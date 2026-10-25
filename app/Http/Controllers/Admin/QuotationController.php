<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuotationStatus;
use App\Exceptions\ApprovalRefused;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\Quotation;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\QuotationService;
use App\Support\BasisPoints;
use App\Support\CurrentLocation;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\QuotationPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->can('manage-sales');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'all');
        $followUpDue = $request->boolean('follow_up_due');

        $quotations = Quotation::query()
            ->forLocation()
            ->with(['customer:id,name,phone', 'location:id,name,code', 'salesperson:id,name'])
            ->withCount('lines')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($followUpDue, fn ($q) => $q->whereNotNull('follow_up_date')
                ->whereDate('follow_up_date', '<=', now()->toDateString())
                ->whereIn('status', [QuotationStatus::Draft->value, QuotationStatus::Sent->value]))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quotation $q) => [
                'id' => $q->id,
                'number' => $q->number,
                'customer_id' => $q->customer_id,
                'customer_name' => $q->customer?->name,
                'customer_phone' => $q->customer?->displayPhone() ?? $q->customer?->phone,
                'location_id' => $q->location_id,
                'location_name' => $q->location?->name,
                'location_code' => $q->location?->code,
                'salesperson_name' => $q->salesperson?->name,
                'status' => $q->status->value,
                'status_label' => $q->status->label(),
                'valid_until' => $q->valid_until?->toDateString(),
                'follow_up_date' => $q->follow_up_date?->toDateString(),
                'is_follow_up_due' => $q->follow_up_date !== null
                    && $q->follow_up_date->lte(now()->startOfDay())
                    && in_array($q->status, [QuotationStatus::Draft, QuotationStatus::Sent], true),
                'lines_count' => $q->lines_count,
                'grand_total' => $q->grand_total,
                'created_at' => $q->created_at?->toIso8601String(),
            ]);

        $followUpsDueCount = Quotation::query()
            ->forLocation()
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', now()->toDateString())
            ->whereIn('status', [QuotationStatus::Draft->value, QuotationStatus::Sent->value])
            ->count();

        return Inertia::render('Admin/Sales/Quotations/Index', [
            'quotations' => $quotations,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'follow_up_due' => $followUpDue,
            ],
            'statuses' => QuotationStatus::options(),
            'followUpsDueCount' => $followUpsDueCount,
            'canManage' => $canManage,
        ]);
    }

    public function create(Request $request, CurrentLocation $currentLocation, DiscountApprovalService $discountService): Response
    {
        Gate::authorize('manage-sales');

        $user = $request->user();
        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $settings = CompanySetting::current();

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Sales/Quotations/Form', [
            'quotation' => null,
            'locations' => $accessibleLocations,
            'defaultLocationId' => $currentLocation->id() ?? $accessibleLocations->first()?->id,
            'defaultValidUntil' => now()->addDays(max(1, (int) ($settings->quote_validity_days ?: 14)))->toDateString(),
            'pricingConfig' => [
                'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
                'prices_include_tax' => (bool) $settings->prices_include_vat,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
                'max_discount_bp' => $discountService->maxDiscountBpFor($user),
            ],
            'approversByLocation' => $approversByLocation,
            'canOverride' => $user->can('override'),
        ]);
    }

    public function store(Request $request, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $payload = $this->validateQuotationPayload($request);

        try {
            $quotation = $service->save($payload, $request->user());
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('sales.quotations.show', $quotation)
            ->with('success', "Quotation {$quotation->number} created.");
    }

    public function show(Request $request, Quotation $quotation, QuotationService $service): Response
    {
        $user = $request->user();
        if (! $user->worksAt($quotation->location_id)) {
            abort(403);
        }

        $quotation->load(['customer', 'location:id,name,code', 'salesperson:id,name', 'lines.variant.product', 'lines.customSpec.baseProduct:id,name', 'convertedSalesOrder:id,number']);
        $preview = $service->previewConversion($quotation);

        return Inertia::render('Admin/Sales/Quotations/Show', [
            'quotation' => [
                'id' => $quotation->id,
                'number' => $quotation->number,
                'share_token' => $quotation->share_token,
                'customer' => $quotation->customer ? [
                    'id' => $quotation->customer->id,
                    'name' => $quotation->customer->name,
                    'phone' => $quotation->customer->phone,
                    'phone_display' => $quotation->customer->displayPhone(),
                    'email' => $quotation->customer->email,
                ] : null,
                'location' => $quotation->location ? [
                    'id' => $quotation->location->id,
                    'name' => $quotation->location->name,
                    'code' => $quotation->location->code,
                ] : null,
                'salesperson_name' => $quotation->salesperson?->name,
                'status' => $quotation->status->value,
                'status_label' => $quotation->status->label(),
                'can_edit' => $quotation->status->canEdit() && $user->can('manage-sales'),
                'can_convert' => $quotation->status->canConvert() && $user->can('manage-sales'),
                'valid_until' => $quotation->valid_until?->toDateString(),
                'follow_up_date' => $quotation->follow_up_date?->toDateString(),
                'prices_include_tax' => $quotation->prices_include_tax,
                'tax_rate_bp' => $quotation->tax_rate_bp,
                'items_gross_total' => $quotation->items_gross_total,
                'line_discount_total' => $quotation->line_discount_total,
                'subtotal' => $quotation->subtotal,
                'order_discount_type' => $quotation->order_discount_type,
                'order_discount_value' => $quotation->order_discount_value,
                'order_discount_cents' => $quotation->order_discount_cents,
                'tax_total' => $quotation->tax_total,
                'delivery_charge' => $quotation->delivery_charge,
                'rounding_cents' => $quotation->rounding_cents,
                'grand_total' => $quotation->grand_total,
                'lost_reason' => $quotation->lost_reason,
                'notes' => $quotation->notes,
                'sent_at' => $quotation->sent_at?->toIso8601String(),
                'accepted_at' => $quotation->accepted_at?->toIso8601String(),
                'converted_sales_order' => $quotation->convertedSalesOrder ? [
                    'id' => $quotation->convertedSalesOrder->id,
                    'number' => $quotation->convertedSalesOrder->number,
                ] : null,
                'whatsapp_url' => $service->whatsappShareUrl($quotation),
                'public_pdf_url' => url("/q/{$quotation->share_token}/pdf"),
                'lines' => $quotation->lines->map(fn ($l) => [
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
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                    'discount_type' => $l->discount_type,
                    'discount_value' => $l->discount_value,
                    'discount_cents' => $l->discount_cents,
                    'line_subtotal' => $l->line_subtotal,
                    'order_discount_share' => $l->order_discount_share,
                    'tax_cents' => $l->tax_cents,
                    'line_total' => $l->line_total,
                    'note' => $l->note,
                ])->values()->all(),
            ],
            'conversionPreview' => $preview,
            'approvers' => ManagerApproval::approvers($quotation->location_id),
            'canManage' => $user->can('manage-sales'),
            'canOverride' => $user->can('override'),
        ]);
    }

    public function edit(Request $request, Quotation $quotation, DiscountApprovalService $discountService): Response
    {
        Gate::authorize('manage-sales');

        if (! $quotation->status->canEdit()) {
            abort(422, 'This quotation can no longer be edited.');
        }

        $user = $request->user();
        if (! $user->worksAt($quotation->location_id)) {
            abort(403);
        }

        $accessibleLocations = Location::accessibleTo($user)->orderBy('id')->get(['id', 'name', 'code']);
        $settings = CompanySetting::current();
        $quotation->load(['customer', 'lines.variant.product', 'lines.customSpec.baseProduct:id,name']);

        $approversByLocation = [];
        foreach ($accessibleLocations as $loc) {
            $approversByLocation[$loc->id] = ManagerApproval::approvers($loc->id);
        }

        return Inertia::render('Admin/Sales/Quotations/Form', [
            'quotation' => [
                'id' => $quotation->id,
                'number' => $quotation->number,
                'customer_id' => $quotation->customer_id,
                'customer' => $quotation->customer ? [
                    'id' => $quotation->customer->id,
                    'name' => $quotation->customer->name,
                    'phone' => $quotation->customer->phone,
                    'phone_display' => $quotation->customer->displayPhone(),
                ] : null,
                'location_id' => $quotation->location_id,
                'valid_until' => $quotation->valid_until?->toDateString(),
                'follow_up_date' => $quotation->follow_up_date?->toDateString(),
                'order_discount_type' => $quotation->order_discount_type,
                'order_discount_value' => $quotation->order_discount_type === 'percent'
                    ? BasisPoints::toPercent($quotation->order_discount_value)
                    : (in_array($quotation->order_discount_type, ['fixed', 'amount'], true) ? Money::fromCents($quotation->order_discount_value)->toDecimal() : ''),
                'delivery_charge' => Money::fromCents($quotation->delivery_charge)->toDecimal(),
                'notes' => $quotation->notes,
                'lines' => $quotation->lines->map(fn ($l) => [
                    'product_variant_id' => $l->product_variant_id,
                    'is_custom' => $l->is_custom,
                    'is_service' => $l->is_service,
                    'sku_snapshot' => $l->sku_snapshot,
                    'name_snapshot' => $l->name_snapshot,
                    'custom_spec' => $l->custom_spec,
                    'custom_spec_id' => $l->custom_spec_id,
                    'custom_spec_detail' => $l->customSpec?->toPayload(),
                    'quantity' => Quantity::format($l->quantity),
                    'unit_price' => Money::fromCents($l->unit_price)->toDecimal(),
                    'min_price_cents' => $l->variant ? $l->variant->min_price : 0,
                    'discount_type' => $l->discount_type === 'amount' ? 'fixed' : ($l->discount_type ?? ''),
                    'discount_value' => $l->discount_type === 'percent'
                        ? BasisPoints::toPercent($l->discount_value)
                        : (in_array($l->discount_type, ['fixed', 'amount'], true) ? Money::fromCents($l->discount_value)->toDecimal() : ''),
                    'note' => $l->note,
                ])->values()->all(),
            ],
            'locations' => $accessibleLocations,
            'defaultLocationId' => $quotation->location_id,
            'defaultValidUntil' => $quotation->valid_until?->toDateString(),
            'pricingConfig' => [
                'tax_rate_bp' => $settings->vat_registered ? (int) $settings->vat_rate_bp : 0,
                'prices_include_tax' => (bool) $settings->prices_include_vat,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
                'max_discount_bp' => $discountService->maxDiscountBpFor($user),
            ],
            'approversByLocation' => $approversByLocation,
            'canOverride' => $user->can('override'),
        ]);
    }

    public function update(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $payload = $this->validateQuotationPayload($request);

        try {
            $quotation = $service->save($payload, $request->user(), $quotation);
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return to_route('sales.quotations.show', $quotation)
            ->with('success', "Quotation {$quotation->number} updated.");
    }

    public function send(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $emailCustomer = $request->boolean('email_customer', true);

        try {
            $quotation = $service->send($quotation, $request->user(), $emailCustomer);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $msg = $emailCustomer && $quotation->customer?->email
            ? "Quotation {$quotation->number} marked as sent and emailed to {$quotation->customer->email}."
            : "Quotation {$quotation->number} marked as sent.";

        return back()->with('success', $msg);
    }

    public function accept(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        try {
            $service->markAccepted($quotation, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation {$quotation->number} marked as accepted.");
    }

    public function lose(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $service->markLost($quotation, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation {$quotation->number} marked as lost.");
    }

    public function convert(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        Gate::authorize('manage-sales');

        $validated = $request->validate([
            'honour_quoted_prices' => ['nullable', 'boolean'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $order = $service->convertToSalesOrder(
                $quotation,
                $request->user(),
                (bool) ($validated['honour_quoted_prices'] ?? true),
                isset($validated['approver_id']) ? (int) $validated['approver_id'] : null,
                $validated['pin'] ?? null,
            );
        } catch (ApprovalRefused $e) {
            throw ValidationException::withMessages(['pin' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return to_route('sales.orders.show', $order)
            ->with('success', "Quotation {$quotation->number} converted to sales order {$order->number}.");
    }

    public function pdf(Request $request, Quotation $quotation): HttpResponse
    {
        if (! $request->user()->worksAt($quotation->location_id)) {
            abort(403);
        }

        $bytes = QuotationPdf::render($quotation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$quotation->number.'.pdf"',
        ]);
    }

    public function publicPdf(string $token): HttpResponse
    {
        $quotation = Quotation::query()->where('share_token', $token)->firstOrFail();

        $bytes = QuotationPdf::render($quotation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$quotation->number.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuotationPayload(Request $request): array
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'valid_until' => ['nullable', 'date'],
            'follow_up_date' => ['nullable', 'date'],
            'order_discount_type' => ['nullable', 'string', 'in:fixed,percent'],
            'order_discount_value' => ['nullable', 'string'],
            'delivery_charge' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.is_custom' => ['nullable', 'boolean'],
            'lines.*.is_service' => ['nullable', 'boolean'],
            'lines.*.name_snapshot' => ['nullable', 'string', 'max:200'],
            'lines.*.custom_spec' => ['nullable', 'string', 'max:1000'],
            'lines.*.custom_spec_id' => ['nullable', 'integer', 'exists:custom_specs,id'],
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

        $lines = array_map(function (array $line) {
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
                'is_custom' => (bool) ($line['is_custom'] ?? empty($line['product_variant_id'])),
                'is_service' => (bool) ($line['is_service'] ?? false),
                'name_snapshot' => $line['name_snapshot'] ?? null,
                'custom_spec' => $line['custom_spec'] ?? null,
                'custom_spec_id' => ! empty($line['custom_spec_id']) ? (int) $line['custom_spec_id'] : null,
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
            'valid_until' => $validated['valid_until'] ?? null,
            'follow_up_date' => $validated['follow_up_date'] ?? null,
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
