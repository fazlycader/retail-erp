<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\ProductType;
use App\Enums\QuotationStatus;
use App\Enums\SalesChannel;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Mail\QuotationMail;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Production\CustomSpecService;
use App\Support\Counter;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manages the Quotation lifecycle:
 * draft → sent → accepted | expired | lost (reason) | converted (to SalesOrder).
 */
class QuotationService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DiscountApprovalService $discountApproval,
        private readonly SalesOrderService $salesOrders,
        private readonly CustomSpecService $specs,
    ) {}

    /**
     * Create or update a quotation.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $actor, ?Quotation $quotation = null): Quotation
    {
        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A quotation requires at least one line.');
        }

        if ($quotation && ! $quotation->status->canEdit()) {
            throw new InvalidArgumentException("Quotation [{$quotation->number}] cannot be edited in status [{$quotation->status->value}].");
        }

        return DB::transaction(function () use ($data, $actor, $quotation) {
            $locationId = (int) $data['location_id'];
            $location = Location::query()->findOrFail($locationId);
            $settings = CompanySetting::current();

            $customerId = ! empty($data['customer_id']) ? (int) $data['customer_id'] : null;
            if ($customerId) {
                $customer = Customer::query()->findOrFail($customerId);
                if ($customer->is_blacklisted) {
                    throw new InvalidArgumentException("Customer [{$customer->name}] is blacklisted: {$customer->blacklist_reason}");
                }
            }

            $variantIds = array_values(array_unique(array_filter(array_map(
                fn ($l) => ! empty($l['product_variant_id']) ? (int) $l['product_variant_id'] : null,
                $data['lines'],
            ))));
            sort($variantIds);

            $variantsById = ProductVariant::query()
                ->withTrashed()
                ->with(['product', 'attributeValues.attribute'])
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');

            $pricingLines = [];
            foreach ($data['lines'] as $index => $raw) {
                $qty = (int) ($raw['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Line #'.($index + 1).' quantity must be greater than zero.');
                }

                $variantId = ! empty($raw['product_variant_id']) ? (int) $raw['product_variant_id'] : null;
                /** @var ProductVariant|null $variant */
                $variant = $variantId ? $variantsById->get($variantId) : null;

                if ($variantId && ! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                $isCustom = (bool) ($raw['is_custom'] ?? ($variant === null));
                $isService = $variant
                    ? ($variant->product?->type === ProductType::Service)
                    : (bool) ($raw['is_service'] ?? false);

                $unitPrice = isset($raw['unit_price']) && $raw['unit_price'] !== null
                    ? max(0, (int) $raw['unit_price'])
                    : ($variant ? (int) $variant->price : 0);

                $attributesSnapshot = null;
                if ($variant && $variant->attributeValues->isNotEmpty()) {
                    $attributesSnapshot = $variant->attributeValues
                        ->mapWithKeys(fn ($av) => [$av->attribute?->name ?? 'Option' => $av->value])
                        ->all();
                }

                $nameSnapshot = $variant
                    ? trim(($variant->product?->name ?? $variant->sku).($variant->name ? ' — '.$variant->name : ''))
                    : trim((string) ($raw['name_snapshot'] ?? 'Custom Made-to-Order Item'));

                $pricingLines[] = [
                    'product_variant_id' => $variant?->id,
                    'is_custom' => $isCustom,
                    'is_service' => $isService,
                    'sku_snapshot' => $variant?->sku,
                    'name_snapshot' => $nameSnapshot,
                    'attributes_snapshot' => $attributesSnapshot,
                    'custom_spec' => isset($raw['custom_spec']) && trim((string) $raw['custom_spec']) !== ''
                        ? trim((string) $raw['custom_spec'])
                        : null,
                    'custom_spec_id' => ! empty($raw['custom_spec_id']) ? (int) $raw['custom_spec_id'] : null,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_type' => $raw['discount_type'] ?? null,
                    'discount_value' => (int) ($raw['discount_value'] ?? 0),
                    'note' => isset($raw['note']) && trim((string) $raw['note']) !== '' ? trim((string) $raw['note']) : null,
                ];
            }

            $taxRateBp = $settings->vat_registered ? (int) $settings->vat_rate_bp : 0;
            $pricesIncludeTax = (bool) $settings->prices_include_vat;

            $priced = $this->pricing->calculate($pricingLines, [
                'order_discount_type' => $data['order_discount_type'] ?? null,
                'order_discount_value' => (int) ($data['order_discount_value'] ?? 0),
                'delivery_charge' => (int) ($data['delivery_charge'] ?? 0),
                'tax_rate_bp' => $taxRateBp,
                'prices_include_tax' => $pricesIncludeTax,
                'rounding_unit' => (int) $settings->cash_rounding_unit,
            ]);

            $approver = $this->discountApproval->authorise(
                $actor,
                $location->id,
                $priced,
                $variantsById,
                isset($data['approver_id']) ? (int) $data['approver_id'] : null,
                $data['pin'] ?? null,
                $quotation,
            );

            $defaultValidityDays = max(1, (int) ($settings->quote_validity_days ?: 14));
            $validUntil = ! empty($data['valid_until'])
                ? $data['valid_until']
                : now()->addDays($defaultValidityDays)->toDateString();

            $followUpDate = ! empty($data['follow_up_date']) ? $data['follow_up_date'] : null;

            if (! $quotation) {
                $prefix = $settings->prefixFor(DocumentType::Quotation);
                $seq = Counter::next(DocumentType::Quotation->value, "location:{$location->id}");
                $number = sprintf('%s-%s-%06d', $prefix, $location->code, $seq);

                $quotation = Quotation::query()->create([
                    'number' => $number,
                    'share_token' => Str::random(32),
                    'customer_id' => $customerId,
                    'location_id' => $location->id,
                    'salesperson_id' => ! empty($data['salesperson_id']) ? (int) $data['salesperson_id'] : $actor->id,
                    'status' => QuotationStatus::Draft,
                    'valid_until' => $validUntil,
                    'follow_up_date' => $followUpDate,
                    'prices_include_tax' => $priced['prices_include_tax'],
                    'tax_rate_bp' => $priced['tax_rate_bp'],
                    'items_gross_total' => $priced['items_gross_total'],
                    'line_discount_total' => $priced['line_discount_total'],
                    'subtotal' => $priced['subtotal'],
                    'order_discount_type' => $priced['order_discount_type'],
                    'order_discount_value' => $priced['order_discount_value'],
                    'order_discount_cents' => $priced['order_discount_cents'],
                    'tax_total' => $priced['tax_total'],
                    'delivery_charge' => $priced['delivery_charge'],
                    'rounding_cents' => $priced['rounding_cents'],
                    'grand_total' => $priced['grand_total'],
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'discount_approved_by' => $approver?->id,
                ]);
            } else {
                $quotation->update([
                    'customer_id' => $customerId,
                    'location_id' => $location->id,
                    'valid_until' => $validUntil,
                    'follow_up_date' => $followUpDate,
                    'prices_include_tax' => $priced['prices_include_tax'],
                    'tax_rate_bp' => $priced['tax_rate_bp'],
                    'items_gross_total' => $priced['items_gross_total'],
                    'line_discount_total' => $priced['line_discount_total'],
                    'subtotal' => $priced['subtotal'],
                    'order_discount_type' => $priced['order_discount_type'],
                    'order_discount_value' => $priced['order_discount_value'],
                    'order_discount_cents' => $priced['order_discount_cents'],
                    'tax_total' => $priced['tax_total'],
                    'delivery_charge' => $priced['delivery_charge'],
                    'rounding_cents' => $priced['rounding_cents'],
                    'grand_total' => $priced['grand_total'],
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'discount_approved_by' => $approver?->id,
                ]);
                $quotation->lines()->delete();
            }

            foreach ($priced['lines'] as $line) {
                $created = $quotation->lines()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'is_custom' => $line['is_custom'],
                    'is_service' => $line['is_service'],
                    'sku_snapshot' => $line['sku_snapshot'],
                    'name_snapshot' => $line['name_snapshot'],
                    'attributes_snapshot' => $line['attributes_snapshot'],
                    'custom_spec' => $line['custom_spec'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                    'discount_cents' => $line['discount_cents'],
                    'line_subtotal' => $line['line_subtotal'],
                    'order_discount_share' => $line['order_discount_share'],
                    'tax_cents' => $line['tax_cents'],
                    'line_total' => $line['line_total'],
                    'note' => $line['note'],
                ]);
                $this->specs->attach($line['custom_spec_id'] ?? null, $created);
            }

            AuditLog::record(
                $quotation->wasRecentlyCreated ? 'quotation.created' : 'quotation.updated',
                $quotation,
                "Quotation {$quotation->number}",
                null,
                [
                    'number' => $quotation->number,
                    'customer_id' => $quotation->customer_id,
                    'grand_total' => $quotation->grand_total,
                ],
                $actor,
            );

            return $quotation->fresh(['customer', 'location', 'salesperson', 'lines.variant.product']);
        });
    }

    /**
     * Mark a quotation as Sent and optionally queue an email to the customer.
     */
    public function send(Quotation $quotation, User $actor, bool $emailCustomer = false): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $emailCustomer) {
            $quotation->loadMissing(['customer', 'location', 'salesperson', 'lines']);

            if ($quotation->status === QuotationStatus::Draft) {
                $quotation->status = QuotationStatus::Sent;
            }
            $quotation->sent_at = now();
            if (! $quotation->share_token) {
                $quotation->share_token = Str::random(32);
            }
            $quotation->save();

            if ($emailCustomer && $quotation->customer?->email) {
                Mail::to($quotation->customer->email)->queue(new QuotationMail($quotation));
            }

            AuditLog::record(
                'quotation.sent',
                $quotation,
                "Sent quotation {$quotation->number}",
                null,
                ['email_sent' => $emailCustomer && (bool) $quotation->customer?->email],
                $actor,
            );

            return $quotation->fresh(['customer', 'location', 'salesperson', 'lines.variant.product']);
        });
    }

    /**
     * Mark a quotation as Accepted by the customer.
     */
    public function markAccepted(Quotation $quotation, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor) {
            if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Sent], true)) {
                throw new InvalidArgumentException("Quotation [{$quotation->number}] cannot be marked accepted from status [{$quotation->status->value}].");
            }

            $old = $quotation->status->value;
            $quotation->status = QuotationStatus::Accepted;
            $quotation->accepted_at = now();
            $quotation->save();

            AuditLog::record(
                'quotation.accepted',
                $quotation,
                "Quotation {$quotation->number} accepted",
                ['status' => $old],
                ['status' => QuotationStatus::Accepted->value],
                $actor,
            );

            return $quotation->fresh(['customer', 'location', 'salesperson', 'lines.variant.product']);
        });
    }

    /**
     * Mark a quotation as Lost with a reason.
     */
    public function markLost(Quotation $quotation, User $actor, string $reason): Quotation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required when marking a quotation as lost.');
        }

        return DB::transaction(function () use ($quotation, $actor, $reason) {
            if ($quotation->status === QuotationStatus::Converted) {
                throw new InvalidArgumentException("Quotation [{$quotation->number}] has already been converted to an order.");
            }

            $old = $quotation->status->value;
            $quotation->status = QuotationStatus::Lost;
            $quotation->lost_reason = $reason;
            $quotation->save();

            AuditLog::record(
                'quotation.lost',
                $quotation,
                "Quotation {$quotation->number} marked lost: {$reason}",
                ['status' => $old],
                ['status' => QuotationStatus::Lost->value, 'lost_reason' => $reason],
                $actor,
            );

            return $quotation->fresh(['customer', 'location', 'salesperson', 'lines.variant.product']);
        });
    }

    /**
     * Inspect whether any variant's current catalog price differs from the quoted unit price.
     *
     * @return array{
     *   any_price_changed: bool,
     *   lines: array<int, array{
     *     quotation_line_id: int,
     *     product_variant_id: int|null,
     *     name_snapshot: string,
     *     sku_snapshot: string|null,
     *     quantity: int,
     *     quoted_unit_price: int,
     *     current_unit_price: int,
     *     difference: int,
     *     has_price_change: bool,
     *   }>
     * }
     */
    public function previewConversion(Quotation $quotation): array
    {
        $quotation->load(['lines.variant']);

        $lines = [];
        $anyChanged = false;

        foreach ($quotation->lines as $line) {
            $quotedPrice = (int) $line->unit_price;
            $currentPrice = $line->variant ? (int) $line->variant->price : $quotedPrice;
            $diff = $currentPrice - $quotedPrice;
            $changed = $line->variant !== null && $diff !== 0;

            if ($changed) {
                $anyChanged = true;
            }

            $lines[] = [
                'quotation_line_id' => $line->id,
                'product_variant_id' => $line->product_variant_id,
                'name_snapshot' => $line->name_snapshot,
                'sku_snapshot' => $line->sku_snapshot,
                'quantity' => $line->quantity,
                'quoted_unit_price' => $quotedPrice,
                'current_unit_price' => $currentPrice,
                'difference' => $diff,
                'has_price_change' => $changed,
            ];
        }

        return [
            'any_price_changed' => $anyChanged,
            'lines' => $lines,
        ];
    }

    /**
     * Convert a quotation into a draft SalesOrder.
     * Re-prices against current variant prices when $honourQuotedPrices is false,
     * or honours the quoted unit prices (subject to role discount / min_price limits).
     */
    public function convertToSalesOrder(
        Quotation $quotation,
        User $actor,
        bool $honourQuotedPrices = true,
        ?int $approverId = null,
        ?string $pin = null,
    ): SalesOrder {
        return DB::transaction(function () use ($quotation, $actor, $honourQuotedPrices, $approverId, $pin) {
            $quotation = Quotation::query()
                ->with(['lines.variant.product'])
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if (! $quotation->status->canConvert()) {
                throw new InvalidArgumentException("Quotation [{$quotation->number}] cannot be converted from status [{$quotation->status->value}].");
            }

            $orderLines = [];
            foreach ($quotation->lines as $qLine) {
                $variant = $qLine->variant;
                $unitPrice = $honourQuotedPrices
                    ? (int) $qLine->unit_price
                    : ($variant ? (int) $variant->price : (int) $qLine->unit_price);

                $source = match (true) {
                    $qLine->is_custom || $variant?->product?->type === ProductType::MadeToOrder => SalesLineSource::Make->value,
                    default => SalesLineSource::Stock->value,
                };

                $orderLines[] = [
                    'product_variant_id' => $qLine->product_variant_id,
                    'is_custom' => $qLine->is_custom,
                    'is_service' => $qLine->is_service,
                    'name_snapshot' => $qLine->name_snapshot,
                    'custom_spec' => $qLine->custom_spec,
                    'custom_spec_id' => $qLine->custom_spec_id, // copied onto the order line
                    'source' => $source,
                    'fulfil_location_id' => $quotation->location_id,
                    'quantity' => $qLine->quantity,
                    'unit_price' => $unitPrice,
                    'discount_type' => $qLine->discount_type,
                    'discount_value' => $qLine->discount_value,
                    'note' => $qLine->note,
                ];
            }

            $order = $this->salesOrders->save([
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'location_id' => $quotation->location_id,
                'salesperson_id' => $quotation->salesperson_id ?? $actor->id,
                'channel' => SalesChannel::Showroom->value,
                'kind' => SalesOrderKind::Order->value,
                'order_discount_type' => $quotation->order_discount_type,
                'order_discount_value' => $quotation->order_discount_value,
                'delivery_charge' => $quotation->delivery_charge,
                'notes' => $quotation->notes,
                'lines' => $orderLines,
                'approver_id' => $approverId,
                'pin' => $pin,
            ], $actor);

            $quotation->status = QuotationStatus::Converted;
            $quotation->converted_sales_order_id = $order->id;
            $quotation->converted_at = now();
            $quotation->save();

            AuditLog::record(
                'quotation.converted',
                $quotation,
                "Converted quotation {$quotation->number} to sales order {$order->number}",
                null,
                [
                    'sales_order_id' => $order->id,
                    'sales_order_number' => $order->number,
                    'honour_quoted_prices' => $honourQuotedPrices,
                ],
                $actor,
            );

            return $order;
        });
    }

    /**
     * Build a WhatsApp wa.me share URL containing the quotation summary and public PDF link.
     */
    public function whatsappShareUrl(Quotation $quotation): string
    {
        $quotation->loadMissing(['customer']);
        $company = CompanySetting::current();

        if (! $quotation->share_token) {
            $quotation->share_token = Str::random(32);
            $quotation->save();
        }

        $pdfUrl = url("/q/{$quotation->share_token}/pdf");
        $totalFormatted = Money::fromCents($quotation->grand_total)->format();
        $validUntil = $quotation->valid_until?->format('Y-m-d') ?? '';

        $message = implode("\n", array_filter([
            "Hello {$quotation->customer?->name},",
            "Here is your quotation *{$quotation->number}* from *{$company->name}*.",
            "Total: *{$totalFormatted}*",
            $validUntil ? "Valid until: {$validUntil}" : null,
            "Download PDF: {$pdfUrl}",
        ]));

        $phoneDigits = $quotation->customer?->phone
            ? preg_replace('/\D+/', '', $quotation->customer->phone)
            : '';

        return "https://wa.me/{$phoneDigits}?text=".rawurlencode($message);
    }
}
