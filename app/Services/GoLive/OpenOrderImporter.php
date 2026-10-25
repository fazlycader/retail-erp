<?php

namespace App\Services\GoLive;

use App\Enums\FulfilmentType;
use App\Enums\ProductType;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Rules\SriLankanPhone;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Open customer orders from the old system, one file line per order line, grouped by order_ref.
 * Each becomes a confirmed sales order (invoice number, stock reserved at the location), dated as
 * in the old system, with any advance already paid as an "imported" payment (method other, no shift).
 *
 * Import products, customers and opening stock first: unknown SKUs or phones, or not enough stock
 * to reserve, fail the whole order with the reason.
 */
class OpenOrderImporter extends Importer
{
    private const ORDER_FIELDS = ['location', 'customer_phone', 'order_date', 'delivery_date', 'fulfilment', 'advance_paid', 'advance_date', 'notes'];

    public function __construct(private SalesOrderService $orders, private PaymentService $payments) {}

    public function kind(): string
    {
        return 'open_orders';
    }

    public function required(): array
    {
        return ['order_ref', 'location', 'customer_phone', 'quantity', 'unit_price'];
    }

    public function optional(): array
    {
        return ['sku', 'description', 'source', 'order_date', 'delivery_date', 'fulfilment', 'advance_paid', 'advance_date', 'notes'];
    }

    protected function check(array $rows, ImportContext $context): array
    {
        $groups = [];
        foreach ($rows as ['line' => $line, 'row' => $raw]) {
            $groups[Str::upper(trim($raw['order_ref'] ?? ''))][] = ['line' => $line, 'row' => $raw];
        }

        $locations = Location::query()->get(['id', 'code', 'is_active'])->keyBy(fn ($l) => Str::upper($l->code));
        $skus = collect($rows)->pluck('row.sku')->filter()->map(fn ($s) => Str::upper(trim($s)))->unique()->all();
        $variants = ProductVariant::query()->with('product:id,type,is_serialized,is_active')->whereIn('sku', $skus)->get()->keyBy(fn ($v) => Str::upper($v->sku));
        $out = [];

        foreach ($groups as $ref => $lines) {
            $first = $lines[0]['row'];
            $row = new ImportRow(array_column($lines, 'line'), $first, $ref !== '' ? $ref : null);
            $out[] = $row;

            if ($ref === '') {
                $row->fail('order_ref is required.');

                continue;
            }

            // Order-level columns must agree on every line of the order (or be blank after the first).
            foreach (self::ORDER_FIELDS as $field) {
                foreach ($lines as ['line' => $line, 'row' => $raw]) {
                    $value = trim($raw[$field] ?? '');
                    if ($value !== '' && $value !== trim($first[$field] ?? '')) {
                        $row->fail("{$field} differs between lines of order {$ref} (line {$line}).");
                    }
                }
            }

            $location = $locations->get(Str::upper(trim($first['location'] ?? '')));
            if (! $location || ! $location->is_active) {
                $row->fail("location \"{$first['location']}\" is not an active location code.");
            }

            $phone = SriLankanPhone::normalize($first['customer_phone'] ?? '');
            $customer = $phone ? Customer::query()->where('phone', $phone)->first(['id', 'name', 'is_blacklisted']) : null;
            if (! $customer) {
                $row->fail("No customer with phone \"{$first['customer_phone']}\": import customers first.");
            } elseif ($customer->is_blacklisted) {
                $row->fail("Customer {$customer->name} is blacklisted.");
            }

            $fulfilment = Str::lower(trim($first['fulfilment'] ?? '')) ?: 'collect';
            if (! FulfilmentType::tryFrom($fulfilment)) {
                $row->fail("fulfilment \"{$first['fulfilment']}\" must be collect or deliver.");
            }

            $orderDate = $this->date($row, 'order_date');
            $deliveryDate = $this->date($row, 'delivery_date');
            $advance = $this->money($row, 'advance_paid');
            $advanceDate = $this->date($row, 'advance_date');

            $orderLines = [];
            foreach ($lines as ['line' => $line, 'row' => $raw]) {
                $lineRow = new ImportRow([$line], $raw);
                $sku = Str::upper(trim($raw['sku'] ?? ''));
                $variant = $sku !== '' ? $variants->get($sku) : null;
                $description = trim($raw['description'] ?? '');

                if ($sku !== '' && ! $variant) {
                    $row->fail("Line {$line}: unknown SKU \"{$raw['sku']}\".");
                }
                if ($sku === '' && $description === '') {
                    $row->fail("Line {$line}: give a sku, or a description for a custom item.");
                }

                $source = Str::lower(trim($raw['source'] ?? '')) ?: ($variant && $variant->product?->type === ProductType::Stock ? 'stock' : 'make');
                if (! SalesLineSource::tryFrom($source)) {
                    $row->fail("Line {$line}: source \"{$raw['source']}\" must be stock, make or supplier_order.");
                }
                if (! $variant && $source !== 'make') {
                    $row->fail("Line {$line}: a custom item (no SKU) can only be source make.");
                }

                try {
                    $qty = Quantity::parse($raw['quantity'] ?? '');
                    if ($qty <= 0) {
                        $row->fail("Line {$line}: quantity must be more than zero.");
                    }
                    if ($variant?->product?->is_serialized && $qty % Quantity::SCALE !== 0) {
                        $row->fail("Line {$line}: {$variant->sku} is serialized: whole pieces only.");
                    }
                } catch (Throwable) {
                    $row->fail("Line {$line}: quantity \"{$raw['quantity']}\" is not a number.");
                    $qty = 0;
                }

                $price = $this->money($lineRow, 'unit_price', required: true);
                foreach ($lineRow->reasons as $reason) {
                    $row->fail("Line {$line}: {$reason}");
                }

                if ($variant && $price !== null && $variant->min_price > 0 && $price < $variant->min_price) {
                    $row->note("Line {$line}: price is below {$variant->sku}'s minimum (approved by the importing admin).");
                }

                $orderLines[] = [
                    'product_variant_id' => $variant?->id,
                    'is_custom' => $variant === null,
                    'name_snapshot' => $variant ? null : $description,
                    'custom_spec' => $variant && $description !== '' ? $description : null,
                    'source' => $source,
                    'fulfil_location_id' => $location?->id,
                    'quantity' => $qty,
                    'unit_price' => (int) $price,
                ];
            }

            if ($advance !== null && $advance < 0) {
                $row->fail('advance_paid cannot be negative.');
            }
            if ($advance) {
                $row->note('Advance of '.Money::fromCents($advance)->format().' will be recorded as an imported payment (method Other, no shift).');
            }

            $row->data = [
                'location_id' => $location?->id,
                'customer_id' => $customer?->id,
                'kind' => SalesOrderKind::Order->value,
                'fulfilment_type' => FulfilmentType::tryFrom($fulfilment)?->value ?? 'collect',
                'delivery_address' => null,
                'requested_delivery_date' => $deliveryDate,
                'notes' => trim(trim($first['notes'] ?? '')."\nImported from the previous system: order {$ref}."),
                'lines' => $orderLines,
                'order_date' => $orderDate,
                'advance' => (int) $advance,
                'advance_date' => $advanceDate ?? $orderDate,
            ];
        }

        return $out;
    }

    protected function apply(ImportRow $row, ImportContext $context): Model
    {
        $data = $row->data;
        $order = $this->orders->save(collect($data)->except(['order_date', 'advance', 'advance_date'])->all(), $context->actor);
        $order = $this->orders->confirm($order, $context->actor);

        if ($data['order_date']) {
            // Dated as in the old system, so today's sales aren't inflated by old orders.
            SalesOrder::query()->whereKey($order->id)->update(['business_date' => $data['order_date']]);
        }

        if ($data['advance'] > 0) {
            $this->payments->recordImportedAdvance($order, $data['advance'], $data['advance_date'] ?? now()->toDateString(), $row->key, $context->actor);
        }

        return $order;
    }
}
