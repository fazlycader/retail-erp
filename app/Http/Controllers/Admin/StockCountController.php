<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Services\Catalog\CategoryTree;
use App\Services\Inventory\StockCountService;
use App\Support\CurrentLocation;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockCountController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation, CategoryTree $tree): Response
    {
        $user = $request->user();
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $counts = StockCount::query()
            ->forLocation()
            ->with(['location:id,name,code', 'category:id,name', 'starter:id,name', 'poster:id,name'])
            ->withCount('lines')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockCount $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'location_name' => $c->location?->name,
                'location_code' => $c->location?->code,
                'scope' => $c->scope->value,
                'scope_label' => $c->scope->label(),
                'category_name' => $c->category?->name,
                'blind' => $c->blind,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'lines_count' => $c->lines_count,
                'started_by_name' => $c->starter?->name,
                'posted_by_name' => $c->poster?->name,
                'business_date' => $c->business_date?->toDateString(),
                'started_at' => $c->started_at?->toIso8601String(),
                'posted_at' => $c->posted_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Stock/Counts/Index', [
            'counts' => $counts,
            'locations' => $accessibleLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'categories' => $tree->options(),
            'scopes' => StockCountScope::options(),
        ]);
    }

    public function store(Request $request, StockCountService $service): RedirectResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'scope' => ['required', Rule::enum(StockCountScope::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['integer', 'exists:product_variants,id'],
            'blind' => ['boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $request->user()->worksAt((int) $validated['location_id'])) {
            abort(403);
        }

        try {
            $count = $service->start($validated, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scope' => $e->getMessage()]);
        }

        return redirect()->route('stock.counts.show', $count)
            ->with('success', "Stock count {$count->number} started.");
    }

    public function show(Request $request, StockCount $count): Response
    {
        $user = $request->user();
        if (! $user->worksAt($count->location_id)) {
            abort(403);
        }

        $canViewCost = $user->can('view-cost');
        $canPost = $user->role->canOverride();

        // In blind mode while still counting, hide system_qty and variance from counters.
        $hideSystemQty = $count->blind && in_array($count->status, [StockCountStatus::Open, StockCountStatus::Counting], true);

        $count->load(['location:id,name,code', 'category:id,name', 'starter:id,name', 'reviewer:id,name', 'poster:id,name', 'lines.variant.product', 'lines.counter:id,name']);

        $totalVarianceValue = 0;
        $countedCount = 0;

        $lines = $count->lines->map(function (StockCountLine $line) use ($hideSystemQty, $canViewCost, &$totalVarianceValue, &$countedCount) {
            if ($line->counted_qty !== null) {
                $countedCount++;
                $totalVarianceValue += $line->variance_value;
            }

            return [
                'id' => $line->id,
                'product_variant_id' => $line->product_variant_id,
                'sku' => $line->variant?->sku,
                'barcode' => $line->variant?->barcode,
                'product_name' => $line->variant?->product?->name,
                'variant_name' => $line->variant?->name,
                'counted_qty' => $line->counted_qty,
                'counted_decimal' => $line->counted_qty !== null ? Quantity::toDecimal($line->counted_qty) : '',
                'counted_by_name' => $line->counter?->name,
                'note' => $line->note,
                ...(! $hideSystemQty ? [
                    'system_qty' => $line->system_qty,
                    'variance_qty' => $line->variance_qty,
                ] : []),
                ...(! $hideSystemQty && $canViewCost ? [
                    'unit_cost' => $line->unit_cost,
                    'variance_value' => $line->variance_value,
                ] : []),
            ];
        })->values()->all();

        return Inertia::render('Admin/Stock/Counts/Show', [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'location_id' => $count->location_id,
                'location_name' => $count->location?->name,
                'location_code' => $count->location?->code,
                'scope' => $count->scope->value,
                'scope_label' => $count->scope->label(),
                'category_name' => $count->category?->name,
                'blind' => $count->blind,
                'hide_system_qty' => $hideSystemQty,
                'status' => $count->status->value,
                'status_label' => $count->status->label(),
                'note' => $count->note,
                'started_by_name' => $count->starter?->name,
                'reviewed_by_name' => $count->reviewer?->name,
                'posted_by_name' => $count->poster?->name,
                'business_date' => $count->business_date?->toDateString(),
                'started_at' => $count->started_at?->toIso8601String(),
                'posted_at' => $count->posted_at?->toIso8601String(),
                'total_lines' => count($lines),
                'counted_lines' => $countedCount,
                ...(! $hideSystemQty && $canViewCost ? [
                    'total_variance_value' => $totalVarianceValue,
                ] : []),
            ],
            'lines' => $lines,
            'canViewCost' => $canViewCost,
            'canPost' => $canPost,
        ]);
    }

    public function scan(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($count->location_id)) {
            abort(403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'quantity' => ['nullable', 'string'],
        ]);

        try {
            $step = filled($validated['quantity'] ?? null) ? Quantity::parse($validated['quantity']) : Quantity::SCALE;
            $line = $service->scan($count, $validated['code'], $request->user(), $step);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('success', "Counted {$line->variant->sku}: ".Quantity::format($line->counted_qty));
    }

    public function record(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($count->location_id)) {
            abort(403);
        }

        $validated = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['nullable', 'string'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payload = [];
            foreach ($validated['lines'] as $item) {
                $raw = trim((string) ($item['counted_qty'] ?? ''));
                $payload[(int) $item['id']] = [
                    'counted_qty' => $raw === '' ? null : Quantity::parse($raw),
                    'note' => $item['note'] ?? null,
                ];
            }

            $service->recordCounts($count, $payload, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', 'Counts saved.');
    }

    public function review(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($count->location_id)) {
            abort(403);
        }

        try {
            $service->submitForReview($count, $request->user(), $request->boolean('zero_uncounted'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Stock count {$count->number} moved to variance review.");
    }

    public function post(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        if (! $request->user()->worksAt($count->location_id) || ! $request->user()->role->canOverride()) {
            abort(403);
        }

        try {
            $service->post($count, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Stock count {$count->number} posted to stock levels.");
    }
}
