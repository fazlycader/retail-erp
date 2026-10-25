<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\GoodsReceiptService;
use App\Support\CurrentLocation;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $search = trim((string) $request->string('search'));
        $supplierId = $request->integer('supplier_id') ?: null;

        $receipts = GoodsReceipt::query()
            ->forLocation()
            ->with([
                'supplier:id,name',
                'location:id,name,code',
                'purchaseOrder:id,number',
                'receivedBy:id,name',
                'approvedBy:id,name',
                'lines.variant.product',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('number', 'like', "%{$search}%")
                        ->orWhere('supplier_delivery_note', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('purchaseOrder', fn ($pq) => $pq->where('number', 'like', "%{$search}%"));
                });
            })
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GoodsReceipt $grn) => [
                'id' => $grn->id,
                'number' => $grn->number,
                'purchase_order_id' => $grn->purchase_order_id,
                'purchase_order_number' => $grn->purchaseOrder?->number,
                'supplier_name' => $grn->supplier?->name,
                'location_name' => $grn->location?->name,
                'location_code' => $grn->location?->code,
                'supplier_delivery_note' => $grn->supplier_delivery_note,
                'received_at' => $grn->received_at?->toIso8601String(),
                'received_by_name' => $grn->receivedBy?->name,
                'approved_by_name' => $grn->approvedBy?->name,
                'notes' => $grn->notes,
                ...($canViewCost ? [
                    'total_amount' => $grn->total_amount,
                ] : []),
                'lines' => $grn->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'sku' => $l->variant?->sku,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'received_qty' => $l->received_qty,
                    'damaged_qty' => $l->damaged_qty,
                    'serials' => $l->serials ?? [],
                    'note' => $l->note,
                    ...($canViewCost ? [
                        'unit_cost' => $l->unit_cost,
                        'line_total' => $l->line_total,
                    ] : []),
                ])->values()->all(),
            ]);

        return Inertia::render('Admin/Purchasing/Receipts', [
            'receipts' => $receipts,
            'filters' => [
                'search' => $search,
                'supplier_id' => $supplierId,
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function create(Request $request, CurrentLocation $currentLocation): Response
    {
        Gate::authorize('receive-stock');

        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $poId = $request->integer('purchase_order_id') ?: null;
        $selectedOrder = null;

        if ($poId) {
            $po = PurchaseOrder::query()
                ->with(['supplier:id,name', 'location:id,name,code', 'lines.variant.product'])
                ->findOrFail($poId);

            if (! $user->worksAt($po->location_id)) {
                abort(403);
            }

            $selectedOrder = [
                'id' => $po->id,
                'number' => $po->number,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier?->name,
                'location_id' => $po->location_id,
                'location_name' => $po->location?->name,
                'location_code' => $po->location?->code,
                'lines' => $po->lines->map(fn ($l) => [
                    'purchase_order_line_id' => $l->id,
                    'product_variant_id' => $l->product_variant_id,
                    'sku' => $l->variant?->sku,
                    'barcode' => $l->variant?->barcode,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'is_serialized' => (bool) $l->variant?->product?->is_serialized,
                    'ordered_qty' => $l->ordered_qty,
                    'already_received_qty' => $l->received_qty,
                    'remaining_qty' => $l->remainingQty(),
                    'received_qty' => Quantity::format($l->remainingQty()),
                    'damaged_qty' => '0',
                    ...($canViewCost ? [
                        'unit_cost' => Money::fromCents($l->unit_cost)->toDecimal(),
                    ] : []),
                ])->values()->all(),
            ];
        }

        $openOrders = PurchaseOrder::query()
            ->forLocation()
            ->whereIn('status', [
                PurchaseOrderStatus::Sent->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->with(['supplier:id,name', 'location:id,name,code'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplier_name' => $po->supplier?->name,
                'location_code' => $po->location?->code,
                'status_label' => $po->status->label(),
            ])->values()->all();

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Purchasing/Receive', [
            'selectedOrder' => $selectedOrder,
            'openOrders' => $openOrders,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'locations' => $accessibleLocations,
            'defaultLocationId' => $selectedOrder['location_id'] ?? $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'approversByLocation' => $approversByLocation,
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => true,
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function store(Request $request, GoodsReceiptService $service): RedirectResponse
    {
        Gate::authorize('receive-stock');

        $validated = $request->validate([
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'supplier_delivery_note' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.received_qty' => ['required', 'string'],
            'lines.*.damaged_qty' => ['nullable', 'string'],
            'lines.*.unit_cost' => ['nullable', 'string'],
            'lines.*.serials' => ['nullable'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $po = ! empty($validated['purchase_order_id'])
            ? PurchaseOrder::query()->with('lines')->findOrFail((int) $validated['purchase_order_id'])
            : null;

        $locationId = (int) ($validated['location_id'] ?? $po?->location_id ?? 0);
        if (! $locationId || ! $request->user()->worksAt($locationId)) {
            abort(403);
        }

        $poLinesById = $po ? $po->lines->keyBy('id') : collect();
        $variantsById = ProductVariant::query()
            ->withTrashed()
            ->whereIn('id', array_column($validated['lines'], 'product_variant_id'))
            ->get()
            ->keyBy('id');

        try {
            $lines = [];
            foreach ($validated['lines'] as $line) {
                $receivedQty = Quantity::parse($line['received_qty']);
                if ($receivedQty <= 0) {
                    continue;
                }

                $damagedQty = isset($line['damaged_qty']) && trim((string) $line['damaged_qty']) !== ''
                    ? Quantity::parse($line['damaged_qty'])
                    : 0;

                $poLine = ! empty($line['purchase_order_line_id'])
                    ? $poLinesById->get((int) $line['purchase_order_line_id'])
                    : ($po ? $po->lines->firstWhere('product_variant_id', (int) $line['product_variant_id']) : null);

                $variant = $variantsById->get((int) $line['product_variant_id']);

                $unitCost = isset($line['unit_cost']) && trim((string) $line['unit_cost']) !== ''
                    ? Money::fromDecimal($line['unit_cost'])->cents
                    : ($poLine ? $poLine->unit_cost : ($variant?->last_cost ?: ($variant?->average_cost ?? 0)));

                $lines[] = [
                    'purchase_order_line_id' => $poLine?->id,
                    'product_variant_id' => (int) $line['product_variant_id'],
                    'received_qty' => $receivedQty,
                    'damaged_qty' => $damagedQty,
                    'unit_cost' => $unitCost,
                    'serials' => $line['serials'] ?? null,
                    'note' => $line['note'] ?? null,
                ];
            }

            $grn = $service->receive([
                'purchase_order_id' => $po?->id,
                'supplier_id' => $validated['supplier_id'] ?? $po?->supplier_id,
                'location_id' => $locationId,
                'supplier_delivery_note' => $validated['supplier_delivery_note'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'approver_id' => $validated['approver_id'] ?? null,
                'pin' => $validated['pin'] ?? null,
                'lines' => $lines,
            ], $request->user());
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        if ($po) {
            return to_route('purchasing.orders.show', $po)
                ->with('success', "Goods receipt {$grn->number} recorded against {$po->number}.");
        }

        return to_route('purchasing.receipts.index')
            ->with('success', "Goods receipt {$grn->number} recorded.");
    }
}
