<?php

namespace App\Services\Sales;

use App\Enums\SalesChannel;
use App\Enums\SalesLineSource;
use App\Enums\SalesOrderKind;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One POS sale in one transaction: save the cart as an order, confirm it (invoice number),
 * take every payment, and for cash & carry hand the goods over at once. Any failure rolls the
 * whole sale back, including its invoice number, so numbers stay gap-free.
 */
class PosCheckoutService
{
    public function __construct(
        private readonly SalesOrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $cart  Same shape as SalesOrderService::save() input
     * @param  array<int, array<string, mixed>>  $payments  PaymentService::receive() inputs
     */
    public function checkout(array $cart, array $payments, User $actor): SalesOrder
    {
        $kind = SalesOrderKind::from((string) ($cart['kind'] ?? SalesOrderKind::CashAndCarry->value));

        if ($kind === SalesOrderKind::CashAndCarry) {
            foreach ($cart['lines'] ?? [] as $line) {
                $source = $line['source'] ?? SalesLineSource::Stock->value;
                if (! ($line['is_service'] ?? false) && $source !== SalesLineSource::Stock->value) {
                    throw new InvalidArgumentException('Made-to-order or supplier items cannot be cash & carry: switch the sale to an order (collect later or deliver).');
                }
            }
        }

        return DB::transaction(function () use ($cart, $payments, $actor, $kind) {
            $order = $this->orders->save([...$cart, 'kind' => $kind->value, 'channel' => SalesChannel::Showroom->value], $actor);
            $order = $this->orders->confirm($order, $actor);

            foreach ($payments as $payment) {
                $this->payments->receive($order, $payment, $actor);
            }

            $order->refresh();

            if ($kind === SalesOrderKind::CashAndCarry) {
                if ($order->balance_due > 0) {
                    throw new InvalidArgumentException('Cash & carry sales must be paid in full: take the remaining balance or switch to an order.');
                }

                $order->load('lines');
                $toFulfil = $order->lines
                    ->reject(fn (SalesOrderLine $l) => $l->is_service || $l->remainingQty() === 0)
                    ->mapWithKeys(fn (SalesOrderLine $l) => [$l->id => $l->remainingQty()])
                    ->all();

                if ($toFulfil !== []) {
                    $this->orders->fulfil($order, $toFulfil, $actor, 'Collected at the counter');
                    $order = $this->orders->complete($order, $actor);
                }
            }

            return $order->fresh(['customer', 'location', 'lines', 'payments.method']);
        }, 3); // retried on a deadlock (the first number of a new counter scope can race)
    }
}
