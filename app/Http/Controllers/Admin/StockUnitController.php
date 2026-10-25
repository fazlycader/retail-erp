<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockUnitController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StockUnitStatus::class)],
            'condition' => ['nullable', Rule::enum(StockUnitCondition::class)],
            'location_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $units = StockUnit::query()
            ->with(['variant.product', 'location:id,name,code'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = trim($search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('serial_number', 'like', "%{$term}%")
                        ->orWhereHas('variant', fn ($vq) => $vq->where('sku', 'like', "%{$term}%")
                            ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$term}%")));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['condition'] ?? null, fn ($q, $cond) => $q->where('condition', $cond))
            ->when($filters['location_id'] ?? null, fn ($q, $locId) => $q->where('location_id', (int) $locId))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (StockUnit $u) => [
                'id' => $u->id,
                'serial_number' => $u->serial_number,
                'product_variant_id' => $u->product_variant_id,
                'sku' => $u->variant?->sku,
                'product_name' => $u->variant?->product?->name,
                'variant_name' => $u->variant?->name,
                'status' => $u->status->value,
                'status_label' => $u->status->label(),
                'condition' => $u->condition->value,
                'condition_label' => $u->condition->label(),
                'location_id' => $u->location_id,
                'location_name' => $u->location?->name,
                'location_code' => $u->location?->code,
                'received_at' => $u->received_at?->toIso8601String(),
                'note' => $u->note,
                ...($canViewCost ? ['unit_cost' => $u->unit_cost] : []),
            ]);

        $selectedUnit = null;
        $unitMovements = [];

        $selectedId = $filters['unit_id'] ?? null;
        if (! $selectedId && ! empty($filters['search']) && $units->total() === 1) {
            $selectedId = $units->items()[0]['id'] ?? null;
        }

        if ($selectedId) {
            $u = StockUnit::query()->with(['variant.product', 'location:id,name,code'])->find((int) $selectedId);
            if ($u) {
                $selectedUnit = [
                    'id' => $u->id,
                    'serial_number' => $u->serial_number,
                    'sku' => $u->variant?->sku,
                    'product_name' => $u->variant?->product?->name,
                    'variant_name' => $u->variant?->name,
                    'status' => $u->status->value,
                    'status_label' => $u->status->label(),
                    'condition' => $u->condition->value,
                    'condition_label' => $u->condition->label(),
                    'location_name' => $u->location?->name,
                    'location_code' => $u->location?->code,
                    'received_at' => $u->received_at?->toIso8601String(),
                    'note' => $u->note,
                    ...($canViewCost ? ['unit_cost' => $u->unit_cost] : []),
                ];

                $unitMovements = StockMovement::query()
                    ->with(['location:id,name,code', 'user:id,name'])
                    ->where('stock_unit_id', $u->id)
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (StockMovement $m) => [
                        'id' => $m->id,
                        'type' => $m->type->value,
                        'type_label' => $m->type->label(),
                        'quantity' => $m->quantity,
                        'location_name' => $m->location?->name,
                        'location_code' => $m->location?->code,
                        'user_name' => $m->user?->name,
                        'business_date' => $m->business_date?->toDateString(),
                        'note' => $m->note,
                        'created_at' => $m->created_at?->toIso8601String(),
                    ])->values()->all();
            }
        }

        return Inertia::render('Admin/Stock/Units', [
            'units' => $units,
            'selectedUnit' => $selectedUnit,
            'unitMovements' => $unitMovements,
            'statuses' => StockUnitStatus::options(),
            'conditions' => StockUnitCondition::options(),
            'locations' => $accessibleLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'condition' => $filters['condition'] ?? '',
                'location_id' => $filters['location_id'] ?? '',
            ],
            'canViewCost' => $canViewCost,
        ]);
    }

    public function updateCondition(Request $request, StockUnit $unit): RedirectResponse
    {
        $validated = $request->validate([
            'condition' => ['required', Rule::enum(StockUnitCondition::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $unit->update([
            'condition' => $validated['condition'],
            'note' => $validated['note'] ?? $unit->note,
        ]);

        return back()->with('success', "Unit {$unit->serial_number} updated.");
    }
}
