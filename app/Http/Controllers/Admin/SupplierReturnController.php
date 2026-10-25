<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\Purchasing\SupplierReturnService;
use App\Support\CurrentLocation;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SupplierReturnController extends Controller
{
    public function index(Request $request, CurrentLocation $currentLocation): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $returns = SupplierReturn::query()
            ->forLocation()
            ->with([
                'supplier:id,name',
                'location:id,name,code',
                'goodsReceipt:id,number',
                'createdBy:id,name',
                'lines.variant.product',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierReturn $ret) => [
                'id' => $ret->id,
                'number' => $ret->number,
                'supplier_name' => $ret->supplier?->name,
                'location_name' => $ret->location?->name,
                'location_code' => $ret->location?->code,
                'goods_receipt_number' => $ret->goodsReceipt?->number,
                'returned_at' => $ret->returned_at?->toDateString(),
                'reason' => $ret->reason,
                'notes' => $ret->notes,
                'created_by_name' => $ret->createdBy?->name,
                ...($canViewCost ? [
                    'credit_expected' => $ret->credit_expected,
                ] : []),
                'lines' => $ret->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'sku' => $l->variant?->sku,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'quantity' => $l->quantity,
                    'reason' => $l->reason,
                    ...($canViewCost ? [
                        'unit_cost' => $l->unit_cost,
                        'line_total' => $l->line_total,
                    ] : []),
                ])->values()->all(),
            ]);

        return Inertia::render('Admin/Purchasing/Returns', [
            'returns' => $returns,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'recentReceipts' => GoodsReceipt::query()
                ->forLocation()
                ->latest('id')
                ->limit(40)
                ->get(['id', 'number', 'supplier_id']),
            'locations' => $accessibleLocations,
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function store(Request $request, SupplierReturnService $service): RedirectResponse
    {
        Gate::authorize('receive-stock');

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'returned_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_cost' => ['nullable', 'string'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->user()->worksAt((int) $validated['location_id'])) {
            abort(403);
        }

        try {
            $lines = array_map(fn (array $l) => [
                'product_variant_id' => (int) $l['product_variant_id'],
                'quantity' => Quantity::parse($l['quantity']),
                'unit_cost' => isset($l['unit_cost']) && trim((string) $l['unit_cost']) !== ''
                    ? Money::fromDecimal($l['unit_cost'])->cents
                    : null,
                'serial_number' => $l['serial_number'] ?? null,
                'reason' => $l['reason'] ?? null,
            ], $validated['lines']);

            $ret = $service->create([
                'supplier_id' => (int) $validated['supplier_id'],
                'location_id' => (int) $validated['location_id'],
                'goods_receipt_id' => $validated['goods_receipt_id'] ?? null,
                'returned_at' => $validated['returned_at'] ?? null,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'lines' => $lines,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Supplier return {$ret->number} recorded.");
    }
}
