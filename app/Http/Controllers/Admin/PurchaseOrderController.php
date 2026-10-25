<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReorderService;
use App\Support\BasisPoints;
use App\Support\CurrentLocation;
use App\Support\Money;
use App\Support\PurchaseOrderPdf;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'all');
        $supplierId = $request->integer('supplier_id') ?: null;

        $orders = PurchaseOrder::query()
            ->forLocation()
            ->with(['supplier:id,name', 'location:id,name,code', 'createdBy:id,name'])
            ->withCount('lines')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier?->name,
                'location_id' => $po->location_id,
                'location_name' => $po->location?->name,
                'location_code' => $po->location?->code,
                'status' => $po->status->value,
                'status_label' => $po->status->label(),
                'can_receive' => $po->status->canReceive(),
                'order_date' => $po->order_date?->toDateString(),
                'expected_date' => $po->expected_date?->toDateString(),
                'lines_count' => $po->lines_count,
                'created_by_name' => $po->createdBy?->name,
                ...($canViewCost ? [
                    'subtotal' => $po->subtotal,
                    'discount_total' => $po->discount_total,
                    'tax_total' => $po->tax_total,
                    'total_amount' => $po->total_amount,
                ] : []),
            ]);

        return Inertia::render('Admin/Purchasing/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'supplier_id' => $supplierId,
            ],
            'statuses' => PurchaseOrderStatus::options(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function create(Request $request, CurrentLocation $currentLocation): Response
    {
        Gate::authorize('manage-purchase-orders');

        $user = $request->user();
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        return Inertia::render('Admin/Purchasing/Form', [
            'order' => null,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name', 'payment_terms_days']),
            'locations' => $accessibleLocations,
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'defaultTaxRateBp' => CompanySetting::current()->tax_rate_bp ?? 0,
            'canCreatePo' => true,
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $user->can('view-cost'),
        ]);
    }

    public function store(Request $request, PurchaseOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $payload = $this->validateOrderPayload($request);

        try {
            $po = $service->save($payload, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('purchasing.orders.show', $po)
            ->with('success', "Purchase order {$po->number} created.");
    }

    public function show(Request $request, PurchaseOrder $order): Response
    {
        $user = $request->user();
        if (! $user->worksAt($order->location_id)) {
            abort(403);
        }

        $canViewCost = $user->can('view-cost');

        $order->load([
            'supplier',
            'location:id,name,code',
            'lines.variant.product',
            'goodsReceipts.receivedBy:id,name',
            'createdBy:id,name',
            'sentBy:id,name',
        ]);

        return Inertia::render('Admin/Purchasing/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier ? [
                    'id' => $order->supplier->id,
                    'name' => $order->supplier->name,
                    'contact_person' => $order->supplier->contact_person,
                    'phone' => $order->supplier->phone,
                    'email' => $order->supplier->email,
                ] : null,
                'location' => $order->location ? [
                    'id' => $order->location->id,
                    'name' => $order->location->name,
                    'code' => $order->location->code,
                ] : null,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'can_edit' => $order->status->canEdit() && $user->can('manage-purchase-orders'),
                'can_send' => in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent], true) && $user->can('manage-purchase-orders'),
                'can_receive' => $order->status->canReceive() && $user->can('receive-stock'),
                'can_close' => $order->status->canClose() && $user->can('manage-purchase-orders'),
                'can_cancel' => $order->status->canCancel()
                    && ! $order->lines->contains(fn ($l) => $l->received_qty > 0)
                    && $user->can('manage-purchase-orders'),
                'order_date' => $order->order_date?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'notes' => $order->notes,
                'cancel_reason' => $order->cancel_reason,
                'created_by_name' => $order->createdBy?->name,
                'sent_by_name' => $order->sentBy?->name,
                'sent_at' => $order->sent_at?->toIso8601String(),
                ...($canViewCost ? [
                    'subtotal' => $order->subtotal,
                    'discount_total' => $order->discount_total,
                    'tax_total' => $order->tax_total,
                    'total_amount' => $order->total_amount,
                ] : []),
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_variant_id' => $l->product_variant_id,
                    'sku' => $l->variant?->sku,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'supplier_sku' => $l->supplier_sku,
                    'ordered_qty' => $l->ordered_qty,
                    'received_qty' => $l->received_qty,
                    'remaining_qty' => $l->remainingQty(),
                    'sales_order_line_id' => $l->sales_order_line_id,
                    'note' => $l->note,
                    ...($canViewCost ? [
                        'unit_cost' => $l->unit_cost,
                        'discount_cents' => $l->discount_cents,
                        'tax_rate_bp' => $l->tax_rate_bp,
                        'tax_cents' => $l->tax_cents,
                        'line_total' => $l->line_total,
                    ] : []),
                ])->values()->all(),
                'goods_receipts' => $order->goodsReceipts->map(fn ($grn) => [
                    'id' => $grn->id,
                    'number' => $grn->number,
                    'supplier_delivery_note' => $grn->supplier_delivery_note,
                    'received_at' => $grn->received_at?->toIso8601String(),
                    'received_by_name' => $grn->receivedBy?->name,
                    ...($canViewCost ? ['total_amount' => $grn->total_amount] : []),
                ])->values()->all(),
            ],
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): Response
    {
        Gate::authorize('manage-purchase-orders');

        if (! $order->status->canEdit()) {
            abort(422, 'Only draft purchase orders can be edited.');
        }

        $user = $request->user();
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $order->load(['lines.variant.product']);

        return Inertia::render('Admin/Purchasing/Form', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier_id' => $order->supplier_id,
                'location_id' => $order->location_id,
                'order_date' => $order->order_date?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_variant_id' => $l->product_variant_id,
                    'sku' => $l->variant?->sku,
                    'product_name' => $l->variant?->product?->name,
                    'variant_name' => $l->variant?->name,
                    'is_serialized' => (bool) $l->variant?->product?->is_serialized,
                    'supplier_sku' => $l->supplier_sku,
                    'ordered_qty' => Quantity::format($l->ordered_qty),
                    'unit_cost' => Money::fromCents($l->unit_cost)->toDecimal(),
                    'discount' => Money::fromCents($l->discount_cents)->toDecimal(),
                    'tax_rate' => BasisPoints::toPercent($l->tax_rate_bp),
                    'sales_order_line_id' => $l->sales_order_line_id,
                    'note' => $l->note,
                ])->values()->all(),
            ],
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name', 'payment_terms_days']),
            'locations' => $accessibleLocations,
            'defaultLocationId' => $order->location_id,
            'defaultTaxRateBp' => CompanySetting::current()->tax_rate_bp ?? 0,
            'canCreatePo' => true,
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $user->can('view-cost'),
        ]);
    }

    public function update(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $payload = $this->validateOrderPayload($request);

        try {
            $po = $service->save($payload, $request->user(), $order);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('purchasing.orders.show', $po)
            ->with('success', "Purchase order {$po->number} updated.");
    }

    public function send(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $emailSupplier = $request->boolean('email_supplier', true);

        try {
            $po = $service->send($order, $request->user(), $emailSupplier);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $msg = $emailSupplier && $po->supplier?->email
            ? "Purchase order {$po->number} marked as sent and queued to {$po->supplier->email}."
            : "Purchase order {$po->number} marked as sent.";

        return back()->with('success', $msg);
    }

    public function close(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $po = $service->close($order, $request->user(), $validated['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Purchase order {$po->number} closed.");
    }

    public function cancel(Request $request, PurchaseOrder $order, PurchaseOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $po = $service->cancel($order, $request->user(), $validated['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Purchase order {$po->number} cancelled.");
    }

    public function pdf(Request $request, PurchaseOrder $order): HttpResponse
    {
        Gate::authorize('view-cost');

        if (! $request->user()->worksAt($order->location_id)) {
            abort(403);
        }

        $bytes = PurchaseOrderPdf::render($order);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->number.'.pdf"',
        ]);
    }

    public function reorder(Request $request, CurrentLocation $currentLocation, ReorderService $reorderService): Response
    {
        $user = $request->user();
        $canViewCost = $user->can('view-cost');
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $selectedLocationId = $request->integer('location_id') ?: $currentLocation->id();
        $locationIds = $selectedLocationId
            ? [$selectedLocationId]
            : $accessibleLocations->pluck('id')->all();

        $groups = $reorderService->suggestions($locationIds);

        if (! $canViewCost) {
            foreach ($groups as &$group) {
                foreach ($group['items'] as &$item) {
                    unset($item['unit_cost']);
                }
            }
        }

        return Inertia::render('Admin/Purchasing/Reorder', [
            'groups' => $groups,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'locations' => $accessibleLocations,
            'selectedLocationId' => $selectedLocationId,
            'canCreatePo' => $user->can('manage-purchase-orders'),
            'canReceive' => $user->can('receive-stock'),
            'canManageBills' => $user->can('manage-bills'),
            'canViewCost' => $canViewCost,
        ]);
    }

    public function createFromReorder(Request $request, ReorderService $reorderService): RedirectResponse
    {
        Gate::authorize('manage-purchase-orders');

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'expected_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.ordered_qty' => ['required', 'string'],
            'lines.*.unit_cost' => ['required', 'string'],
            'lines.*.supplier_sku' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $lines = array_map(fn (array $l) => [
                'product_variant_id' => (int) $l['product_variant_id'],
                'ordered_qty' => Quantity::parse($l['ordered_qty']),
                'unit_cost' => Money::fromDecimal($l['unit_cost'])->cents,
                'supplier_sku' => $l['supplier_sku'] ?? null,
            ], $validated['lines']);

            $po = $reorderService->createPurchaseOrder([
                'supplier_id' => (int) $validated['supplier_id'],
                'location_id' => (int) $validated['location_id'],
                'expected_date' => $validated['expected_date'] ?? null,
                'lines' => $lines,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('purchasing.orders.show', $po)
            ->with('success', "Draft purchase order {$po->number} created from reorder suggestions.");
    }

    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));
        $supplierId = $request->integer('supplier_id') ?: null;
        $canViewCost = $request->user()->can('view-cost');

        if ($q === '') {
            return response()->json(['variants' => []]);
        }

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($pq) => $pq->where('is_active', true))
            ->with(['product:id,name,is_serialized'])
            ->where(function ($sub) use ($q) {
                $sub->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', $q)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('sku')
            ->limit(15)
            ->get();

        $supplierPivots = collect();
        if ($supplierId && $variants->isNotEmpty()) {
            $supplierPivots = DB::table('product_supplier')
                ->where('supplier_id', $supplierId)
                ->whereIn('product_variant_id', $variants->pluck('id')->all())
                ->get()
                ->keyBy('product_variant_id');
        }

        return response()->json([
            'variants' => $variants->map(function (ProductVariant $v) use ($supplierPivots, $canViewCost) {
                $pivot = $supplierPivots->get($v->id);
                $defaultCost = (int) ($pivot?->last_cost ?: ($v->last_cost ?: $v->average_cost));

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'product_name' => $v->product?->name,
                    'variant_name' => $v->name,
                    'is_serialized' => (bool) $v->product?->is_serialized,
                    'supplier_sku' => $pivot->supplier_sku ?? null,
                    ...($canViewCost ? [
                        'unit_cost_cents' => $defaultCost,
                        'unit_cost_decimal' => Money::fromCents($defaultCost)->toDecimal(),
                    ] : []),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrderPayload(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'order_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.supplier_sku' => ['nullable', 'string', 'max:120'],
            'lines.*.ordered_qty' => ['required', 'string'],
            'lines.*.unit_cost' => ['required', 'string'],
            'lines.*.discount' => ['nullable', 'string'],
            'lines.*.tax_rate' => ['nullable', 'string'],
            'lines.*.sales_order_line_id' => ['nullable', 'integer'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->user()->worksAt((int) $validated['location_id'])) {
            abort(403);
        }

        $lines = array_map(function (array $line) {
            $orderedQty = Quantity::parse($line['ordered_qty']);
            $unitCost = Money::fromDecimal($line['unit_cost'])->cents;
            $discountCents = isset($line['discount']) && trim((string) $line['discount']) !== ''
                ? Money::fromDecimal($line['discount'])->cents
                : 0;
            $taxRateBp = isset($line['tax_rate']) && trim((string) $line['tax_rate']) !== ''
                ? BasisPoints::fromPercent($line['tax_rate'])
                : 0;

            return [
                'product_variant_id' => (int) $line['product_variant_id'],
                'supplier_sku' => $line['supplier_sku'] ?? null,
                'ordered_qty' => $orderedQty,
                'unit_cost' => $unitCost,
                'discount_cents' => $discountCents,
                'tax_rate_bp' => $taxRateBp,
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                'note' => $line['note'] ?? null,
            ];
        }, $validated['lines']);

        return [
            'supplier_id' => (int) $validated['supplier_id'],
            'location_id' => (int) $validated['location_id'],
            'order_date' => $validated['order_date'] ?? null,
            'expected_date' => $validated['expected_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'lines' => $lines,
        ];
    }
}
