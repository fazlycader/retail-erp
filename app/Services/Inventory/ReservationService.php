<?php

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Enums\StockUnitStatus;
use App\Exceptions\InsufficientStock;
use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\StockUnit;
use App\Support\SyncVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reserves, releases and fulfils stock for sales order lines or serialized units.
 */
class ReservationService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * Reserve quantity (milli-units) at a location for a reference model.
     */
    public function reserve(
        ProductVariant|int $variant,
        int $locationId,
        int $quantity,
        ?Model $reservedFor = null,
        ?int $stockUnitId = null,
        ?string $note = null,
        ?int $userId = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Reservation quantity must be positive.');
        }

        return DB::transaction(function () use ($variant, $locationId, $quantity, $reservedFor, $stockUnitId, $note, $userId) {
            $variantModel = $variant instanceof ProductVariant
                ? $variant
                : ProductVariant::query()->withTrashed()->findOrFail($variant);

            $level = $this->stock->lockLevel($locationId, $variantModel->id);
            $available = max(0, $level->on_hand - $level->reserved);

            if (! CompanySetting::current()->allow_negative_stock && $available < $quantity) {
                throw InsufficientStock::forVariant($variantModel, $locationId, $quantity, $available);
            }

            if ($stockUnitId !== null) {
                $unit = StockUnit::query()->whereKey($stockUnitId)->lockForUpdate()->firstOrFail();
                if ($unit->product_variant_id !== $variantModel->id || $unit->location_id !== $locationId || $unit->status !== StockUnitStatus::InStock) {
                    throw new InvalidArgumentException("Serialized unit [{$unit->serial_number}] is not available at this location.");
                }
                $unit->update(['status' => StockUnitStatus::Reserved]);
            }

            $level->reserved += $quantity;
            $level->sync_version = SyncVersion::next($locationId);
            $level->save();

            return StockReservation::query()->create([
                'product_variant_id' => $variantModel->id,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'stock_unit_id' => $stockUnitId,
                'reserved_for_type' => $reservedFor?->getMorphClass(),
                'reserved_for_id' => $reservedFor?->getKey(),
                'status' => ReservationStatus::Active,
                'user_id' => $userId ?? Auth::id(),
                'note' => $note,
            ]);
        });
    }

    /**
     * Release an active reservation without moving stock out.
     */
    public function release(StockReservation $reservation): StockReservation
    {
        return DB::transaction(function () use ($reservation) {
            $reservation = StockReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                return $reservation;
            }

            $level = $this->stock->lockLevel($reservation->location_id, $reservation->product_variant_id);
            $level->reserved = max(0, $level->reserved - $reservation->quantity);
            $level->sync_version = SyncVersion::next($reservation->location_id);
            $level->save();

            if ($reservation->stock_unit_id) {
                $unit = StockUnit::query()->whereKey($reservation->stock_unit_id)->lockForUpdate()->first();
                if ($unit && $unit->status === StockUnitStatus::Reserved) {
                    $unit->update(['status' => StockUnitStatus::InStock]);
                }
            }

            $reservation->update([
                'status' => ReservationStatus::Released,
                'released_at' => now(),
            ]);

            return $reservation;
        });
    }

    /**
     * Fulfil an active reservation (or a partial quantity of it) by releasing the reserved hold and moving the stock out.
     */
    public function fulfil(
        StockReservation $reservation,
        MovementType $movementType = MovementType::Sale,
        ?Model $reference = null,
        ?string $note = null,
        ?int $quantity = null,
    ): StockReservation {
        return DB::transaction(function () use ($reservation, $movementType, $reference, $note, $quantity) {
            $reservation = StockReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== ReservationStatus::Active) {
                throw new InvalidArgumentException('Only active reservations can be fulfilled.');
            }

            $qtyToFulfil = $quantity !== null ? $quantity : $reservation->quantity;
            if ($qtyToFulfil <= 0 || $qtyToFulfil > $reservation->quantity) {
                throw new InvalidArgumentException('Fulfilment quantity must be positive and not exceed the reserved quantity.');
            }

            $level = $this->stock->lockLevel($reservation->location_id, $reservation->product_variant_id);
            $level->reserved = max(0, $level->reserved - $qtyToFulfil);
            $level->save();

            $ref = $reference ?? $reservation;

            if ($reservation->stock_unit_id) {
                $unit = StockUnit::query()->whereKey($reservation->stock_unit_id)->lockForUpdate()->first();
                if ($unit) {
                    $unit->update([
                        'status' => StockUnitStatus::Sold,
                        'location_id' => null,
                        'sold_reference_type' => $ref->getMorphClass(),
                        'sold_reference_id' => $ref->getKey(),
                    ]);
                }
            }

            $this->stock->move([
                'product_variant_id' => $reservation->product_variant_id,
                'location_id' => $reservation->location_id,
                'type' => $movementType,
                'quantity' => -$qtyToFulfil,
                'reference' => $ref,
                'stock_unit_id' => $reservation->stock_unit_id,
                'note' => $note ?? $reservation->note,
            ]);

            if ($qtyToFulfil < $reservation->quantity) {
                $reservation->update([
                    'quantity' => $reservation->quantity - $qtyToFulfil,
                ]);
            } else {
                $reservation->update([
                    'status' => ReservationStatus::Fulfilled,
                    'fulfilled_at' => now(),
                ]);
            }

            return $reservation->fresh();
        });
    }
}
