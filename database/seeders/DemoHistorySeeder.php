<?php

namespace Database\Seeders;

use App\Enums\CashMovementType;
use App\Enums\ChequeStatus;
use App\Enums\MovementType;
use App\Enums\PaymentMethodCode;
use App\Enums\ProductType;
use App\Enums\ReturnOutcome;
use App\Enums\ShiftStatus;
use App\Models\CashMovementCategory;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Cash\BankDepositService;
use App\Services\Cash\CashCalculator;
use App\Services\Cash\DayCloseService;
use App\Services\Cash\ShiftService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\TransferService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\PosCheckoutService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\ReturnService;
use App\Services\Sales\SalesOrderService;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Demo history for demos and training: the last N days (config demo.history_days, set by
 * `demo:reset --days=`) played through the real services with a simulated clock. Each day at
 * both showrooms: shifts with a float, walk-in sales (cash, card, LankaQR, split), orders with
 * advances that are delivered and paid a few days later (some by cheque), returns, pay-outs and
 * drops, quotations, weekly warehouse transfers and bank deposits, shift close (a few with
 * differences a manager approves) and the end of day (which builds the report summaries).
 * Deterministic (fixed random seed). Never in production.
 */
class DemoHistorySeeder extends Seeder
{
    private const FIRST_NAMES = ['Nimal', 'Kamala', 'Sunil', 'Dilani', 'Ruwan', 'Chathura', 'Ishara', 'Tharindu', 'Sanduni', 'Kasun', 'Nadeesha', 'Pradeep', 'Malini', 'Asanka', 'Fathima', 'Mohamed', 'Priya', 'Suresh', 'Anoma', 'Lahiru'];

    private const LAST_NAMES = ['Perera', 'Silva', 'Fernando', 'Jayasinghe', 'Bandara', 'Wickramasinghe', 'Rajapaksa', 'Dissanayake', 'Herath', 'Kumara', 'Rizvi', 'Nadarajah'];

    /** @var array<int, array{order: SalesOrder, due: string}> orders to deliver later */
    private array $pending = [];

    private User $admin;

    public function run(): void
    {
        $days = (int) config('demo.history_days', 0);

        if (app()->isProduction() || $days <= 0) {
            return;
        }

        mt_srand(2026);
        $this->admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $warehouse = Location::query()->where('code', 'HO')->firstOrFail();
        $showrooms = [
            'MS' => [Location::query()->where('code', 'MS')->firstOrFail(), User::query()->where('email', 'cashier@example.com')->firstOrFail()],
            'KDY' => [Location::query()->where('code', 'KDY')->firstOrFail(), User::query()->where('email', 'kandy.cashier@example.com')->first() ?? User::query()->where('email', 'manager@example.com')->firstOrFail()],
        ];
        $manager = User::query()->where('email', 'manager@example.com')->firstOrFail();
        $today = CarbonImmutable::now('Asia/Colombo')->startOfDay();
        $start = $today->subDays($days);

        Carbon::setTestNow($start->setTime(8, 0));

        try {
            $customers = $this->customers(40);
            $variants = ProductVariant::query()->with('product:id,type,is_serialized')->where('is_active', true)->get()
                ->filter(fn ($v) => $v->product?->type === ProductType::Stock && ! $v->product->is_serialized)->values();

            if ($variants->isEmpty()) {
                return;
            }

            $this->stockUp($variants, [$warehouse, ...array_column($showrooms, 0)]);

            for ($day = $start; $day->lt($today); $day = $day->addDay()) {
                foreach ($showrooms as [$location, $cashier]) {
                    $this->tradingDay($day, $location, $cashier, $manager, $customers, $variants);
                }

                if ($day->isMonday()) {
                    $this->weekly($day, $warehouse, $showrooms, $manager, $variants);
                }

                foreach ($showrooms as [$location]) {
                    Carbon::setTestNow($day->setTime(20, 30));
                    try {
                        app(DayCloseService::class)->close($location->id, $day->toDateString(), $manager, true);
                    } catch (Throwable) {
                        // e.g. a shift a manager still has to approve: the day stays open, like real life
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function tradingDay(CarbonImmutable $day, Location $location, User $cashier, User $manager, $customers, $variants): void
    {
        $at = fn (int $h, int $m = 0) => Carbon::setTestNow($day->setTime($h, $m));
        $method = fn (PaymentMethodCode $code) => PaymentMethod::byCode($code)->id;
        $busy = $day->isWeekend() ? 2 : 0;

        $at(9, 15);
        $shift = app(ShiftService::class)->open(Register::query()->where('location_id', $location->id)->firstOrFail(), $cashier, ['500000' => 1, '100000' => 4, '50000' => 2]);

        // Deliveries due today: hand over and collect the balance (sometimes by cheque).
        foreach ($this->pending as $i => ['order' => $order, 'due' => $due]) {
            if ($order->location_id !== $location->id || $due > $day->toDateString()) {
                continue;
            }
            $at(10, 0);
            try {
                $order = app(SalesOrderService::class)->fulfil($order, $order->lines->where('is_service', false)->mapWithKeys(fn ($l) => [$l->id => $l->quantity - $l->fulfilled_qty])->all(), $cashier, 'Delivered');
                if ($order->balance_due > 0) {
                    app(PaymentService::class)->receive($order, mt_rand(1, 3) === 1
                        ? ['payment_method_id' => $method(PaymentMethodCode::Cheque), 'amount' => $order->balance_due, 'cheque_bank' => ['BOC', 'HNB', 'Commercial Bank', 'Sampath'][mt_rand(0, 3)], 'cheque_number' => (string) mt_rand(100000, 999999), 'cheque_date' => $day->addDays(mt_rand(0, 10))->toDateString()]
                        : ['payment_method_id' => $method(PaymentMethodCode::BankTransfer), 'amount' => $order->balance_due, 'reference' => 'TRX'.mt_rand(100000, 999999)], $cashier);
                }
            } catch (Throwable) {
                // not enough stock that day: try again tomorrow
                continue;
            }
            unset($this->pending[$i]);
        }

        $sales = mt_rand(3, 6) + $busy;
        for ($s = 0; $s < $sales; $s++) {
            $at(10 + intdiv($s * 9, max(1, $sales)), mt_rand(0, 59));
            $variant = $variants[mt_rand(0, $variants->count() - 1)];
            $qty = mt_rand(1, 10) === 1 ? 2 : 1;
            $available = (int) StockLevel::query()->where('location_id', $location->id)->where('product_variant_id', $variant->id)->selectRaw('on_hand - reserved AS a')->value('a');
            if ($available < Quantity::fromUnits($qty)) {
                continue;
            }

            $customer = mt_rand(1, 3) === 1 ? null : $customers[mt_rand(0, count($customers) - 1)];
            $discount = mt_rand(1, 6) === 1 ? ['discount_type' => 'percent', 'discount_value' => 500] : [];
            $line = ['product_variant_id' => $variant->id, 'source' => 'stock', 'fulfil_location_id' => $location->id, 'quantity' => Quantity::fromUnits($qty), 'unit_price' => $variant->price, ...$discount];
            $total = (int) round($variant->price * $qty * ($discount ? 0.95 : 1));
            $isOrder = $customer && mt_rand(1, 5) === 1;

            try {
                if ($isOrder) {
                    $order = app(PosCheckoutService::class)->checkout(
                        ['customer_id' => $customer->id, 'location_id' => $location->id, 'kind' => 'order', 'fulfilment_type' => 'deliver', 'delivery_address' => $customer->defaultAddress(), 'lines' => [$line]],
                        [['payment_method_id' => $method(PaymentMethodCode::Card), 'amount' => intdiv($total, 2), 'reference' => 'ADV'.mt_rand(1000, 9999)]],
                        $cashier,
                    );
                    $this->pending[] = ['order' => $order->load('lines'), 'due' => $day->addDays(mt_rand(2, 5))->toDateString()];

                    continue;
                }

                $pick = mt_rand(1, 10);
                $payments = match (true) {
                    $pick <= 5 => [['payment_method_id' => $method(PaymentMethodCode::Cash), 'tendered' => (int) (ceil($total / 500000) * 500000)]],
                    $pick <= 8 => [['payment_method_id' => $method(PaymentMethodCode::Card), 'amount' => $total, 'reference' => 'APPR'.mt_rand(100000, 999999)]],
                    $pick === 9 => [['payment_method_id' => $method(PaymentMethodCode::LankaQr), 'amount' => $total, 'reference' => 'LQR'.mt_rand(100000, 999999)]],
                    default => [['payment_method_id' => $method(PaymentMethodCode::Card), 'amount' => intdiv($total, 2), 'reference' => 'APPR'.mt_rand(100000, 999999)], ['payment_method_id' => $method(PaymentMethodCode::Cash), 'tendered' => $total]],
                };
                $sale = app(PosCheckoutService::class)->checkout(['customer_id' => $customer?->id, 'location_id' => $location->id, 'kind' => 'cash_and_carry', 'lines' => [$line]], $payments, $cashier);

                // About one sale in fifteen comes back.
                if (mt_rand(1, 15) === 1) {
                    $at(17, 30);
                    app(ReturnService::class)->process($sale, [[
                        'sales_order_line_id' => $sale->lines->first()->id, 'quantity' => Quantity::fromUnits(1),
                        'condition' => mt_rand(1, 4) === 1 ? 'damaged' : 'resaleable',
                    ]], $sale->customer_id ? ReturnOutcome::CreditNote : ReturnOutcome::Refund, ['Colour did not match', 'Changed mind', 'Scratch on delivery', 'Wrong size'][mt_rand(0, 3)], $cashier, $method(PaymentMethodCode::Cash));
                }
            } catch (Throwable) {
                // a refused sale (e.g. stock reserved meanwhile) simply didn't happen
            }
        }

        // A quotation most days; some are converted later.
        if ($customers && mt_rand(1, 3) <= 2) {
            $at(12, 30);
            $variant = $variants[mt_rand(0, $variants->count() - 1)];
            try {
                $quote = app(QuotationService::class)->save(['customer_id' => $customers[mt_rand(0, count($customers) - 1)]->id, 'location_id' => $location->id, 'valid_until' => $day->addDays(14)->toDateString(), 'follow_up_date' => $day->addDays(3)->toDateString(),
                    'lines' => [['product_variant_id' => $variant->id, 'quantity' => Quantity::fromUnits(mt_rand(1, 4)), 'unit_price' => $variant->price]]], $cashier);
                app(QuotationService::class)->send($quote, $cashier, emailCustomer: false);
            } catch (Throwable) {
            }
        }

        // Petty cash and a drop to the safe.
        $at(15, 0);
        $category = fn (string $name) => CashMovementCategory::query()->where('name', $name)->value('id');
        try {
            if (mt_rand(1, 3) === 1) {
                app(ShiftService::class)->movement($shift, $cashier, CashMovementType::PayOut, mt_rand(5, 30) * 10000, $category('Transport / fuel'), 'Three-wheeler for a small delivery');
            }
            $drawer = CashCalculator::expected($shift->fresh())['expected'];
            if ($drawer > 3000000) {
                app(ShiftService::class)->movement($shift, $cashier, CashMovementType::CashDrop, intdiv($drawer - 1000000, 100000) * 100000, $category('Safe drop'), 'Afternoon drop to the safe');
            }
        } catch (Throwable) {
        }

        // Close: most counts are right; now and then a little short, rarely a lot (a manager approves).
        $at(19, 15);
        $expected = CashCalculator::expected($shift->fresh())['expected'];
        $roll = mt_rand(1, 20);
        $counted = $expected + match (true) {
            $roll === 1 => -mt_rand(6, 20) * 10000,
            $roll <= 4 => -mt_rand(1, 30) * 1000,
            default => 0,
        };
        $closed = app(ShiftService::class)->close($shift, $cashier, null, max(0, $counted), null, $roll === 1 ? 'Could not find the difference' : null);

        if ($closed->status === ShiftStatus::Closed) {
            $at(19, 45);
            app(ShiftService::class)->approve($closed, $manager, 'Checked the slips; short recorded');
        }
    }

    /**
     * Mondays: the warehouse replenishes the main showroom; pending cheques go to the bank and one
     * in a while bounces.
     *
     * @param  array<string, array{0: Location, 1: User}>  $showrooms
     */
    private function weekly(CarbonImmutable $day, Location $warehouse, array $showrooms, User $manager, $variants): void
    {
        [$main] = $showrooms['MS'];
        Carbon::setTestNow($day->setTime(8, 30));
        $transfers = app(TransferService::class);

        try {
            $lines = $variants->shuffle()->take(3)->map(fn ($v) => ['product_variant_id' => $v->id, 'requested_qty' => Quantity::fromUnits(3)])->values()->all();
            $transfer = $transfers->create(['from_location_id' => $warehouse->id, 'to_location_id' => $main->id, 'note' => 'Weekly replenishment', 'lines' => $lines], $this->admin);
            $transfers->dispatch($transfer, $this->admin, null, 'Company lorry');
            Carbon::setTestNow($day->setTime(14, 0));
            $transfers->receive($transfer->fresh(), $manager);
        } catch (Throwable) {
        }

        Carbon::setTestNow($day->setTime(16, 0));
        foreach ($showrooms as [$location]) {
            $cheques = Payment::query()->where('location_id', $location->id)->where('cheque_status', ChequeStatus::Pending->value)->whereDate('cheque_date', '<=', $day->toDateString())->pluck('id')->all();
            $safe = app(BankDepositService::class)->safeBalance($location->id);
            if ($cheques === [] && $safe <= 0) {
                continue;
            }
            try {
                app(BankDepositService::class)->record($location->id, [
                    'source' => 'safe', 'cash_amount' => max(0, $safe), 'cheque_ids' => $cheques, 'bank_account' => "BOC {$location->code} current account",
                    'slip_reference' => 'DS'.$day->format('ymd').$location->code, 'deposited_on' => $day->toDateString(),
                ], $manager);

                foreach (Payment::query()->whereIn('id', $cheques)->get() as $cheque) {
                    app(PaymentService::class)->setChequeStatus($cheque, mt_rand(1, 12) === 1 ? ChequeStatus::Bounced : ChequeStatus::Cleared, $manager);
                }
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return array<int, Customer>
     */
    private function customers(int $count): array
    {
        $districts = [['Colombo', 'Colombo 05'], ['Gampaha', 'Negombo'], ['Kandy', 'Peradeniya'], ['Kandy', 'Katugastota'], ['Kalutara', 'Panadura']];
        $out = [];

        for ($i = 1; $i <= $count; $i++) {
            [$district, $city] = $districts[$i % count($districts)];
            $out[] = Customer::query()->firstOrCreate(['phone' => sprintf('+9477%07d', 5000000 + $i)], [
                'name' => self::FIRST_NAMES[$i % count(self::FIRST_NAMES)].' '.self::LAST_NAMES[$i % count(self::LAST_NAMES)],
                'addresses' => [['label' => 'Home', 'line1' => (10 + $i).' Temple Road', 'city' => $city, 'district' => $district, 'is_default' => true]],
            ]);
        }

        return $out;
    }

    /**
     * Enough stock to trade for the whole history.
     *
     * @param  array<int, Location>  $locations
     */
    private function stockUp($variants, array $locations): void
    {
        $entries = [];
        foreach ($locations as $location) {
            foreach ($variants as $variant) {
                $entries[] = [
                    'product_variant_id' => $variant->id, 'location_id' => $location->id, 'type' => MovementType::Opening,
                    'quantity' => Quantity::fromUnits($location->code === 'HO' ? 80 : 25), 'unit_cost' => (int) ($variant->average_cost ?: intdiv($variant->price * 6, 10)),
                    'user_id' => $this->admin->id, 'note' => 'Demo history stock',
                ];
            }
        }

        app(StockService::class)->moveMany($entries);
    }
}
