<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryAction;
use App\Models\DeliveryLine;
use App\Models\DeliveryRun;
use App\Models\DeliveryStop;
use App\Models\DeliveryVehicle;
use App\Models\DeliveryZone;
use App\Models\DriverCollection;
use App\Models\InstallationJob;
use App\Models\Location;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Shift;
use App\Models\User;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use App\Services\Sms\SmsService;
use App\Support\BasisPoints;
use App\Support\Counter;
use App\Support\ManagerApproval;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\SyncVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeliveryService
{
    public function __construct(
        private readonly SalesOrderService $salesOrders,
        private readonly ReservationService $reservations,
        private readonly StockService $stock,
        private readonly PaymentService $payments,
        private readonly SmsService $sms,
    ) {}

    public function fee(DeliveryZone $zone, int $orderValue): int
    {
        if ($zone->fee_rule === 'free_above' && $zone->free_above_cents !== null && $orderValue >= $zone->free_above_cents) {
            return 0;
        }
        if ($zone->fee_rule === 'order_value') {
            return BasisPoints::of($orderValue, (int) $zone->fee_cents);
        }

        return $zone->fee_cents;
    }

    public function createForOrder(SalesOrder $order, array $data, User $actor): Delivery
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = SalesOrder::query()->with(['lines.variant.product', 'customer'])->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::InProduction, SalesOrderStatus::Ready, SalesOrderStatus::PartiallyFulfilled], true)
                || $order->fulfilment_type?->value !== 'deliver') {
                throw new InvalidArgumentException('Only confirmed delivery orders can be scheduled.');
            }
            if (Delivery::query()->where('sales_order_id', $order->id)->whereNotIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Delivered->value])->exists()) {
                throw new InvalidArgumentException('This order already has an active delivery.');
            }
            $zone = ! empty($data['delivery_zone_id']) ? DeliveryZone::query()->where('is_active', true)->findOrFail($data['delivery_zone_id']) : null;
            if ($zone && $order->delivery_zone_id && (int) $order->delivery_zone_id !== (int) $zone->id) {
                throw new InvalidArgumentException('This order already has a delivery zone and fee.');
            }
            $date = CarbonImmutable::parse($data['scheduled_date'])->startOfDay();
            if ($zone && $date->lt(today()->addDays($zone->lead_days))) {
                throw new InvalidArgumentException("This zone needs {$zone->lead_days} lead day(s); earliest delivery is ".today()->addDays($zone->lead_days)->toDateString().'.');
            }
            $address = trim((string) ($data['address'] ?? collect($order->delivery_address ?? [])->filter()->implode(', ')));
            $phone = trim((string) ($data['contact_phone'] ?? $order->customer?->phone ?? ''));
            if ($address === '' || $phone === '') {
                throw new InvalidArgumentException('A delivery address and contact phone are required.');
            }
            if ($zone && ! $order->delivery_zone_id) {
                $fee = $this->fee($zone, (int) $order->grand_total);
                $oldGrandTotal = (int) $order->grand_total;
                $order->delivery_zone_id = $zone->id;
                $order->delivery_fee_cents = $fee;
                $order->delivery_charge += $fee;
                $unit = (int) CompanySetting::current()->cash_rounding_unit;
                $unrounded = (int) $order->grand_total - (int) $order->rounding_cents + $fee;
                $order->grand_total = $unit > 1 && $unrounded > 0 ? intdiv($unrounded + intdiv($unit, 2), $unit) * $unit : $unrounded;
                $order->rounding_cents = $order->grand_total - $unrounded;
                $order->save();
                if ($order->customer_id && $order->grand_total !== $oldGrandTotal) {
                    $customer = Customer::query()->lockForUpdate()->find($order->customer_id);
                    if ($customer) {
                        $customer->total_spent += $order->grand_total - $oldGrandTotal;
                        $customer->save();
                    }
                }
                $this->payments->refresh($order);
            }
            $delivery = Delivery::query()->create([
                'number' => $this->nextNumber($order->location_id), 'location_id' => $order->location_id,
                'sales_order_id' => $order->id, 'delivery_zone_id' => $zone?->id, 'address' => $address,
                'contact_name' => $data['contact_name'] ?? $order->customer?->name, 'contact_phone' => $phone,
                'scheduled_date' => $date->toDateString(), 'time_window' => $data['time_window'] ?? null,
                'status' => DeliveryStatus::Scheduled, 'requires_installation' => (bool) ($data['requires_installation'] ?? false),
                'balance_to_collect' => $order->balance_due,
            ]);
            foreach ($order->lines as $line) {
                if ($line->is_service || ! $line->variant?->product?->requires_delivery) {
                    continue;
                }
                $quantity = $line->remainingQty();
                if ($quantity > 0) {
                    DeliveryLine::query()->create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $line->id, 'product_variant_id' => $line->product_variant_id, 'quantity' => $quantity]);
                }
            }
            if (! $delivery->lines()->exists()) {
                throw new InvalidArgumentException('This order has no undelivered products that require delivery.');
            }
            if ($delivery->requires_installation) {
                InstallationJob::query()->create(['delivery_id' => $delivery->id, 'sales_order_id' => $order->id, 'location_id' => $order->location_id, 'scheduled_date' => $date, 'status' => 'scheduled', 'checklist' => []]);
            }
            SyncVersion::bump($delivery);
            AuditLog::record('delivery.scheduled', $delivery, "Scheduled {$delivery->number} for {$date->toDateString()}", null, ['order_id' => $order->id, 'zone_id' => $zone?->id], $actor);
            $this->notify($delivery, 'delivery_scheduled', 'delivery:'.$delivery->id.':scheduled');

            return $delivery->fresh(['lines']);
        });
    }

    public function createRun(array $data, User $actor, bool $overrideCapacity = false): DeliveryRun
    {
        return DB::transaction(function () use ($data, $actor, $overrideCapacity) {
            $vehicle = DeliveryVehicle::query()->where('is_active', true)->findOrFail($data['vehicle_id']);
            if ($vehicle->location_id && (int) $vehicle->location_id !== (int) $data['location_id']) {
                throw new InvalidArgumentException('Choose a vehicle assigned to the selected location.');
            }
            $driver = User::query()->active()->findOrFail($data['driver_id']);
            if ($driver->role !== Role::Driver || ! $driver->worksAt((int) $data['location_id'])) {
                throw new InvalidArgumentException('Choose an active driver assigned to this location.');
            }
            $date = CarbonImmutable::parse($data['run_date'])->toDateString();
            $ids = array_values(array_unique(array_map('intval', $data['delivery_ids'] ?? [])));
            if ($ids === []) {
                throw new InvalidArgumentException('Add at least one delivery stop.');
            }
            $deliveries = Delivery::query()->where('location_id', $data['location_id'])->whereIn('id', $ids)->whereDate('scheduled_date', $date)->whereIn('status', [DeliveryStatus::Scheduled->value, DeliveryStatus::Rescheduled->value])->with('lines.variant.product')->get();
            if ($deliveries->count() !== count($ids)) {
                throw new InvalidArgumentException('One or more deliveries are unavailable for this location or date.');
            }
            $warnings = $this->capacityWarnings($vehicle, $date, $deliveries);
            if ($warnings !== [] && ! $overrideCapacity) {
                throw new InvalidArgumentException(implode(' ', $warnings).' Confirm the manager capacity override to continue.');
            }
            $location = Location::query()->findOrFail($data['location_id']);
            $run = DeliveryRun::query()->create([
                'number' => sprintf('RUN-%s-%06d', $location->code, Counter::next('delivery_run', 'location:'.$location->id)),
                'location_id' => $location->id, 'loading_location_id' => $data['loading_location_id'] ?? $location->id,
                'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'run_date' => $date,
                'helper_names' => filled($data['helper_names'] ?? null) ? trim($data['helper_names']) : null,
                'start_odometer' => null, 'status' => 'planned', 'created_by' => $actor->id,
            ]);
            foreach ($deliveries->values() as $i => $delivery) {
                DeliveryStop::query()->create(['delivery_run_id' => $run->id, 'delivery_id' => $delivery->id, 'sort_order' => $i + 1]);
                $delivery->update(['status' => DeliveryStatus::Assigned]);
                SyncVersion::bump($delivery);
            }
            AuditLog::record('delivery.run_created', $run, "Planned {$run->number} with {$deliveries->count()} stops", null, ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'capacity_override' => $warnings !== []], $actor);

            return $run->fresh(['stops.delivery']);
        });
    }

    /** @return array<int, string> */
    public function capacityWarnings(DeliveryVehicle $vehicle, string $date, iterable $adding = [], ?int $excludeRunId = null): array
    {
        $existing = DeliveryStop::query()->whereHas('run', fn ($q) => $q->where('vehicle_id', $vehicle->id)->whereDate('run_date', $date)->where('status', '!=', 'closed')->when($excludeRunId, fn ($q) => $q->where('id', '!=', $excludeRunId)))->with('delivery.lines.variant.product')->get()->pluck('delivery');
        $all = collect($adding)->concat($existing)->unique('id');
        $items = 0;
        $volume = 0;
        foreach ($all as $delivery) {
            foreach ($delivery->lines as $line) {
                $pieces = max(1, intdiv($line->quantity, Quantity::SCALE));
                $items += $pieces;
                $product = $line->variant?->product;
                $volume += (int) ($product?->width_mm ?? 0) * (int) ($product?->depth_mm ?? 0) * (int) ($product?->height_mm ?? 0) * $pieces;
            }
        }
        $warnings = [];
        if ($vehicle->capacity_items && $items > $vehicle->capacity_items) {
            $warnings[] = "Run contains {$items} items but {$vehicle->name} capacity is {$vehicle->capacity_items}.";
        }
        if ($vehicle->capacity_cubic_mm && $volume > $vehicle->capacity_cubic_mm) {
            $warnings[] = "Run volume exceeds {$vehicle->name}'s configured cubic capacity.";
        }

        return $warnings;
    }

    public function loadRun(DeliveryRun $run, int $startOdometer, User $actor): DeliveryRun
    {
        return DB::transaction(function () use ($run, $startOdometer, $actor) {
            $run = DeliveryRun::query()->with('stops.delivery.lines')->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'planned') {
                throw new InvalidArgumentException('Only a planned delivery run can be loaded.');
            }
            $vehicle = DeliveryVehicle::query()->lockForUpdate()->findOrFail($run->vehicle_id);
            if ($startOdometer < $vehicle->odometer) {
                throw new InvalidArgumentException('Starting odometer cannot be lower than the last recorded odometer.');
            }
            foreach ($run->stops as $stop) {
                $delivery = $stop->delivery;
                if (! in_array($delivery->status, [DeliveryStatus::Assigned, DeliveryStatus::Rescheduled], true)) {
                    throw new InvalidArgumentException("{$delivery->number} is not ready to load.");
                }
                $lines = SalesOrderLine::query()->whereIn('id', $delivery->lines->pluck('sales_order_line_id'))
                    ->where(function ($query) use ($delivery, $run) {
                        $query->where('fulfil_location_id', $run->loading_location_id);
                        if ((int) $delivery->location_id === (int) $run->loading_location_id) {
                            $query->orWhereNull('fulfil_location_id');
                        }
                    })
                    ->count();
                if ($lines !== $delivery->lines->count()) {
                    throw new InvalidArgumentException("{$delivery->number} stock is reserved at another location; load from that location or move the reservation first.");
                }
                $qty = $delivery->lines->mapWithKeys(fn (DeliveryLine $line) => [$line->sales_order_line_id => $line->quantity])->all();
                $order = SalesOrder::query()->findOrFail($delivery->sales_order_id);
                $this->salesOrders->fulfil($order, $qty, $actor, "Loaded on {$run->number} for delivery");
                $stop->update(['loaded_at' => now()]);
                $delivery->update(['status' => DeliveryStatus::Loaded]);
                SyncVersion::bump($delivery);
            }
            $run->update(['start_odometer' => $startOdometer, 'status' => 'loaded']);
            AuditLog::record('delivery.run_loaded', $run, "Loaded {$run->number} at odometer {$startOdometer}", null, ['start_odometer' => $startOdometer], $actor);

            return $run->fresh(['stops.delivery']);
        });
    }

    public function startRun(DeliveryRun $run, User $driver): DeliveryRun
    {
        return DB::transaction(function () use ($run, $driver) {
            $run = DeliveryRun::query()->with('stops.delivery.salesOrder.customer')->lockForUpdate()->findOrFail($run->id);
            abort_unless($driver->role === Role::Driver && $run->driver_id === $driver->id, 403);
            if ($run->status !== 'loaded') {
                throw new InvalidArgumentException('Load the run before starting the route.');
            }
            $run->update(['status' => 'out_for_delivery']);
            foreach ($run->stops as $stop) {
                if ($stop->delivery->status === DeliveryStatus::Loaded) {
                    $stop->delivery->update(['status' => DeliveryStatus::OutForDelivery]);
                    $this->notify($stop->delivery, 'delivery_out_for_delivery', 'delivery:'.$stop->delivery->id.':out');
                    SyncVersion::bump($stop->delivery);
                }
            }

            return $run->fresh(['stops.delivery']);
        });
    }

    public function arrive(Delivery $delivery, User $driver): Delivery
    {
        $this->assertDriverOwns($delivery, $driver);
        if (! in_array($delivery->status, [DeliveryStatus::OutForDelivery, DeliveryStatus::Loaded], true)) {
            throw new InvalidArgumentException('This delivery is not out for delivery.');
        }
        $delivery->forceFill(['arrived_at' => $delivery->arrived_at ?? now()])->save();
        SyncVersion::bump($delivery);

        return $delivery->fresh();
    }

    public function markDelivered(Delivery $delivery, array $proof, User $driver): Delivery
    {
        return DB::transaction(function () use ($delivery, $proof, $driver) {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->assertDriverOwns($delivery, $driver);
            if ($delivery->status === DeliveryStatus::Delivered) {
                return $delivery;
            }
            if (! in_array($delivery->status, [DeliveryStatus::OutForDelivery, DeliveryStatus::Loaded], true)) {
                throw new InvalidArgumentException('This delivery is not ready to complete.');
            }
            $receiver = trim((string) ($proof['receiver_name'] ?? ''));
            if ($receiver === '' || empty($proof['signature_path'])) {
                throw new InvalidArgumentException('Receiver name and signature are required as proof.');
            }
            $delivery->update(['status' => DeliveryStatus::Delivered, 'receiver_name' => $receiver, 'signature_path' => $proof['signature_path'], 'proof_photos' => $proof['photos'] ?? [], 'delivered_at' => now()]);
            SyncVersion::bump($delivery);
            $this->notify($delivery, 'delivery_delivered', 'delivery:'.$delivery->id.':delivered');
            AuditLog::record('delivery.delivered', $delivery, "Delivered {$delivery->number}, received by {$receiver}", null, ['photos' => count($proof['photos'] ?? [])], $driver);

            return $delivery->fresh();
        });
    }

    public function fail(Delivery $delivery, string $reason, User $driver): Delivery
    {
        return DB::transaction(function () use ($delivery, $reason, $driver) {
            $delivery = Delivery::query()->with('lines')->lockForUpdate()->findOrFail($delivery->id);
            $this->assertDriverOwns($delivery, $driver);
            if (! in_array($delivery->status, [DeliveryStatus::OutForDelivery, DeliveryStatus::Loaded], true)) {
                throw new InvalidArgumentException('This delivery cannot be marked failed in its current status.');
            }
            if (trim($reason) === '') {
                throw new InvalidArgumentException('A failure reason is required.');
            }
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($delivery->sales_order_id);
            foreach ($delivery->lines as $line) {
                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                $this->stock->move(['product_variant_id' => $line->product_variant_id, 'location_id' => $orderLine->fulfil_location_id ?: $order->location_id, 'type' => MovementType::SaleReturn, 'quantity' => $line->quantity, 'reference' => $delivery, 'user_id' => $driver->id, 'note' => "Failed delivery {$delivery->number}: {$reason}"]);
                $orderLine->fulfilled_qty = max(0, $orderLine->fulfilled_qty - $line->quantity);
                $orderLine->reserved_qty += $line->quantity;
                $orderLine->save();
                $this->reservations->reserve((int) $line->product_variant_id, $orderLine->fulfil_location_id ?: $order->location_id, $line->quantity, $orderLine, null, "Rescheduled {$delivery->number}", $driver->id);
            }
            $order->load('lines');
            $hasFulfilledItems = $order->lines->contains(fn (SalesOrderLine $line) => ! $line->is_service && $line->fulfilled_qty > 0);
            $order->forceFill(['status' => $hasFulfilledItems ? SalesOrderStatus::PartiallyFulfilled : SalesOrderStatus::Confirmed])->save();
            $delivery->update(['status' => DeliveryStatus::Failed, 'failure_reason' => trim($reason)]);
            SyncVersion::bump($delivery);
            AuditLog::record('delivery.failed', $delivery, "Failed {$delivery->number}: {$reason}", null, ['reason' => $reason], $driver);

            return $delivery->fresh();
        });
    }

    public function reschedule(Delivery $delivery, string $date, ?string $window, User $actor): Delivery
    {
        $delivery->loadMissing('zone');
        $date = CarbonImmutable::parse($date)->toDateString();
        if ($delivery->status !== DeliveryStatus::Failed) {
            throw new InvalidArgumentException('Only a failed delivery can be rescheduled.');
        }
        if ($delivery->zone && CarbonImmutable::parse($date)->lt(today()->addDays($delivery->zone->lead_days))) {
            throw new InvalidArgumentException('The selected date does not meet this zone’s lead time.');
        }
        $delivery->update(['scheduled_date' => $date, 'time_window' => $window, 'status' => DeliveryStatus::Rescheduled, 'failure_reason' => null]);
        SyncVersion::bump($delivery);

        return $delivery->fresh();
    }

    public function collect(Delivery $delivery, array $data, User $driver)
    {
        $this->assertDriverOwns($delivery, $driver);
        if (! in_array($delivery->status, [DeliveryStatus::Loaded, DeliveryStatus::OutForDelivery], true)) {
            throw new InvalidArgumentException('Payments can only be collected for a loaded delivery.');
        }

        return $this->payments->receiveFromDriver($delivery, $data, $driver);
    }

    public function handOver(array $collectionIds, int $shiftId, int $countedCash, User $cashier, ?int $approverId = null, ?string $pin = null): int
    {
        return DB::transaction(function () use ($collectionIds, $shiftId, $countedCash, $cashier, $approverId, $pin) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shiftId);
            if (! $shift->isOpen() || $shift->user_id !== $cashier->id) {
                throw new InvalidArgumentException('Choose your own open cashier shift.');
            }
            $collections = DriverCollection::query()->with(['delivery', 'payment'])->whereIn('id', $collectionIds)->where('status', 'held_by_driver')->lockForUpdate()->get();
            if ($collections->isEmpty()) {
                throw new InvalidArgumentException('Select at least one collection held by a driver.');
            }
            if ($collections->contains(fn ($c) => $c->delivery->location_id !== $shift->location_id)) {
                throw new InvalidArgumentException('All selected collections must belong to this shift location.');
            }
            $cashTotal = (int) $collections->where('method', 'cash')->sum('amount');
            if ($countedCash !== $cashTotal && ! $cashier->role->canOverride()) {
                ManagerApproval::verify($cashier, $shift->location_id, $approverId, $pin, 'delivery.cash_handover', $shift, 'accepting a delivery cash hand-over with a discrepancy');
            }
            foreach ($collections as $collection) {
                $collection->update(['status' => 'handed_over', 'shift_id' => $shift->id, 'handed_over_by' => $cashier->id, 'handed_over_at' => now()]);
                $collection->payment->update(['collection_status' => 'handed_over', 'shift_id' => $shift->id]);
            }
            AuditLog::record('delivery.cash_handover', $shift, 'Accepted driver collections totalling '.Money::fromCents($cashTotal)->format(), null, ['collection_ids' => $collections->modelKeys(), 'counted_cash' => $countedCash, 'expected_cash' => $cashTotal], $cashier);

            return $cashTotal;
        });
    }

    public function closeRun(DeliveryRun $run, int $endOdometer, User $actor): DeliveryRun
    {
        return DB::transaction(function () use ($run, $endOdometer, $actor) {
            $run = DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['loaded', 'out_for_delivery'], true) || $run->start_odometer === null) {
                throw new InvalidArgumentException('Only a started run can be closed.');
            }
            if ($endOdometer < $run->start_odometer) {
                throw new InvalidArgumentException('Ending odometer must be at least the starting odometer.');
            }
            $vehicle = DeliveryVehicle::query()->lockForUpdate()->findOrFail($run->vehicle_id);
            $vehicle->update(['odometer' => $endOdometer]);
            $run->update(['end_odometer' => $endOdometer, 'status' => 'closed']);
            AuditLog::record('delivery.run_closed', $run, "Closed {$run->number} after ".($endOdometer - $run->start_odometer).' km', null, ['start_odometer' => $run->start_odometer, 'end_odometer' => $endOdometer], $actor);

            return $run->fresh();
        });
    }

    public function nextNumber(int $locationId): string
    {
        $code = Location::query()->whereKey($locationId)->value('code');

        return sprintf('%s-%s-%06d', CompanySetting::current()->prefixFor(DocumentType::Delivery), $code, Counter::next(DocumentType::Delivery->value, "location:{$locationId}"));
    }

    public function action(Delivery $delivery, string $actionId, string $action, array $data, User $driver): array
    {
        $this->assertDriverOwns($delivery, $driver);

        return DB::transaction(function () use ($delivery, $actionId, $action, $data, $driver) {
            // Serialize retries for one delivery before checking the idempotency key.
            Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($saved = DeliveryAction::query()->where('action_id', $actionId)->first()) {
                abort_unless($saved->user_id === $driver->id && $saved->delivery_id === $delivery->id, 409);

                return $saved->result ?? ['ok' => true, 'duplicate' => true];
            }
            $result = match ($action) {
                'arrived' => ['delivery' => $this->arrive($delivery, $driver)->only(['id', 'status', 'arrived_at'])],
                'delivered' => ['delivery' => $this->markDelivered($delivery, $data, $driver)->only(['id', 'status', 'delivered_at'])],
                'failed' => ['delivery' => $this->fail($delivery, (string) ($data['reason'] ?? ''), $driver)->only(['id', 'status', 'failure_reason'])],
                'collect' => ['collection_id' => $this->collect($delivery, $data, $driver)->id],
                default => throw new InvalidArgumentException('Unknown driver delivery action.'),
            };
            DeliveryAction::query()->create(['action_id' => $actionId, 'delivery_id' => $delivery->id, 'user_id' => $driver->id, 'action' => $action, 'result' => $result]);

            return $result;
        });
    }

    public function completeInstallation(InstallationJob $job, array $data, User $actor): InstallationJob
    {
        return DB::transaction(function () use ($job, $data, $actor) {
            $job = InstallationJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless($actor->worksAt($job->location_id) && $actor->role->canOverride(), 403);
            if ($job->status === 'completed') {
                return $job;
            }
            $job->update(['checklist' => array_values($data['checklist'] ?? []), 'completion_photos' => array_values($data['photos'] ?? []), 'status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id]);
            AuditLog::record('installation.completed', $job, "Completed installation for order {$job->sales_order_id}", null, ['checklist' => $job->checklist, 'photos' => count($job->completion_photos ?? [])], $actor);

            return $job->fresh();
        });
    }

    private function assertDriverOwns(Delivery $delivery, User $driver): void
    {
        abort_unless($driver->role === Role::Driver && DeliveryStop::query()->where('delivery_id', $delivery->id)->whereHas('run', fn ($q) => $q->where('driver_id', $driver->id))->exists(), 403);
    }

    private function notify(Delivery $delivery, string $template, string $key): void
    {
        $order = $delivery->salesOrder()->with('customer')->first();
        $this->sms->queue($template, $order?->customer, ['customer' => $order?->customer?->name, 'order' => $order?->number, 'date' => $delivery->scheduled_date?->format('j M'), 'window' => $delivery->time_window, 'delivery' => $delivery->number], $delivery, $key, $delivery->location_id);
    }
}
