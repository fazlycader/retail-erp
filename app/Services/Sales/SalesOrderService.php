<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Enums\FulfilmentType;
use App\Enums\MovementType;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\ReservationStatus;
use App\Enums\SalesChannel;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Enums\SalesOrderStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockService;
use App\Services\Production\CustomSpecService;
use App\Services\Production\ProductionService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\BasisPoints;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\DayLock;
use App\Support\ManagerApproval;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manages the full SalesOrder lifecycle:
 * draft → confirmed (assigns invoice #, reserves stock, creates back-to-back PO drafts for supplier_order lines)
 * → partially_fulfilled / fulfilled (issues stock from reservation, prints delivery note)
 * → completed | cancelled (releases reservations, requires manager if payments exist).
 */
class SalesOrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly DiscountApprovalService $discountApproval,
        private readonly ReservationService $reservations,
        private readonly StockService $stock,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly CreditNoteService $creditNotes,
        private readonly ProductionService $production,
        private readonly CustomSpecService $specs,
    ) {}

    /**
     * Create or update a draft sales order.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $actor, ?SalesOrder $order = null): SalesOrder
    {
        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A sales order requires at least one line.');
        }

        if ($order && ! $order->status->canEdit()) {
            throw new InvalidArgumentException("Sales order [{$order->number}] cannot be edited in status [{$order->status->value}].");
        }

        return DB::transaction(function () use ($data, $actor, $order) {
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

                if ($variant && $variant->product?->is_serialized && ($qty % 1000 !== 0)) {
                    throw new InvalidArgumentException("Serialized item [{$variant->sku}] must be sold in whole units.");
                }

                $unitPrice = isset($raw['unit_price']) && $raw['unit_price'] !== null
                    ? max(0, (int) $raw['unit_price'])
                    : ($variant ? (int) $variant->price : 0);

                $defaultSource = match (true) {
                    $isCustom || $variant?->product?->type === ProductType::MadeToOrder => SalesLineSource::Make->value,
                    default => SalesLineSource::Stock->value,
                };
                $source = SalesLineSource::from((string) ($raw['source'] ?? $defaultSource));

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
                    'stock_unit_id' => ! empty($raw['stock_unit_id']) ? (int) $raw['stock_unit_id'] : null,
                    'is_custom' => $isCustom,
                    'is_service' => $isService,
                    'sku_snapshot' => $variant?->sku,
                    'name_snapshot' => $nameSnapshot,
                    'attributes_snapshot' => $attributesSnapshot,
                    'custom_spec' => isset($raw['custom_spec']) && trim((string) $raw['custom_spec']) !== ''
                        ? trim((string) $raw['custom_spec'])
                        : null,
                    'custom_spec_id' => ! empty($raw['custom_spec_id']) ? (int) $raw['custom_spec_id'] : null,
                    'source' => $source->value,
                    'fulfil_location_id' => ! empty($raw['fulfil_location_id'])
                        ? (int) $raw['fulfil_location_id']
                        : $location->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'min_price_snapshot' => $variant ? (int) $variant->min_price : 0,
                    'unit_cost_snapshot' => $variant ? (int) ($variant->average_cost ?: $variant->last_cost) : 0,
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
                $order,
            );

            $channel = isset($data['channel'])
                ? ($data['channel'] instanceof SalesChannel ? $data['channel'] : SalesChannel::from((string) $data['channel']))
                : SalesChannel::Showroom;

            $kind = isset($data['kind'])
                ? ($data['kind'] instanceof SalesOrderKind ? $data['kind'] : SalesOrderKind::from((string) $data['kind']))
                : SalesOrderKind::Order;

            $fulfilmentType = isset($data['fulfilment_type'])
                ? ($data['fulfilment_type'] instanceof FulfilmentType ? $data['fulfilment_type'] : FulfilmentType::from((string) $data['fulfilment_type']))
                : FulfilmentType::Collect;

            $businessDate = BusinessDay::current($location->id);

            if (! $order) {
                $seq = Counter::next('sales_order', "location:{$location->id}");
                $number = sprintf('SO-%s-%06d', $location->code, $seq);

                $order = SalesOrder::query()->create([
                    'number' => $number,
                    'quotation_id' => ! empty($data['quotation_id']) ? (int) $data['quotation_id'] : null,
                    'customer_id' => $customerId,
                    'location_id' => $location->id,
                    'salesperson_id' => ! empty($data['salesperson_id']) ? (int) $data['salesperson_id'] : $actor->id,
                    'channel' => $channel,
                    'kind' => $kind,
                    'status' => SalesOrderStatus::Draft,
                    'payment_status' => PaymentStatus::Unpaid,
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
                    'paid_amount' => 0,
                    'balance_due' => $priced['grand_total'],
                    'fulfilment_type' => $fulfilmentType,
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'requested_delivery_date' => ! empty($data['requested_delivery_date']) ? $data['requested_delivery_date'] : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'discount_approved_by' => $approver?->id,
                    'business_date' => $businessDate,
                    'sync_version' => 0,
                ]);

                $this->recordStatusHistory($order, null, SalesOrderStatus::Draft, $actor, 'Sales order created');
            } else {
                $order->update([
                    'customer_id' => $customerId,
                    'location_id' => $location->id,
                    'channel' => $channel,
                    'kind' => $kind,
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
                    'balance_due' => max(0, $priced['grand_total'] - $order->paid_amount),
                    'fulfilment_type' => $fulfilmentType,
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'requested_delivery_date' => ! empty($data['requested_delivery_date']) ? $data['requested_delivery_date'] : null,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                    'discount_approved_by' => $approver?->id,
                ]);
                $order->lines()->delete();
            }

            foreach ($priced['lines'] as $line) {
                $created = $order->lines()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'stock_unit_id' => $line['stock_unit_id'],
                    'is_custom' => $line['is_custom'],
                    'is_service' => $line['is_service'],
                    'sku_snapshot' => $line['sku_snapshot'],
                    'name_snapshot' => $line['name_snapshot'],
                    'attributes_snapshot' => $line['attributes_snapshot'],
                    'custom_spec' => $line['custom_spec'],
                    'source' => $line['source'],
                    'fulfil_location_id' => $line['fulfil_location_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'min_price_snapshot' => $line['min_price_snapshot'],
                    'unit_cost_snapshot' => $line['unit_cost_snapshot'],
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

            SyncVersion::bump($order);

            AuditLog::record(
                $order->wasRecentlyCreated ? 'sales_order.created' : 'sales_order.updated',
                $order,
                "Sales order {$order->number}",
                null,
                [
                    'number' => $order->number,
                    'customer_id' => $order->customer_id,
                    'grand_total' => $order->grand_total,
                ],
                $actor,
            );

            return $order->fresh(['customer', 'location', 'salesperson', 'lines.variant.product', 'lines.fulfilLocation', 'statusHistory.user']);
        });
    }

    /**
     * Confirm a draft sales order:
     * - Requires a customer (not blacklisted), except walk-in cash & carry sales.
     * - Assigns gap-free invoice_number (INV-{LOC}-{000001}).
     * - Checks require_advance_bp setting (sets advance_pending flag if unpaid until Step 6).
     * - Reserves stock lines at their fulfil_location_id via ReservationService.
     * - Creates back-to-back draft POs for supplier_order lines and links purchase_order_line_id.
     * - Opens a production job per custom / made-to-order line (Step 11) and texts the customer.
     */
    public function confirm(SalesOrder $order, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = SalesOrder::query()
                ->with(['customer', 'location', 'lines.variant.product'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $order->status->canConfirm()) {
                throw new InvalidArgumentException("Sales order [{$order->number}] cannot be confirmed from status [{$order->status->value}].");
            }

            // Walk-in cash & carry sales may be anonymous; orders to collect later or deliver need a customer.
            if ((! $order->customer_id || ! $order->customer) && $order->kind !== SalesOrderKind::CashAndCarry) {
                throw new InvalidArgumentException('Confirming a sales order requires a customer.');
            }

            if ($order->customer?->is_blacklisted) {
                throw new InvalidArgumentException("Customer [{$order->customer->name}] is blacklisted: {$order->customer->blacklist_reason}");
            }

            $settings = CompanySetting::current();
            $invPrefix = $settings->prefixFor(DocumentType::Invoice);
            $invSeq = Counter::next(DocumentType::Invoice->value, "location:{$order->location_id}");
            $invoiceNumber = sprintf('%s-%s-%06d', $invPrefix, $order->location->code, $invSeq);

            $requireAdvanceBp = (int) $settings->require_advance_bp;
            $advanceRequiredCents = $requireAdvanceBp > 0
                ? BasisPoints::of($order->grand_total, $requireAdvanceBp)
                : 0;
            $advancePending = $advanceRequiredCents > 0 && $order->paid_amount < $advanceRequiredCents;

            $supplierOrderLines = [];
            $order->confirmed_at = now();
            $this->production->createForOrder($order, $actor);

            foreach ($order->lines as $line) {
                if ($line->is_service) {
                    continue;
                }

                $fulfilLocId = $line->fulfil_location_id ?: $order->location_id;

                if ($line->source === SalesLineSource::Stock && $line->product_variant_id) {
                    $this->reservations->reserve(
                        variant: $line->variant,
                        locationId: $fulfilLocId,
                        quantity: $line->quantity,
                        reservedFor: $line,
                        stockUnitId: $line->stock_unit_id,
                        note: "Reserved for {$order->number}",
                        userId: $actor->id,
                    );

                    $line->reserved_qty = $line->quantity;
                    $line->save();
                } elseif ($line->source === SalesLineSource::SupplierOrder && $line->product_variant_id) {
                    $supplierOrderLines[] = $line;
                }
            }

            // Create back-to-back PO drafts grouped by preferred supplier and fulfil location
            if ($supplierOrderLines !== []) {
                $this->createBackToBackPurchaseOrders($order, $supplierOrderLines, $actor);
                $this->production->linkPurchaseOrders($order);
            }

            $oldStatus = $order->status;
            $order->invoice_number = $invoiceNumber;
            $order->status = SalesOrderStatus::Confirmed;
            $order->advance_required_cents = $advanceRequiredCents;
            $order->advance_pending = $advancePending;
            $order->confirmed_by = $actor->id;
            $order->confirmed_at = now();
            $order->save();

            $this->recordStatusHistory(
                $order,
                $oldStatus,
                SalesOrderStatus::Confirmed,
                $actor,
                $advancePending ? 'Confirmed (advance payment pending)' : "Confirmed — Invoice {$invoiceNumber}",
            );

            // Update customer purchase summary
            if ($customer = $order->customer) {
                $customer->first_purchase_at ??= now();
                $customer->last_purchase_at = now();
                $customer->total_orders_count += 1;
                $customer->total_spent += $order->grand_total;
                $customer->save();
            }

            SyncVersion::bump($order);

            AuditLog::record(
                'sales_order.confirmed',
                $order,
                "Confirmed sales order {$order->number} (Invoice {$invoiceNumber})",
                ['status' => $oldStatus->value],
                [
                    'status' => $order->status->value,
                    'invoice_number' => $invoiceNumber,
                    'advance_pending' => $advancePending,
                ],
                $actor,
            );

            return $order->fresh(['customer', 'location', 'salesperson', 'lines.variant.product', 'lines.fulfilLocation', 'lines.purchaseOrderLine.purchaseOrder', 'statusHistory.user']);
        }, 3); // retried on a deadlock when this is the outermost transaction
    }

    /**
     * Fulfil (collect or deliver) quantities on a confirmed sales order.
     *
     * @param  array<int, int>  $lineQuantities  Map of sales_order_line_id => quantity (milli-units) to fulfil now
     */
    public function fulfil(SalesOrder $order, array $lineQuantities, User $actor, ?string $note = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $lineQuantities, $actor, $note) {
            $order = SalesOrder::query()
                ->with(['lines.reservations', 'lines.variant.product', 'lines.productionJob'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $order->status->canFulfil()) {
                throw new InvalidArgumentException("Sales order [{$order->number}] cannot be fulfilled in status [{$order->status->value}].");
            }

            $anyFulfilledNow = false;

            foreach ($order->lines as $line) {
                if ($line->is_service) {
                    continue;
                }

                $qtyNow = (int) ($lineQuantities[$line->id] ?? 0);
                if ($qtyNow <= 0) {
                    continue;
                }

                $remaining = $line->remainingQty();
                if ($qtyNow > $remaining) {
                    throw new InvalidArgumentException("Fulfilment quantity exceeds remaining quantity on [{$line->name_snapshot}].");
                }

                if ($line->productionJob?->status?->isOpen()) {
                    throw new InvalidArgumentException("[{$line->name_snapshot}] is still being made ({$line->productionJob->number}, {$line->productionJob->status->label()}).");
                }

                $anyFulfilledNow = true;
                $fulfilLocId = $line->fulfil_location_id ?: $order->location_id;

                if ($line->product_variant_id) {
                    // Use the line's reservations first (one per finished piece for custom work),
                    // then take anything not reserved from the fulfil location.
                    $left = $qtyNow;
                    foreach ($line->reservations->where('status', ReservationStatus::Active)->sortBy('id') as $activeRes) {
                        if ($left <= 0) {
                            break;
                        }
                        $resFulfilQty = min($left, $activeRes->quantity);
                        $this->reservations->fulfil(
                            reservation: $activeRes,
                            movementType: MovementType::Sale,
                            reference: $order,
                            note: $note ?? "Fulfilled {$order->number}",
                            quantity: $resFulfilQty,
                        );
                        $left -= $resFulfilQty;
                    }

                    if ($left > 0) {
                        $this->stock->move([
                            'product_variant_id' => $line->product_variant_id,
                            'location_id' => $fulfilLocId,
                            'type' => MovementType::Sale,
                            'quantity' => -$left,
                            'reference' => $order,
                            'user_id' => $actor->id,
                            'note' => $note ?? "Fulfilled {$order->number}",
                        ]);
                    }
                }

                $line->fulfilled_qty += $qtyNow;
                $line->reserved_qty = max(0, $line->reserved_qty - $qtyNow);
                $line->save();
            }

            if (! $anyFulfilledNow) {
                throw new InvalidArgumentException('Enter a positive quantity to fulfil on at least one line.');
            }

            $order->load('lines');
            $nonServiceLines = $order->lines->where('is_service', false);
            $allFulfilled = $nonServiceLines->every(fn (SalesOrderLine $l) => $l->fulfilled_qty >= $l->quantity);

            $oldStatus = $order->status;
            $newStatus = $allFulfilled ? SalesOrderStatus::Fulfilled : SalesOrderStatus::PartiallyFulfilled;

            if ($newStatus !== $oldStatus) {
                $order->status = $newStatus;
                $order->save();
                $this->recordStatusHistory($order, $oldStatus, $newStatus, $actor, $note ?? 'Stock issued');
            }

            SyncVersion::bump($order);

            AuditLog::record(
                'sales_order.fulfilled',
                $order,
                "Fulfilled items on {$order->number} ({$newStatus->label()})",
                ['status' => $oldStatus->value],
                ['status' => $newStatus->value, 'quantities' => $lineQuantities],
                $actor,
            );

            return $order->fresh(['customer', 'location', 'salesperson', 'lines.variant.product', 'lines.fulfilLocation', 'statusHistory.user']);
        });
    }

    /**
     * Cancel a sales order and release all active stock reservations.
     * Cancelling an order that has payments requires a manager, and what was paid becomes a credit note.
     */
    public function cancel(
        SalesOrder $order,
        User $actor,
        ?string $reason = null,
        ?int $approverId = null,
        ?string $pin = null,
    ): SalesOrder {
        return DB::transaction(function () use ($order, $actor, $reason, $approverId, $pin) {
            $order = SalesOrder::query()
                ->with(['lines.reservations'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $order->status->canCancel()) {
                throw new InvalidArgumentException("Sales order [{$order->number}] cannot be cancelled from status [{$order->status->value}].");
            }

            DayLock::guard($order->location_id, $order->business_date, $actor, "cancelled {$order->number}", $order);

            $anyFulfilled = $order->lines->contains(fn (SalesOrderLine $l) => $l->fulfilled_qty > 0);
            if ($anyFulfilled) {
                throw new InvalidArgumentException("Sales order [{$order->number}] already has fulfilled items; process a sales return instead.");
            }

            if ($order->paid_amount > 0 && ! $actor->role->canOverride()) {
                ManagerApproval::verify(
                    $actor,
                    $order->location_id,
                    $approverId,
                    $pin,
                    'sales_order.cancel_paid',
                    $order,
                    "cancelling paid order {$order->number}",
                );
            }

            foreach ($order->lines as $line) {
                foreach ($line->reservations as $res) {
                    if ($res->status === ReservationStatus::Active) {
                        $this->reservations->release($res);
                    }
                }
                if ($line->reserved_qty > 0) {
                    $line->reserved_qty = 0;
                    $line->save();
                }
            }

            // Money already taken goes on a credit note (refund it from there if the customer wants cash).
            $kept = $order->paid_amount - $order->refunded_total;
            if ($kept > 0) {
                $this->creditNotes->issue($order->location_id, $order->customer_id, $kept, "Cancelled order {$order->number}", $actor, $order);
                $order->refunded_total += $kept;
            }

            $oldStatus = $order->status;
            $order->status = SalesOrderStatus::Cancelled;
            $order->balance_due = 0;
            $order->cancelled_by = $actor->id;
            $order->cancelled_at = now();
            $order->cancel_reason = $reason ? trim($reason) : null;
            $order->save();

            $this->recordStatusHistory($order, $oldStatus, SalesOrderStatus::Cancelled, $actor, $reason ?? 'Order cancelled');
            $this->production->cancelForLines($order->lines->pluck('id')->all(), $actor, "Order {$order->number} cancelled");

            SyncVersion::bump($order);

            AuditLog::record(
                'sales_order.cancelled',
                $order,
                "Cancelled sales order {$order->number}",
                ['status' => $oldStatus->value],
                ['status' => SalesOrderStatus::Cancelled->value, 'cancel_reason' => $order->cancel_reason],
                $actor,
            );

            return $order->fresh(['customer', 'location', 'salesperson', 'lines.variant.product', 'statusHistory.user']);
        });
    }

    /**
     * Remove an unfulfilled line from a confirmed order before it is paid for: releases its
     * reservation and reprices the order. The new total may never fall below what was paid.
     */
    public function removeLine(SalesOrder $order, SalesOrderLine $line, User $actor): SalesOrder
    {
        return DB::transaction(function () use ($order, $line, $actor) {
            $order = SalesOrder::query()->with(['lines.reservations'])->lockForUpdate()->findOrFail($order->id);
            $line = $order->lines->firstWhere('id', $line->id);

            if (! $line) {
                throw new InvalidArgumentException('That line is not on this order.');
            }

            if (! $order->status->canCancel() || $order->status === SalesOrderStatus::Draft) {
                throw new InvalidArgumentException("Lines can only be removed from a confirmed order that has not been fulfilled (order is {$order->status->label()}).");
            }

            DayLock::guard($order->location_id, $order->business_date, $actor, "removed {$line->name_snapshot} from {$order->number}", $order);

            if ($line->fulfilled_qty > 0 || $line->purchase_order_line_id) {
                throw new InvalidArgumentException("[{$line->name_snapshot}] has been fulfilled or ordered from a supplier; it cannot be removed.");
            }

            if ($order->lines->count() === 1) {
                throw new InvalidArgumentException('This is the only line: cancel the order instead.');
            }

            $remaining = $order->lines->reject(fn (SalesOrderLine $l) => $l->id === $line->id)->values();
            $settings = CompanySetting::current();

            $priced = $this->pricing->calculate(
                $remaining->map(fn (SalesOrderLine $l) => [
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                    'discount_type' => $l->discount_type,
                    'discount_value' => $l->discount_value,
                    'is_service' => $l->is_service,
                ])->all(),
                [
                    'order_discount_type' => $order->order_discount_type,
                    'order_discount_value' => $order->order_discount_value,
                    'delivery_charge' => $order->delivery_charge,
                    'tax_rate_bp' => $order->tax_rate_bp,
                    'prices_include_tax' => $order->prices_include_tax,
                    'rounding_unit' => (int) $settings->cash_rounding_unit,
                ],
            );

            if ($priced['grand_total'] < $order->paid_amount) {
                throw new InvalidArgumentException('This line is already paid for: removing it would leave the order overpaid. Process a return instead.');
            }

            foreach ($line->reservations as $res) {
                if ($res->status === ReservationStatus::Active) {
                    $this->reservations->release($res);
                }
            }

            $before = ['grand_total' => $order->grand_total, 'line' => $line->name_snapshot, 'quantity' => $line->quantity];
            $this->production->cancelForLines([$line->id], $actor, "Line removed from {$order->number}");
            $line->delete();

            foreach ($remaining as $i => $l) {
                $p = $priced['lines'][$i];
                $l->update([
                    'discount_cents' => $p['discount_cents'],
                    'line_subtotal' => $p['line_subtotal'],
                    'order_discount_share' => $p['order_discount_share'],
                    'tax_cents' => $p['tax_cents'],
                    'line_total' => $p['line_total'],
                ]);
            }

            if ($customer = Customer::query()->find($order->customer_id)) {
                $customer->total_spent = max(0, $customer->total_spent - ($order->grand_total - $priced['grand_total']));
                $customer->save();
            }

            $order->fill([
                'items_gross_total' => $priced['items_gross_total'],
                'line_discount_total' => $priced['line_discount_total'],
                'subtotal' => $priced['subtotal'],
                'order_discount_cents' => $priced['order_discount_cents'],
                'tax_total' => $priced['tax_total'],
                'delivery_charge' => $priced['delivery_charge'],
                'rounding_cents' => $priced['rounding_cents'],
                'grand_total' => $priced['grand_total'],
            ]);
            $order->balance_due = max(0, $order->netTotal() - ($order->paid_amount - $order->refunded_total));
            $order->save();
            SyncVersion::bump($order);

            AuditLog::record(
                'sales_order.line_removed',
                $order,
                "Removed {$line->name_snapshot} from {$order->number}",
                $before,
                ['grand_total' => $order->grand_total],
                $actor,
            );

            return $order;
        });
    }

    /**
     * Close a fully paid and fulfilled order (cash & carry at the till).
     */
    public function complete(SalesOrder $order, User $actor, ?string $note = null): SalesOrder
    {
        $order->refresh();

        if ($order->status !== SalesOrderStatus::Fulfilled || $order->balance_due > 0) {
            throw new InvalidArgumentException("Sales order [{$order->number}] must be fulfilled and paid before it is completed.");
        }

        $order->status = SalesOrderStatus::Completed;
        $order->save();
        $this->recordStatusHistory($order, SalesOrderStatus::Fulfilled, SalesOrderStatus::Completed, $actor, $note ?? 'Paid and collected');
        SyncVersion::bump($order);

        return $order;
    }

    /**
     * @param  array<int, SalesOrderLine>  $supplierLines
     */
    private function createBackToBackPurchaseOrders(SalesOrder $order, array $supplierLines, User $actor): void
    {
        $variantIds = array_map(fn (SalesOrderLine $l) => (int) $l->product_variant_id, $supplierLines);

        $pivots = DB::table('product_supplier')
            ->whereIn('product_variant_id', $variantIds)
            ->orderByDesc('is_preferred')
            ->orderBy('id')
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($rows) => $rows->first());

        $fallbackSupplierId = Supplier::query()->active()->orderBy('id')->value('id')
            ?? Supplier::query()->create(['name' => 'Default Preferred Supplier'])->id;

        $grouped = [];
        foreach ($supplierLines as $soLine) {
            $pivot = $pivots->get($soLine->product_variant_id);
            $supplierId = $pivot ? (int) $pivot->supplier_id : (int) $fallbackSupplierId;
            $fulfilLocId = (int) ($soLine->fulfil_location_id ?: $order->location_id);
            $unitCost = (int) ($pivot?->last_cost ?: ($soLine->unit_cost_snapshot ?: 0));

            $key = "{$supplierId}:{$fulfilLocId}";
            $grouped[$key]['supplier_id'] = $supplierId;
            $grouped[$key]['location_id'] = $fulfilLocId;
            $grouped[$key]['so_lines'][] = [
                'so_line' => $soLine,
                'payload' => [
                    'product_variant_id' => $soLine->product_variant_id,
                    'supplier_sku' => $pivot->supplier_sku ?? null,
                    'ordered_qty' => $soLine->quantity,
                    'unit_cost' => $unitCost,
                    'sales_order_line_id' => $soLine->id,
                    'note' => "Back-to-back for {$order->number}",
                ],
            ];
        }

        foreach ($grouped as $group) {
            $po = $this->purchaseOrders->save([
                'supplier_id' => $group['supplier_id'],
                'location_id' => $group['location_id'],
                'expected_date' => $order->requested_delivery_date?->toDateString(),
                'notes' => "Back-to-back order for Sales Order {$order->number}",
                'lines' => array_column($group['so_lines'], 'payload'),
            ], $actor);

            foreach ($po->lines as $poLine) {
                if ($poLine->sales_order_line_id) {
                    SalesOrderLine::query()
                        ->whereKey($poLine->sales_order_line_id)
                        ->update(['purchase_order_line_id' => $poLine->id]);
                }
            }
        }
    }

    private function recordStatusHistory(
        SalesOrder $order,
        ?SalesOrderStatus $from,
        SalesOrderStatus $to,
        User $actor,
        ?string $note = null,
    ): void {
        $order->statusHistory()->create([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $actor->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
