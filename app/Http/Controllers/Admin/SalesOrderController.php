<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FulfilmentType;
use App\Enums\ProductType;
use App\Enums\ReturnCondition;
use App\Enums\ReturnOutcome;
use App\Enums\Role;
use App\Enums\SalesChannel;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Enums\SalesOrderStatus;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\InsufficientStock;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\StockLevel;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\ReturnService;
use App\Services\Sales\SalesOrderService;
use App\Support\BasisPoints;
use App\Support\CurrentLocation;
use App\Support\DeliveryNotePdf;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\SalesDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SalesOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manage-sales');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'all');
        $channel = (string) $request->string('channel', 'all');
        $locationFilter = $request->integer('location_id') ?: null;

        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);

        $orders = SalesOrder::query()
            ->forLocation()
            ->with(['customer:id,name,phone', 'location:id,name,code', 'salesperson:id,name'])
            ->withCount('lines')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('number', 'like', "%{$search}%")
                        ->orWhere('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($channel !== 'all', fn ($q) => $q->where('channel', $channel))
            ->when($locationFilter, fn ($q) => $q->where('location_id', $locationFilter))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SalesOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'invoice_number' => $o->invoice_number,
                'customer_id' => $o->customer_id,
                'customer_name' => $o->customer?->name,
                'customer_phone' => $o->customer?->displayPhone() ?? $o->customer?->phone,
                'location_id' => $o->location_id,
                'location_name' => $o->location?->name,
                'location_code' => $o->location?->code,
                'salesperson_name' => $o->salesperson?->name,
                'channel' => $o->channel->value,
                'channel_label' => $o->channel->label(),
                'kind' => $o->kind->value,
                'kind_label' => $o->kind->label(),
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'payment_status' => $o->payment_status->value,
                'payment_status_label' => $o->payment_status->label(),
                'advance_pending' => $o->advance_pending,
                'fulfilment_type' => $o->fulfilment_type->value,
                'fulfilment_type_label' => $o->fulfilment_type->label(),
                'lines_count' => $o->lines_count,
                'grand_total' => $o->grand_total,
                'paid_amount' => $o->paid_amount,
                'balance_due' => $o->balance_due,
                'business_date' => $o->business_date?->toDateString(),
                'created_at' => $o->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Sales/Index', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'channel' => $channel,
                'location_id' => $locationFilter,
            ],
            'statuses' => SalesOrderStatus::options(),
            'channels' => SalesChannel::options(),
            'locations' => $accessibleLocations,
            'canManage' => $canManage,
        ]);
    }

    public function create(Request $request, CurrentLocation $currentLocation, DiscountApprovalService $discountService): Response
    {
        Gate::authorize('manage-sales');

        $user = $request->user();
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $allActiveLocations = Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);
        $settings = CompanySetting::current();

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Sales/Form', [
            'order' => null,
            'locations' => $accessibleLocations,
            'fulfilLocations' => $allActiveLocations,
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'channels' => SalesChannel::options(),
            'kinds' => SalesOrderKind::options(),
            'fulfilmentTypes' => FulfilmentType::options(),
            'lineSources' => SalesLineSource::options(),
            'pricingConfig' => [
                'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
                'prices_include_tax' => (bool) $settings->prices_include_vat,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
                'max_discount_bp' => $discountService->maxDiscountBpFor($user),
                'require_advance_bp' => (int) $settings->require_advance_bp,
            ],
            'approversByLocation' => $approversByLocation,
            'canOverride' => $user->can('override'),
        ]);
    }

    public function store(Request $request, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $payload = $this->validateOrderPayload($request);
        $confirmNow = $request->boolean('confirm_now');

        try {
            $order = $service->save($payload, $request->user());
            if ($confirmNow) {
                $order = $service->confirm($order, $request->user());
            }
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $msg = $confirmNow
            ? "Sales order {$order->number} created and confirmed ({$order->invoice_number})."
            : "Sales order {$order->number} saved as draft.";

        return to_route('sales.orders.show', $order)
            ->with('success', $msg);
    }

    public function show(Request $request, SalesOrder $order, ReturnService $returns): Response
    {
        $user = $request->user();
        if (! $user->worksAt($order->location_id)) {
            abort(403);
        }

        $canViewCost = $user->can('view-cost');

        $order->load([
            'customer',
            'quotation:id,number',
            'location:id,name,code',
            'salesperson:id,name',
            'confirmedBy:id,name',
            'cancelledBy:id,name',
            'lines.variant.product',
            'lines.customSpec.baseProduct:id,name',
            'lines.productionJob.stage:id,name',
            'lines.fulfilLocation:id,name,code',
            'lines.purchaseOrderLine.purchaseOrder:id,number,status',
            'statusHistory.user:id,name',
            'installmentAgreement:id,sales_order_id,number,status',
        ]);

        return Inertia::render('Admin/Sales/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'invoice_number' => $order->invoice_number,
                'woo_order_number' => $order->woo_order_number,
                'woo_status' => $order->woo_status,
                'needs_attention' => $order->needs_attention,
                'attention_reason' => $order->attention_reason,
                'quotation' => $order->quotation ? [
                    'id' => $order->quotation->id,
                    'number' => $order->quotation->number,
                ] : null,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'phone_display' => $order->customer->displayPhone(),
                    'email' => $order->customer->email,
                    'nic' => $order->customer->nic,
                ] : null,
                'location' => $order->location ? [
                    'id' => $order->location->id,
                    'name' => $order->location->name,
                    'code' => $order->location->code,
                ] : null,
                'salesperson_name' => $order->salesperson?->name,
                'confirmed_by_name' => $order->confirmedBy?->name,
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                'cancelled_by_name' => $order->cancelledBy?->name,
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'cancel_reason' => $order->cancel_reason,
                'channel' => $order->channel->value,
                'channel_label' => $order->channel->label(),
                'kind' => $order->kind->value,
                'kind_label' => $order->kind->label(),
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'payment_status' => $order->payment_status->value,
                'payment_status_label' => $order->payment_status->label(),
                'advance_required_cents' => $order->advance_required_cents,
                'advance_pending' => $order->advance_pending,
                'can_edit' => $order->status->canEdit() && $user->can('manage-sales'),
                'can_confirm' => $order->status->canConfirm() && $user->can('manage-sales'),
                'can_fulfil' => $order->status->canFulfil() && $user->can('manage-sales'),
                'can_cancel' => $order->status->canCancel()
                    && ! $order->lines->contains(fn ($l) => $l->fulfilled_qty > 0)
                    && $user->can('manage-sales'),
                'prices_include_tax' => $order->prices_include_tax,
                'tax_rate_bp' => $order->tax_rate_bp,
                'items_gross_total' => $order->items_gross_total,
                'line_discount_total' => $order->line_discount_total,
                'subtotal' => $order->subtotal,
                'order_discount_type' => $order->order_discount_type,
                'order_discount_value' => $order->order_discount_value,
                'order_discount_cents' => $order->order_discount_cents,
                'tax_total' => $order->tax_total,
                'delivery_charge' => $order->delivery_charge,
                'rounding_cents' => $order->rounding_cents,
                'grand_total' => $order->grand_total,
                'paid_amount' => $order->paid_amount,
                'balance_due' => $order->balance_due,
                'returned_total' => $order->returned_total,
                'refunded_total' => $order->refunded_total,
                'can_take_payment' => $order->invoice_number !== null && $order->status !== SalesOrderStatus::Cancelled && $order->balance_due > 0 && $user->can('manage-sales'),
                'can_create_installment' => $order->invoice_number !== null
                    && $order->status !== SalesOrderStatus::Cancelled
                    && $order->balance_due > 0
                    && filled($order->customer?->nic)
                    && ! $order->installmentAgreement
                    && in_array($user->role, [Role::Admin, Role::Manager, Role::Cashier, Role::Accountant], true),
                'can_return' => $order->invoice_number !== null && $order->status !== SalesOrderStatus::Cancelled && $user->can('manage-sales'),
                'fulfilment_type' => $order->fulfilment_type->value,
                'fulfilment_type_label' => $order->fulfilment_type->label(),
                'delivery_address' => $order->delivery_address,
                'requested_delivery_date' => $order->requested_delivery_date?->toDateString(),
                'notes' => $order->notes,
                'business_date' => $order->business_date?->toDateString(),
                'created_at' => $order->created_at?->toIso8601String(),
                'lines' => $order->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'product_variant_id' => $l->product_variant_id,
                    'is_custom' => $l->is_custom,
                    'is_service' => $l->is_service,
                    'sku_snapshot' => $l->sku_snapshot,
                    'name_snapshot' => $l->name_snapshot,
                    'attributes_snapshot' => $l->attributes_snapshot,
                    'custom_spec' => $l->custom_spec,
                    'custom_spec_id' => $l->custom_spec_id,
                    'custom_spec_detail' => $l->customSpec?->toPayload(),
                    'production_job' => $l->productionJob ? [
                        'id' => $l->productionJob->id,
                        'number' => $l->productionJob->number,
                        'status' => $l->productionJob->status->value,
                        'status_label' => $l->productionJob->status->label(),
                        'stage' => $l->productionJob->stage?->name,
                        'source' => $l->productionJob->source->value,
                        'supplier_status_label' => $l->productionJob->supplier_status?->label(),
                        'due_date' => $l->productionJob->due_date?->toDateString(),
                        'overdue' => $l->productionJob->isOverdue(),
                    ] : null,
                    'source' => $l->source->value,
                    'source_label' => $l->source->label(),
                    'fulfil_location_name' => $l->fulfilLocation?->name,
                    'fulfil_location_code' => $l->fulfilLocation?->code,
                    'quantity' => $l->quantity,
                    'reserved_qty' => $l->reserved_qty,
                    'fulfilled_qty' => $l->fulfilled_qty,
                    'remaining_qty' => $l->remainingQty(),
                    'remaining_qty_decimal' => Quantity::format($l->remainingQty()),
                    'returned_qty' => $l->returned_qty,
                    'returnable_qty' => Quantity::format(max(0, ($l->is_service ? $l->quantity : $l->fulfilled_qty) - $l->returned_qty)),
                    'can_remove' => $order->status->canCancel() && $order->status !== SalesOrderStatus::Draft
                        && $l->fulfilled_qty === 0 && ! $l->purchase_order_line_id && $order->paid_amount === 0 && $order->lines->count() > 1,
                    'unit_price' => $l->unit_price,
                    'discount_type' => $l->discount_type,
                    'discount_value' => $l->discount_value,
                    'discount_cents' => $l->discount_cents,
                    'line_subtotal' => $l->line_subtotal,
                    'order_discount_share' => $l->order_discount_share,
                    'tax_cents' => $l->tax_cents,
                    'line_total' => $l->line_total,
                    'note' => $l->note,
                    'linked_po' => $l->purchaseOrderLine?->purchaseOrder ? [
                        'id' => $l->purchaseOrderLine->purchaseOrder->id,
                        'number' => $l->purchaseOrderLine->purchaseOrder->number,
                    ] : null,
                    ...($canViewCost ? [
                        'unit_cost_snapshot' => $l->unit_cost_snapshot,
                    ] : []),
                ])->values()->all(),
                'status_history' => $order->statusHistory->map(fn ($h) => [
                    'id' => $h->id,
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'user_name' => $h->user?->name,
                    'note' => $h->note,
                    'created_at' => $h->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'approvers' => ManagerApproval::approvers($order->location_id),
            'canManage' => $user->can('manage-sales'),
            'canOverride' => $user->can('override'),
            'canViewCost' => $canViewCost,
            'canManageCheques' => $user->can('manage-cheques'),
            ...$this->moneyProps($order, $returns),
        ]);
    }

    /**
     * Payments, returns, credit notes and printing for the order page.
     *
     * @return array<string, mixed>
     */
    private function moneyProps(SalesOrder $order, ReturnService $returns): array
    {
        $order->load([
            'payments' => fn ($q) => $q->with(['method:id,name,code', 'receivedBy:id,name', 'location:id,code', 'customer:id,name', 'salesOrder:id,number,invoice_number']),
            'returns' => fn ($q) => $q->with(['lines.orderLine:id,name_snapshot', 'lines.restockLocation:id,code', 'createdBy:id,name', 'approvedBy:id,name', 'creditNote:id,sales_return_id,number', 'refund:id,sales_return_id,number']),
            'creditNotes',
        ]);
        $confirmed = $order->invoice_number !== null;

        return [
            'payments' => $order->payments->map(fn (Payment $p) => PaymentController::row($p))->values(),
            'returns' => $order->returns->map(fn (SalesReturn $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'date' => $r->business_date?->toDateString(),
                'outcome_label' => $r->outcome->label(),
                'total' => $r->total,
                'settled_amount' => $r->settled_amount,
                'reason' => $r->reason,
                'created_by' => $r->createdBy?->name,
                'approved_by' => $r->approvedBy?->name,
                'credit_note_number' => $r->creditNote?->number,
                'refund_number' => $r->refund?->number,
                'lines' => $r->lines->map(fn ($l) => [
                    'name' => $l->orderLine?->name_snapshot,
                    'quantity' => Quantity::format($l->quantity),
                    'condition' => $l->condition->label(),
                    'restock' => $l->restockLocation?->code,
                    'amount' => $l->amount,
                ])->values(),
            ])->values(),
            'creditNotes' => $order->creditNotes->map(fn (CreditNote $n) => [
                'id' => $n->id,
                'number' => $n->number,
                'amount' => $n->amount,
                'remaining' => $n->remaining,
                'status_label' => $n->status->label(),
                'pdf_url' => route('sales.credit-notes.pdf', $n),
            ])->values(),
            'paymentMethods' => PaymentMethod::query()->active()->get()->map->toOption()->values(),
            'returnConfig' => [
                'return_days' => (int) (CompanySetting::current()->return_days ?? 7),
                'is_late' => $confirmed && $returns->isLate($order),
                'conditions' => ReturnCondition::options(),
                'outcomes' => ReturnOutcome::options(),
                'locations' => Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']),
            ],
            'documents' => $confirmed ? [
                'title' => SalesDocuments::invoiceTitle(),
                'invoice_url' => route('sales.orders.invoice', $order),
                'receipt_url' => route('pos.receipt', $order),
                'share' => PosController::shareLinks($order),
            ] : null,
        ];
    }

    public function invoicePdf(Request $request, SalesOrder $order): HttpResponse
    {
        abort_unless($request->user()->worksAt($order->location_id), 403);
        abort_if($order->invoice_number === null, 404);
        SalesOrder::query()->whereKey($order->id)->whereNull('invoice_printed_at')->update(['invoice_printed_at' => now()]);

        return response(SalesDocuments::invoicePdf($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$order->invoice_number.'.pdf"',
        ]);
    }

    public function removeLine(Request $request, SalesOrder $order, SalesOrderLine $line, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');
        abort_unless($request->user()->worksAt($order->location_id), 403);

        try {
            $service->removeLine($order, $line, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Removed {$line->name_snapshot} from {$order->number}.");
    }

    public function edit(Request $request, SalesOrder $order, DiscountApprovalService $discountService): Response
    {
        Gate::authorize('manage-sales');

        if (! $order->status->canEdit()) {
            abort(422, 'Only draft sales orders can be edited.');
        }

        $user = $request->user();
        if (! $user->worksAt($order->location_id)) {
            abort(403);
        }

        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $allActiveLocations = Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);
        $settings = CompanySetting::current();
        $order->load(['customer', 'lines.variant.product', 'lines.customSpec.baseProduct:id,name']);

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Sales/Form', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'customer_id' => $order->customer_id,
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                    'phone_display' => $order->customer->displayPhone(),
                    'addresses' => $order->customer->addresses ?? [],
                    'default_address' => $order->customer->defaultAddress(),
                ] : null,
                'location_id' => $order->location_id,
                'channel' => $order->channel->value,
                'kind' => $order->kind->value,
                'fulfilment_type' => $order->fulfilment_type->value,
                'delivery_address' => $order->delivery_address,
                'requested_delivery_date' => $order->requested_delivery_date?->toDateString(),
                'order_discount_type' => $order->order_discount_type,
                'order_discount_value' => $order->order_discount_type === 'percent'
                    ? BasisPoints::toPercent($order->order_discount_value)
                    : (in_array($order->order_discount_type, ['fixed', 'amount'], true) ? Money::fromCents($order->order_discount_value)->toDecimal() : ''),
                'delivery_charge' => Money::fromCents($order->delivery_charge)->toDecimal(),
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn ($l) => [
                    'product_variant_id' => $l->product_variant_id,
                    'is_custom' => $l->is_custom,
                    'is_service' => $l->is_service,
                    'sku_snapshot' => $l->sku_snapshot,
                    'name_snapshot' => $l->name_snapshot,
                    'custom_spec' => $l->custom_spec,
                    'custom_spec_id' => $l->custom_spec_id,
                    'custom_spec_detail' => $l->customSpec?->toPayload(),
                    'source' => $l->source->value,
                    'fulfil_location_id' => $l->fulfil_location_id ?: $order->location_id,
                    'quantity' => Quantity::format($l->quantity),
                    'unit_price' => Money::fromCents($l->unit_price)->toDecimal(),
                    'min_price_cents' => $l->min_price_snapshot,
                    'discount_type' => $l->discount_type === 'amount' ? 'fixed' : ($l->discount_type ?? ''),
                    'discount_value' => $l->discount_type === 'percent'
                        ? BasisPoints::toPercent($l->discount_value)
                        : (in_array($l->discount_type, ['fixed', 'amount'], true) ? Money::fromCents($l->discount_value)->toDecimal() : ''),
                    'note' => $l->note,
                ])->values()->all(),
            ],
            'locations' => $accessibleLocations,
            'fulfilLocations' => $allActiveLocations,
            'defaultLocationId' => $order->location_id,
            'channels' => SalesChannel::options(),
            'kinds' => SalesOrderKind::options(),
            'fulfilmentTypes' => FulfilmentType::options(),
            'lineSources' => SalesLineSource::options(),
            'pricingConfig' => [
                'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
                'prices_include_tax' => (bool) $settings->prices_include_vat,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
                'max_discount_bp' => $discountService->maxDiscountBpFor($user),
                'require_advance_bp' => (int) $settings->require_advance_bp,
            ],
            'approversByLocation' => $approversByLocation,
            'canOverride' => $user->can('override'),
        ]);
    }

    public function update(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $payload = $this->validateOrderPayload($request);

        try {
            $order = $service->save($payload, $request->user(), $order);
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('sales.orders.show', $order)
            ->with('success', "Sales order {$order->number} updated.");
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        try {
            $order = $service->confirm($order, $request->user());
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Sales order {$order->number} confirmed (Invoice {$order->invoice_number}).");
    }

    public function fulfil(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $validated = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $quantities = [];
            foreach ($validated['lines'] as $row) {
                $qtyMilli = trim((string) $row['quantity']) !== ''
                    ? Quantity::parse($row['quantity'])
                    : 0;
                $quantities[(int) $row['line_id']] = $qtyMilli;
            }

            $order = $service->fulfil($order, $quantities, $request->user(), $validated['note'] ?? null);
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Fulfilment recorded for {$order->number} ({$order->status->label()}).");
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $order = $service->cancel(
                $order,
                $request->user(),
                $validated['reason'] ?? null,
                isset($validated['approver_id']) ? (int) $validated['approver_id'] : null,
                $validated['pin'] ?? null,
            );
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Sales order {$order->number} cancelled.");
    }

    public function deliveryNotePdf(Request $request, SalesOrder $order): HttpResponse
    {
        if (! $request->user()->worksAt($order->location_id)) {
            abort(403);
        }

        $bytes = DeliveryNotePdf::render($order);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="DN-'.$order->number.'.pdf"',
        ]);
    }

    public function catalogLookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));
        $locationId = $request->integer('location_id') ?: null;

        if ($q === '') {
            return response()->json(['variants' => []]);
        }

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($pq) => $pq->where('is_active', true))
            ->with(['product:id,name,type,is_serialized'])
            ->where(function ($sub) use ($q) {
                $sub->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', $q)
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('sku')
            ->limit(15)
            ->get();

        $levelsByVariant = collect();
        if ($locationId && $variants->isNotEmpty()) {
            $levelsByVariant = StockLevel::query()
                ->where('location_id', $locationId)
                ->whereIn('product_variant_id', $variants->pluck('id')->all())
                ->get()
                ->keyBy('product_variant_id');
        }

        return response()->json([
            'variants' => $variants->map(function (ProductVariant $v) use ($levelsByVariant) {
                $level = $levelsByVariant->get($v->id);
                $availableMilli = $level ? max(0, $level->on_hand - $level->reserved) : 0;
                $isService = $v->product?->type === ProductType::Service;
                $isMadeToOrder = $v->product?->type === ProductType::MadeToOrder;

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'product_name' => $v->product?->name,
                    'variant_name' => $v->name,
                    'display_name' => trim(($v->product?->name ?? $v->sku).($v->name ? ' — '.$v->name : '')),
                    'product_type' => $v->product?->type?->value ?? 'stock',
                    'is_service' => $isService,
                    'is_serialized' => (bool) $v->product?->is_serialized,
                    'default_source' => $isMadeToOrder ? SalesLineSource::Make->value : SalesLineSource::Stock->value,
                    'price_cents' => $v->price,
                    'price_decimal' => Money::fromCents($v->price)->toDecimal(),
                    'min_price_cents' => $v->min_price,
                    'available_milli' => $availableMilli,
                    'available_display' => Quantity::format($availableMilli),
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
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'channel' => ['nullable', Rule::enum(SalesChannel::class)],
            'kind' => ['nullable', Rule::enum(SalesOrderKind::class)],
            'fulfilment_type' => ['nullable', Rule::enum(FulfilmentType::class)],
            'delivery_address' => ['nullable', 'array'],
            'requested_delivery_date' => ['nullable', 'date'],
            'order_discount_type' => ['nullable', 'string', 'in:fixed,percent'],
            'order_discount_value' => ['nullable', 'string'],
            'delivery_charge' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.stock_unit_id' => ['nullable', 'integer', 'exists:stock_units,id'],
            'lines.*.is_custom' => ['nullable', 'boolean'],
            'lines.*.is_service' => ['nullable', 'boolean'],
            'lines.*.name_snapshot' => ['nullable', 'string', 'max:200'],
            'lines.*.custom_spec' => ['nullable', 'string', 'max:1000'],
            'lines.*.custom_spec_id' => ['nullable', 'integer', 'exists:custom_specs,id'],
            'lines.*.source' => ['nullable', Rule::enum(SalesLineSource::class)],
            'lines.*.fulfil_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'lines.*.quantity' => ['required', 'string'],
            'lines.*.unit_price' => ['required', 'string'],
            'lines.*.discount_type' => ['nullable', 'string', 'in:fixed,percent'],
            'lines.*.discount_value' => ['nullable', 'string'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $request->user()->worksAt((int) $validated['location_id'])) {
            abort(403);
        }

        $orderDiscountType = $validated['order_discount_type'] ?? null;
        $orderDiscountValue = 0;
        if ($orderDiscountType === 'fixed' && isset($validated['order_discount_value']) && trim((string) $validated['order_discount_value']) !== '') {
            $orderDiscountValue = Money::fromDecimal((string) $validated['order_discount_value'])->cents;
        } elseif ($orderDiscountType === 'percent' && isset($validated['order_discount_value']) && trim((string) $validated['order_discount_value']) !== '') {
            $orderDiscountValue = BasisPoints::fromPercent((string) $validated['order_discount_value']);
        }

        $deliveryCharge = isset($validated['delivery_charge']) && trim((string) $validated['delivery_charge']) !== ''
            ? Money::fromDecimal((string) $validated['delivery_charge'])->cents
            : 0;

        $lines = array_map(function (array $line) use ($validated) {
            $qty = Quantity::parse($line['quantity']);
            $unitPrice = Money::fromDecimal($line['unit_price'])->cents;
            $discType = $line['discount_type'] ?? null;
            $discVal = 0;

            if ($discType === 'fixed' && isset($line['discount_value']) && trim((string) $line['discount_value']) !== '') {
                $discVal = Money::fromDecimal((string) $line['discount_value'])->cents;
            } elseif ($discType === 'percent' && isset($line['discount_value']) && trim((string) $line['discount_value']) !== '') {
                $discVal = BasisPoints::fromPercent((string) $line['discount_value']);
            }

            return [
                'product_variant_id' => ! empty($line['product_variant_id']) ? (int) $line['product_variant_id'] : null,
                'stock_unit_id' => ! empty($line['stock_unit_id']) ? (int) $line['stock_unit_id'] : null,
                'is_custom' => (bool) ($line['is_custom'] ?? empty($line['product_variant_id'])),
                'is_service' => (bool) ($line['is_service'] ?? false),
                'name_snapshot' => $line['name_snapshot'] ?? null,
                'custom_spec' => $line['custom_spec'] ?? null,
                'custom_spec_id' => ! empty($line['custom_spec_id']) ? (int) $line['custom_spec_id'] : null,
                'source' => $line['source'] ?? SalesLineSource::Stock->value,
                'fulfil_location_id' => ! empty($line['fulfil_location_id'])
                    ? (int) $line['fulfil_location_id']
                    : (int) $validated['location_id'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_type' => $discType,
                'discount_value' => $discVal,
                'note' => $line['note'] ?? null,
            ];
        }, $validated['lines']);

        return [
            'customer_id' => $validated['customer_id'] ?? null,
            'location_id' => (int) $validated['location_id'],
            'channel' => $validated['channel'] ?? SalesChannel::Showroom->value,
            'kind' => $validated['kind'] ?? SalesOrderKind::Order->value,
            'fulfilment_type' => $validated['fulfilment_type'] ?? FulfilmentType::Collect->value,
            'delivery_address' => $validated['delivery_address'] ?? null,
            'requested_delivery_date' => $validated['requested_delivery_date'] ?? null,
            'order_discount_type' => $orderDiscountType,
            'order_discount_value' => $orderDiscountValue,
            'delivery_charge' => $deliveryCharge,
            'notes' => $validated['notes'] ?? null,
            'approver_id' => $validated['approver_id'] ?? null,
            'pin' => $validated['pin'] ?? null,
            'lines' => $lines,
        ];
    }
}
