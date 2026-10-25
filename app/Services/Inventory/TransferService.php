<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Enums\StockUnitStatus;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SalesOrderLine;
use App\Models\StockReservation;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\User;
use App\Support\Counter;
use App\Support\SyncVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inter-location stock transfers:
 * draft → requested → dispatched (in transit; moves stock out of source as transfer_out)
 * → received | partially_received (moves received qty into destination as transfer_in)
 * → closed (short receipts resolved by a manager: write_off as loss or return_to_source).
 */
class TransferService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * Create a new transfer in draft or requested status.
     *
     * @param  array{
     *     from_location_id: int,
     *     to_location_id: int,
     *     status?: TransferStatus|string,
     *     vehicle_note?: string|null,
     *     note?: string|null,
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         requested_qty: int,
     *         stock_unit_id?: int|null,
     *         note?: string|null,
     *     }>,
     * }  $data
     */
    public function create(array $data, User $actor): Transfer
    {
        $fromId = (int) $data['from_location_id'];
        $toId = (int) $data['to_location_id'];

        if ($fromId === $toId) {
            throw new InvalidArgumentException('Source and destination locations must be different.');
        }

        if (empty($data['lines'])) {
            throw new InvalidArgumentException('A transfer needs at least one line.');
        }

        $status = isset($data['status'])
            ? ($data['status'] instanceof TransferStatus ? $data['status'] : TransferStatus::from((string) $data['status']))
            : TransferStatus::Requested;

        if (! in_array($status, [TransferStatus::Draft, TransferStatus::Requested], true)) {
            $status = TransferStatus::Requested;
        }

        return DB::transaction(function () use ($data, $fromId, $toId, $status, $actor) {
            $from = Location::query()->findOrFail($fromId);
            Location::query()->findOrFail($toId);

            $prefix = CompanySetting::current()->prefixFor(DocumentType::Transfer);
            $seq = Counter::next(DocumentType::Transfer->value, "location:{$from->id}");
            $number = sprintf('%s-%s-%06d', $prefix, $from->code, $seq);

            $transfer = Transfer::query()->create([
                'number' => $number,
                'from_location_id' => $fromId,
                'to_location_id' => $toId,
                'status' => $status,
                'vehicle_note' => isset($data['vehicle_note']) && trim((string) $data['vehicle_note']) !== '' ? trim((string) $data['vehicle_note']) : null,
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                'requested_by' => $actor->id,
                'requested_at' => $status === TransferStatus::Requested ? now() : null,
                'sync_version' => 0,
            ]);

            $variantIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_variant_id'], $data['lines'])));
            $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->get()->keyBy('id');

            foreach ($data['lines'] as $line) {
                $variantId = (int) $line['product_variant_id'];
                $variant = $variants->get($variantId);
                if (! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                $qty = (int) $line['requested_qty'];
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Requested quantity for {$variant->sku} must be positive.");
                }

                $transfer->lines()->create([
                    'product_variant_id' => $variant->id,
                    'stock_unit_id' => isset($line['stock_unit_id']) ? (int) $line['stock_unit_id'] : null,
                    'sales_order_line_id' => isset($line['sales_order_line_id']) ? (int) $line['sales_order_line_id'] : null,
                    'requested_qty' => $qty,
                    'sent_qty' => 0,
                    'received_qty' => 0,
                    'unit_cost' => $variant->average_cost,
                    'note' => isset($line['note']) && trim((string) $line['note']) !== '' ? trim((string) $line['note']) : null,
                ]);
            }

            $transfer->update(['sync_version' => SyncVersion::next($fromId)]);

            AuditLog::record('transfer.created', $transfer, "Transfer {$transfer->number} created ({$status->value})", null, ['from_location_id' => $fromId, 'to_location_id' => $toId], $actor);

            return $transfer->load(['lines.variant.product', 'fromLocation', 'toLocation']);
        });
    }

    public function submitRequest(Transfer $transfer, User $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== TransferStatus::Draft) {
                throw new InvalidArgumentException('Only draft transfers can be requested.');
            }

            $transfer->update([
                'status' => TransferStatus::Requested,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'sync_version' => SyncVersion::next($transfer->from_location_id),
            ]);

            return $transfer;
        });
    }

    /**
     * Dispatch a transfer from its source location: moves sent quantities out of source
     * (type transfer_out) and puts the transfer into the in-transit (dispatched) state.
     *
     * @param  array<int, int>|null  $sentQuantitiesByLineId  line_id => milli-units sent (null = send requested_qty for all lines)
     */
    public function dispatch(Transfer $transfer, User $actor, ?array $sentQuantitiesByLineId = null, ?string $vehicleNote = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor, $sentQuantitiesByLineId, $vehicleNote) {
            $transfer = Transfer::query()->with('lines.variant')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Requested], true)) {
                throw new InvalidArgumentException("Transfer {$transfer->number} cannot be dispatched from status [{$transfer->status->value}].");
            }

            $entries = [];
            $anySent = false;

            foreach ($transfer->lines as $line) {
                $sentQty = $sentQuantitiesByLineId !== null && array_key_exists($line->id, $sentQuantitiesByLineId)
                    ? (int) $sentQuantitiesByLineId[$line->id]
                    : $line->requested_qty;

                if ($sentQty < 0 || $sentQty > $line->requested_qty) {
                    throw new InvalidArgumentException("Sent quantity for {$line->variant->sku} must be between 0 and requested quantity.");
                }

                $unitCost = $line->variant->average_cost ?: $line->unit_cost;
                $line->update([
                    'sent_qty' => $sentQty,
                    'unit_cost' => $unitCost,
                ]);

                if ($sentQty > 0) {
                    $anySent = true;

                    // Reserved for a customer's order (e.g. a finished custom piece): the hold moves with it.
                    if ($line->sales_order_line_id) {
                        $this->releaseForOrderLine($line, $transfer->from_location_id, $sentQty);
                    }

                    if ($line->stock_unit_id) {
                        $unit = StockUnit::query()->whereKey($line->stock_unit_id)->lockForUpdate()->firstOrFail();
                        $unit->update([
                            'status' => StockUnitStatus::InTransit,
                            'location_id' => null,
                        ]);
                    }

                    $entries[] = [
                        'product_variant_id' => $line->product_variant_id,
                        'location_id' => $transfer->from_location_id,
                        'type' => MovementType::TransferOut,
                        'quantity' => -$sentQty,
                        'unit_cost' => $unitCost,
                        'reference' => $transfer,
                        'stock_unit_id' => $line->stock_unit_id,
                        'user_id' => $actor->id,
                        'note' => "Transfer {$transfer->number} dispatched",
                    ];
                }
            }

            if (! $anySent) {
                throw new InvalidArgumentException('At least one line must have a positive sent quantity to dispatch.');
            }

            $this->stock->moveMany($entries);

            $transfer->update([
                'status' => TransferStatus::Dispatched,
                'dispatched_by' => $actor->id,
                'dispatched_at' => now(),
                'vehicle_note' => $vehicleNote !== null ? (trim($vehicleNote) ?: null) : $transfer->vehicle_note,
                'sync_version' => SyncVersion::current($transfer->from_location_id),
            ]);

            AuditLog::record('transfer.dispatched', $transfer, "Transfer {$transfer->number} dispatched", null, ['dispatched_by' => $actor->id], $actor);

            return $transfer->fresh(['lines.variant.product', 'fromLocation', 'toLocation']);
        });
    }

    /**
     * Receive a dispatched transfer at the destination location.
     * If all lines receive their full sent_qty, status becomes received.
     * If any line receives less than sent_qty, status becomes partially_received (needs manager resolution).
     *
     * @param  array<int, array{received_qty: int, discrepancy_reason?: string|null}|int>|null  $receivedByLineId
     */
    public function receive(Transfer $transfer, User $actor, ?array $receivedByLineId = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor, $receivedByLineId) {
            $transfer = Transfer::query()->with('lines.variant')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== TransferStatus::Dispatched) {
                throw new InvalidArgumentException("Only dispatched transfers can be received (current: {$transfer->status->value}).");
            }

            $entries = [];
            $hasDiscrepancy = false;

            foreach ($transfer->lines as $line) {
                $raw = $receivedByLineId !== null && array_key_exists($line->id, $receivedByLineId)
                    ? $receivedByLineId[$line->id]
                    : $line->sent_qty;

                $receivedQty = is_array($raw) ? (int) ($raw['received_qty'] ?? 0) : (int) $raw;
                $reason = is_array($raw) && isset($raw['discrepancy_reason']) ? trim((string) $raw['discrepancy_reason']) : null;

                if ($receivedQty < 0 || $receivedQty > $line->sent_qty) {
                    throw new InvalidArgumentException("Received quantity for {$line->variant->sku} must be between 0 and sent quantity.");
                }

                if ($receivedQty < $line->sent_qty) {
                    $hasDiscrepancy = true;
                    if (blank($reason)) {
                        throw new InvalidArgumentException("Enter a discrepancy reason for {$line->variant->sku} (sent {$line->sent_qty}, received {$receivedQty}).");
                    }
                }

                $line->update([
                    'received_qty' => $receivedQty,
                    'discrepancy_reason' => $receivedQty < $line->sent_qty ? $reason : null,
                ]);

                if ($receivedQty > 0) {
                    if ($line->stock_unit_id) {
                        $unit = StockUnit::query()->whereKey($line->stock_unit_id)->lockForUpdate()->first();
                        $unit?->update([
                            'status' => StockUnitStatus::InStock,
                            'location_id' => $transfer->to_location_id,
                        ]);
                    }

                    $entries[] = [
                        'product_variant_id' => $line->product_variant_id,
                        'location_id' => $transfer->to_location_id,
                        'type' => MovementType::TransferIn,
                        'quantity' => $receivedQty,
                        'unit_cost' => $line->unit_cost,
                        'reference' => $transfer,
                        'stock_unit_id' => $line->stock_unit_id,
                        'user_id' => $actor->id,
                        'note' => "Transfer {$transfer->number} received",
                    ];
                }
            }

            if ($entries !== []) {
                $this->stock->moveMany($entries);
            }

            foreach ($transfer->lines as $line) {
                if ($line->sales_order_line_id && $line->received_qty > 0) {
                    $this->reserveForOrderLine($line, $transfer);
                }
            }

            $newStatus = $hasDiscrepancy ? TransferStatus::PartiallyReceived : TransferStatus::Received;

            $transfer->update([
                'status' => $newStatus,
                'received_by' => $actor->id,
                'received_at' => now(),
                'sync_version' => $entries !== [] ? SyncVersion::current($transfer->to_location_id) : SyncVersion::next($transfer->to_location_id),
            ]);

            AuditLog::record('transfer.received', $transfer, "Transfer {$transfer->number} {$newStatus->value}", null, ['status' => $newStatus->value], $actor);

            return $transfer->fresh(['lines.variant.product', 'fromLocation', 'toLocation']);
        });
    }

    /**
     * Resolve short-receipt discrepancies on a partially_received transfer (manager/admin only).
     *
     * - 'write_off': records an AuditLog and marks any unreceived serialized unit as written_off (stock already left source on dispatch, and we also record an explicit adjustment_in + adjustment_out at source OR keep ledger clean and log the loss on the transfer).
     *   Wait: let's check how stock_levels and stock_movements work!
     *   When dispatched, `sent_qty` was deducted from source (`transfer_out = -sent_qty`).
     *   When received, `received_qty` was added to destination (`transfer_in = +received_qty`).
     *   So the missing `short_qty = sent_qty - received_qty` has ALREADY been deducted from source!
     *   If resolution is `return_to_source`, the missing `short_qty` is returned to the source location (`transfer_in = +short_qty` at `from_location_id`)!
     *   If resolution is `write_off`, the missing `short_qty` remains deducted from source (or we can return +short_qty as transfer_in and immediately deduct -short_qty as adjustment_out/damage so the loss appears explicitly as an `adjustment_out` movement in the ledger!).
     *   Putting `transfer_in (+short_qty)` + `adjustment_out (-short_qty)` at `from_location_id` on `write_off` means `transfer_out` + `transfer_in` net out for the unreceived portion AND the ledger explicitly shows an `adjustment_out` loss movement! And `stock:verify` (`SUM(movements) == on_hand`) stays 100% exact!
     */
    public function resolveDiscrepancy(Transfer $transfer, User $manager, string $resolution, ?string $note = null): Transfer
    {
        if (! $manager->role->canOverride()) {
            throw new InvalidArgumentException('Only a manager or admin can resolve transfer discrepancies.');
        }

        if (! in_array($resolution, ['write_off', 'return_to_source'], true)) {
            throw new InvalidArgumentException('Invalid discrepancy resolution. Choose write_off or return_to_source.');
        }

        return DB::transaction(function () use ($transfer, $manager, $resolution, $note) {
            $transfer = Transfer::query()->with('lines.variant')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== TransferStatus::PartiallyReceived) {
                throw new InvalidArgumentException('Only partially received transfers have unresolved discrepancies.');
            }

            $entries = [];

            foreach ($transfer->lines as $line) {
                /** @var TransferLine $line */
                $shortQty = $line->discrepancyQty();
                if ($shortQty <= 0) {
                    continue;
                }

                if ($resolution === 'return_to_source') {
                    if ($line->stock_unit_id && $line->received_qty === 0) {
                        StockUnit::query()->whereKey($line->stock_unit_id)->update([
                            'status' => StockUnitStatus::InStock,
                            'location_id' => $transfer->from_location_id,
                        ]);
                    }

                    $entries[] = [
                        'product_variant_id' => $line->product_variant_id,
                        'location_id' => $transfer->from_location_id,
                        'type' => MovementType::TransferIn,
                        'quantity' => $shortQty,
                        'unit_cost' => $line->unit_cost,
                        'reference' => $transfer,
                        'stock_unit_id' => $line->stock_unit_id,
                        'user_id' => $manager->id,
                        'note' => $note ?: "Transfer {$transfer->number} discrepancy returned to source",
                    ];
                } else {
                    // Write-off: bring the unreceived in-transit qty back into source and write it off as adjustment_out
                    // so the loss movement is explicitly visible in stock_movements while keeping on_hand unchanged.
                    if ($line->stock_unit_id && $line->received_qty === 0) {
                        StockUnit::query()->whereKey($line->stock_unit_id)->update([
                            'status' => StockUnitStatus::WrittenOff,
                            'location_id' => null,
                        ]);
                    }

                    $entries[] = [
                        'product_variant_id' => $line->product_variant_id,
                        'location_id' => $transfer->from_location_id,
                        'type' => MovementType::TransferIn,
                        'quantity' => $shortQty,
                        'unit_cost' => $line->unit_cost,
                        'reference' => $transfer,
                        'stock_unit_id' => $line->stock_unit_id,
                        'user_id' => $manager->id,
                        'note' => "Transfer {$transfer->number} short receipt reversal",
                    ];

                    $entries[] = [
                        'product_variant_id' => $line->product_variant_id,
                        'location_id' => $transfer->from_location_id,
                        'type' => MovementType::AdjustmentOut,
                        'quantity' => -$shortQty,
                        'unit_cost' => $line->unit_cost,
                        'reference' => $transfer,
                        'stock_unit_id' => $line->stock_unit_id,
                        'user_id' => $manager->id,
                        'allow_negative' => true,
                        'note' => $note ?: "Transfer {$transfer->number} discrepancy write-off ({$line->discrepancy_reason})",
                    ];
                }
            }

            if ($entries !== []) {
                $this->stock->moveMany($entries);
            }

            $transfer->update([
                'status' => TransferStatus::Closed,
                'resolution' => $resolution,
                'closed_by' => $manager->id,
                'closed_at' => now(),
                'sync_version' => $entries !== [] ? SyncVersion::current($transfer->from_location_id) : SyncVersion::next($transfer->from_location_id),
            ]);

            AuditLog::record(
                'transfer.discrepancy_resolved',
                $transfer,
                "Transfer {$transfer->number} discrepancy resolved ({$resolution})",
                null,
                ['resolution' => $resolution, 'note' => $note],
                $manager,
            );

            return $transfer->fresh(['lines.variant.product', 'fromLocation', 'toLocation']);
        });
    }

    public function cancel(Transfer $transfer, User $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Requested], true)) {
                throw new InvalidArgumentException('Cannot cancel a transfer that has already been dispatched.');
            }

            $transfer->update([
                'status' => TransferStatus::Cancelled,
                'sync_version' => SyncVersion::next($transfer->from_location_id),
            ]);

            AuditLog::record('transfer.cancelled', $transfer, "Transfer {$transfer->number} cancelled", null, null, $actor);

            return $transfer;
        });
    }

    /** Dispatch: free the order line's reservation at the source so the piece can leave. */
    private function releaseForOrderLine(TransferLine $line, int $fromLocationId, int $quantity): void
    {
        $left = $quantity;
        $reservations = StockReservation::query()
            ->where('reserved_for_type', (new SalesOrderLine)->getMorphClass())->where('reserved_for_id', $line->sales_order_line_id)
            ->where('location_id', $fromLocationId)->where('product_variant_id', $line->product_variant_id)->where('status', ReservationStatus::Active->value)
            ->when($line->stock_unit_id, fn ($q) => $q->where('stock_unit_id', $line->stock_unit_id))
            ->orderBy('id')->get();

        foreach ($reservations as $reservation) {
            if ($left <= 0) {
                break;
            }
            $this->reservations->release($reservation);
            $left -= $reservation->quantity;
        }

        $released = $quantity - max(0, $left);
        if ($released > 0) {
            SalesOrderLine::query()->whereKey($line->sales_order_line_id)->where('reserved_qty', '>=', $released)->decrement('reserved_qty', $released);
        }
    }

    /** Receipt: reserve the piece again for its order at the destination, which now fulfils the line. */
    private function reserveForOrderLine(TransferLine $line, Transfer $transfer): void
    {
        $orderLine = SalesOrderLine::query()->with('salesOrder')->find($line->sales_order_line_id);
        if (! $orderLine || ! $orderLine->salesOrder?->status->canFulfil() || $orderLine->remainingQty() <= $orderLine->reserved_qty) {
            return;
        }

        $qty = min($line->received_qty, $orderLine->remainingQty() - $orderLine->reserved_qty);
        $this->reservations->reserve($line->product_variant_id, $transfer->to_location_id, $qty, $orderLine, $line->stock_unit_id, "Reserved for {$orderLine->salesOrder->number} (via {$transfer->number})");
        $orderLine->forceFill(['reserved_qty' => $orderLine->reserved_qty + $qty, 'fulfil_location_id' => $transfer->to_location_id])->save();
    }
}
