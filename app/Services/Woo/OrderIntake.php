<?php

namespace App\Services\Woo;

use App\Enums\PaymentMethodCode;
use App\Enums\PaymentRecordStatus;
use App\Enums\ReservationStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InsufficientStock;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WooLink;
use App\Models\WooSyncLog;
use App\Rules\SriLankanPhone;
use App\Services\Inventory\ReservationService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use App\Support\BusinessDay;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A WooCommerce order (webhook or reconcile) → a sales order, idempotently by Woo order id.
 *
 * New order: channel web; customer matched by phone, then email, else created; lines matched by SKU
 * (an unknown SKU becomes a custom line and the order waits on "needs attention", unconfirmed, rather
 * than guessing); shipping = delivery with the Woo address and charge; confirmed at the web fulfilment
 * location (stock reserved there, or at the online location that has it, per setting); when the Woo
 * status is in the "paid" list, the payment is recorded with method Online and the gateway title.
 *
 * Later updates: becoming paid records the payment once; cancelled / refunded / failed releases the
 * reservations (an unpaid order is cancelled) and flags anything paid for staff. Money is never moved
 * automatically.
 */
class OrderIntake
{
    private const OPEN_STATUSES = ['pending', 'on-hold', 'processing', 'completed'];

    private const DEAD_STATUSES = ['cancelled', 'refunded', 'failed'];

    public function __construct(
        private SalesOrderService $orders,
        private PaymentService $payments,
        private ReservationService $reservations,
    ) {}

    /** The system user web orders and their payments are recorded by (cannot sign in). */
    public static function actor(): User
    {
        $user = User::query()->firstOrNew(['email' => 'woocommerce@integration.invalid']);
        if (! $user->exists) {
            $user->forceFill(['name' => 'WooCommerce', 'role' => Role::Admin, 'is_active' => false, 'password' => Str::random(64)])->save();
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $woo  a WooCommerce order (REST v3 shape)
     */
    public function process(array $woo, string $via = 'webhook'): ?SalesOrder
    {
        $wooId = (int) ($woo['id'] ?? 0);
        if ($wooId <= 0) {
            throw new InvalidArgumentException('Not a WooCommerce order (no id).');
        }

        $existing = SalesOrder::query()->where('woo_order_id', $wooId)->first();

        return $existing ? $this->update($existing, $woo, $via) : $this->create($woo, $via);
    }

    private function create(array $woo, string $via): ?SalesOrder
    {
        $status = (string) ($woo['status'] ?? '');
        if (! in_array($status, self::OPEN_STATUSES, true)) {
            WooSyncLog::write('info', 'intake', "Woo order #{$this->number($woo)} ignored: it arrived already {$status}.", ['woo_order_id' => $woo['id']]);

            return null;
        }

        $actor = self::actor();
        $settings = CompanySetting::current();
        $location = Location::query()->find($settings->web_fulfilment_location_id)
            ?? Location::query()->whereIn('id', $settings->onlineLocationIds())->orderBy('id')->first()
            ?? throw new InvalidArgumentException('Choose the web fulfilment location on the Online store page.');

        $attention = [];
        [$customerId, $customerNote] = $this->customer($woo);
        if ($customerNote) {
            $attention[] = $customerNote;
        }

        $lines = [];
        foreach ($woo['line_items'] ?? [] as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unit = $this->unitPrice($item, $qty);
            $sku = trim((string) ($item['sku'] ?? ''));
            $variant = $sku !== '' ? ProductVariant::query()->with('product:id,type')->where('sku', $sku)->first() : null;

            if (! $variant) {
                $attention[] = $sku === '' ? "\"{$item['name']}\" has no SKU in WooCommerce." : "Unknown SKU {$sku} (\"{$item['name']}\").";
                $lines[] = ['is_custom' => true, 'name_snapshot' => mb_substr((string) ($item['name'] ?? 'Web item'), 0, 200), 'source' => 'make', 'quantity' => Quantity::fromUnits($qty), 'unit_price' => $unit, 'fulfil_location_id' => $location->id];

                continue;
            }

            $lines[] = [
                'product_variant_id' => $variant->id,
                'quantity' => Quantity::fromUnits($qty),
                'unit_price' => $unit,
                'fulfil_location_id' => $this->fulfilFrom($variant->id, Quantity::fromUnits($qty), $location->id),
            ];
        }

        foreach ($woo['fee_lines'] ?? [] as $fee) {
            $lines[] = ['is_custom' => true, 'is_service' => true, 'name_snapshot' => mb_substr((string) ($fee['name'] ?? 'Fee'), 0, 200), 'source' => 'make', 'quantity' => Quantity::fromUnits(1), 'unit_price' => $this->money($fee['total'] ?? '0') + $this->money($fee['total_tax'] ?? '0'), 'fulfil_location_id' => $location->id];
        }

        if ($lines === []) {
            throw new InvalidArgumentException("Woo order #{$this->number($woo)} has no items.");
        }

        $shipping = $woo['shipping'] ?? [];
        $pickup = collect($woo['shipping_lines'] ?? [])->contains(fn ($l) => str_contains((string) ($l['method_id'] ?? ''), 'pickup'));
        $deliver = ! $pickup && filled($shipping['address_1'] ?? null);

        $order = DB::transaction(function () use ($woo, $customerId, $location, $lines, $deliver, $shipping, $actor) {
            $order = $this->orders->save([
                'customer_id' => $customerId,
                'location_id' => $location->id,
                'channel' => 'web',
                'kind' => 'order',
                'fulfilment_type' => $deliver ? 'deliver' : 'collect',
                'delivery_address' => $deliver ? array_filter([
                    'label' => 'Web order',
                    'line1' => trim(($shipping['address_1'] ?? '').' '.($shipping['company'] ?? '')),
                    'line2' => $shipping['address_2'] ?? null,
                    'city' => $shipping['city'] ?? null,
                    'district' => $shipping['state'] ?? null,
                ]) : null,
                'delivery_charge' => $this->money($woo['shipping_total'] ?? '0') + $this->money($woo['shipping_tax'] ?? '0'),
                'notes' => trim("WooCommerce order #{$this->number($woo)}.\n".($woo['customer_note'] ?? '')),
                'lines' => $lines,
            ], $actor);

            $order->forceFill(['woo_order_id' => (int) $woo['id'], 'woo_order_number' => $this->number($woo), 'woo_status' => $woo['status']])->save();
            WooLink::remember(WooLink::ORDER, $order->id, (int) $woo['id']);

            return $order;
        });

        $wooTotal = $this->money($woo['total'] ?? '0');
        if (abs($order->grand_total - $wooTotal) > 100) {
            $attention[] = 'WooCommerce total '.Money::fromCents($wooTotal)->format().' differs from ours '.Money::fromCents($order->grand_total)->format().' (check prices, coupons and rounding).';
        }

        if ($attention === []) {
            try {
                $order = $this->orders->confirm($order, $actor);
            } catch (InsufficientStock $e) {
                $attention[] = 'Not enough stock to reserve: '.$e->getMessage();
            } catch (InvalidArgumentException $e) {
                $attention[] = 'Could not confirm: '.$e->getMessage();
            }
        }

        if ($attention !== []) {
            $this->flag($order, $attention);
        }

        $this->recordPaymentIfPaid($order->fresh(), $woo);
        WooSyncLog::write($attention ? 'warning' : 'info', 'intake', "Woo order #{$this->number($woo)} → {$order->number}".($attention ? ' (needs attention)' : '')." via {$via}.", ['sales_order_id' => $order->id]);

        return $order->fresh();
    }

    private function update(SalesOrder $order, array $woo, string $via): SalesOrder
    {
        $status = (string) ($woo['status'] ?? '');
        $previous = $order->woo_status;
        $order->forceFill(['woo_status' => $status])->saveQuietly();

        if (in_array($status, self::DEAD_STATUSES, true) && $order->status !== SalesOrderStatus::Cancelled) {
            $this->deadInWoo($order->fresh(['lines.reservations']), $status, $woo);
        } elseif (in_array($status, CompanySetting::current()->wooPaidStatuses(), true)) {
            $this->recordPaymentIfPaid($order->fresh(), $woo);
        }

        $refunded = collect($woo['refunds'] ?? [])->sum(fn ($r) => abs($this->money((string) ($r['total'] ?? '0'))));
        if ($refunded > 0 && ! str_contains((string) $order->attention_reason, 'Refunded in WooCommerce')) {
            $this->flag($order, ['Refunded in WooCommerce: '.Money::fromCents($refunded)->format().'. Settle it here (return or credit) once the money has gone back.']);
        }

        if ($previous !== $status) {
            WooSyncLog::write('info', 'intake', "Woo order #{$order->woo_order_number}: {$previous} → {$status} via {$via}.", ['sales_order_id' => $order->id]);
        }

        return $order->fresh();
    }

    /** Cancelled / refunded / failed in WooCommerce: free the stock; never move money. */
    private function deadInWoo(SalesOrder $order, string $status, array $woo): void
    {
        $paid = (int) Payment::query()->counted()->where('sales_order_id', $order->id)->sum('amount');
        $fulfilled = $order->lines->contains(fn ($l) => $l->fulfilled_qty > 0);

        if ($paid === 0 && ! $fulfilled && $order->status->canCancel()) {
            $this->orders->cancel($order, self::actor(), "{$status} in WooCommerce");

            return;
        }

        DB::transaction(function () use ($order) {
            foreach ($order->lines as $line) {
                foreach ($line->reservations->where('status', ReservationStatus::Active) as $reservation) {
                    $this->reservations->release($reservation);
                }
                if ($line->reserved_qty > 0) {
                    $line->forceFill(['reserved_qty' => 0])->save();
                }
            }
        });

        $this->flag($order, [ucfirst($status).' in WooCommerce'.($paid ? ' after '.Money::fromCents($paid)->format().' was paid online' : '').($fulfilled ? ', and goods were already handed over' : '').'. Stock reservations released. Refund the customer through the payment gateway or bank, then cancel or return the order here.']);
    }

    private function recordPaymentIfPaid(SalesOrder $order, array $woo): void
    {
        if (! in_array((string) ($woo['status'] ?? ''), CompanySetting::current()->wooPaidStatuses(), true) || $order->status === SalesOrderStatus::Cancelled) {
            return;
        }

        $online = PaymentMethod::byCode(PaymentMethodCode::Online)->id;
        $alreadyOnline = Payment::query()->where('sales_order_id', $order->id)->where('payment_method_id', $online)->where('status', PaymentRecordStatus::Completed->value)->exists();
        $amount = min($this->money($woo['total'] ?? '0'), $order->balance_due);

        if ($alreadyOnline || $amount <= 0) {
            return;
        }

        $paidOn = filled($woo['date_paid_gmt'] ?? null)
            ? BusinessDay::for(CarbonImmutable::parse($woo['date_paid_gmt'].'Z'))
            : BusinessDay::current($order->location_id);
        $reference = filled($woo['transaction_id'] ?? null) ? (string) $woo['transaction_id'] : "Woo #{$this->number($woo)}";

        $this->payments->recordExternal($order, $amount, PaymentMethodCode::Online, $paidOn, $reference, 'Paid online: '.($woo['payment_method_title'] ?? $woo['payment_method'] ?? 'WooCommerce'), self::actor());
    }

    /**
     * Customer by phone, then email; created when new (a phone is needed to create one).
     *
     * @return array{0: int|null, 1: string|null} customer id, attention note
     */
    private function customer(array $woo): array
    {
        $billing = $woo['billing'] ?? [];
        $phone = SriLankanPhone::normalize((string) ($billing['phone'] ?? '')) ?? SriLankanPhone::normalize((string) ($woo['shipping']['phone'] ?? ''));
        $email = Str::lower(trim((string) ($billing['email'] ?? ''))) ?: null;

        $customer = ($phone ? Customer::query()->where('phone', $phone)->first() : null)
            ?? ($email ? Customer::query()->where('email', $email)->first() : null);

        if ($customer) {
            return [$customer->id, $customer->is_blacklisted ? "Customer {$customer->name} is blacklisted." : null];
        }

        if (! $phone) {
            return [null, 'No valid Sri Lankan phone number on the WooCommerce order: add the customer by hand.'];
        }

        $name = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: ($billing['company'] ?? '') ?: 'Web customer';
        $customer = Customer::query()->create([
            'name' => mb_substr($name, 0, 160),
            'phone' => $phone,
            'email' => $email,
            'type' => filled($billing['company'] ?? null) ? 'business' : 'individual',
            'addresses' => filled($billing['address_1'] ?? null) ? [['label' => 'Web', 'line1' => $billing['address_1'], 'line2' => $billing['address_2'] ?? null, 'city' => $billing['city'] ?? null, 'district' => $billing['state'] ?? null, 'is_default' => true]] : null,
            'notes' => 'Created from a WooCommerce order.',
        ]);

        return [$customer->id, null];
    }

    /** Where a web line is reserved: the fulfilment location, or (setting "stock") an online location that has it. */
    private function fulfilFrom(int $variantId, int $qty, int $default): int
    {
        $settings = CompanySetting::current();
        if ($settings->web_reserve_at !== 'stock') {
            return $default;
        }

        $levels = StockLevel::query()->where('product_variant_id', $variantId)->whereIn('location_id', $settings->onlineLocationIds())
            ->whereRaw('on_hand - reserved >= ?', [$qty])->pluck('location_id')->all();

        return in_array($default, $levels, true) ? $default : ($levels[0] ?? $default);
    }

    /** Unit price incl. VAT when shop prices include VAT, from Woo's line total (after coupons). */
    private function unitPrice(array $item, int $qty): int
    {
        $total = $this->money((string) ($item['total'] ?? '0'));
        if (CompanySetting::current()->prices_include_vat) {
            $total += $this->money((string) ($item['total_tax'] ?? '0'));
        }

        return intdiv($total * 2 + $qty, $qty * 2);
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function flag(SalesOrder $order, array $reasons): void
    {
        $existing = array_filter(explode("\n", (string) $order->attention_reason));
        $order->forceFill(['needs_attention' => true, 'attention_reason' => implode("\n", array_values(array_unique([...$existing, ...$reasons])))])->saveQuietly();
    }

    private function money(string $amount): int
    {
        return Money::fromDecimal(number_format((float) $amount, 2, '.', ''))->cents;
    }

    private function number(array $woo): string
    {
        return (string) ($woo['number'] ?? $woo['id']);
    }
}
