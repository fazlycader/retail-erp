<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdjustmentReason;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\StockAdjustment;
use App\Services\Inventory\AdjustmentService;
use App\Support\CurrentLocation;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StockAdjustmentController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $adjustments = StockAdjustment::query()
            ->forLocation()
            ->with(['location:id,name,code', 'creator:id,name', 'approver:id,name', 'lines.variant.product'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockAdjustment $adj) => [
                'id' => $adj->id,
                'number' => $adj->number,
                'location_name' => $adj->location?->name,
                'location_code' => $adj->location?->code,
                'reason' => $adj->reason->value,
                'reason_label' => $adj->reason->label(),
                'note' => $adj->note,
                'created_by_name' => $adj->creator?->name,
                'approved_by_name' => $adj->approver?->name,
                'business_date' => $adj->business_date?->toDateString(),
                'created_at' => $adj->created_at?->toIso8601String(),
                ...($canViewCost ? ['total_value' => $adj->total_value] : []),
                'lines' => $adj->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'sku' => $l->variant?->sku,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'quantity' => $l->quantity,
                    'note' => $l->note,
                    ...($canViewCost ? [
                        'unit_cost' => $l->unit_cost,
                        'line_value' => $l->line_value,
                    ] : []),
                ])->values()->all(),
            ]);

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Stock/Adjustments', [
            'adjustments' => $adjustments,
            'reasons' => AdjustmentReason::options(),
            'locations' => $accessibleLocations->map(fn (Location $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
            ])->values()->all(),
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'approvalThresholdCents' => CompanySetting::current()->adjustment_approval_value,
            'approversByLocation' => $approversByLocation,
            'canViewCost' => $canViewCost,
        ]);
    }

    public function store(Request $request, AdjustmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_cost' => ['nullable', 'string'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->user()->worksAt((int) $validated['location_id'])) {
            abort(403);
        }

        if ($validated['reason'] === AdjustmentReason::Other->value && blank($validated['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Please enter a note explaining the adjustment.']);
        }

        try {
            $lines = array_map(function (array $line) {
                $qtyMilli = Quantity::parse($line['quantity']);
                $unitCost = isset($line['unit_cost']) && trim((string) $line['unit_cost']) !== ''
                    ? Money::fromDecimal($line['unit_cost'])->cents
                    : null;

                return [
                    'product_variant_id' => (int) $line['product_variant_id'],
                    'quantity' => $qtyMilli,
                    'unit_cost' => $unitCost,
                    'serial_number' => $line['serial_number'] ?? null,
                    'note' => $line['note'] ?? null,
                ];
            }, $validated['lines']);

            $adjustment = $service->create([
                'location_id' => (int) $validated['location_id'],
                'reason' => $validated['reason'],
                'note' => $validated['note'] ?? null,
                'approver_id' => $validated['approver_id'] ?? null,
                'pin' => $validated['pin'] ?? null,
                'lines' => $lines,
            ], $request->user());
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()->route('stock.adjustments.index')
            ->with('success', "Stock adjustment {$adjustment->number} recorded.");
    }
}
