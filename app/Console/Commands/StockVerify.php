<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies that every (variant, location) stock_levels.on_hand equals the sum of its
 * immutable stock_movements.quantity, and stock_levels.reserved equals the sum of active reservations.
 */
#[Signature('stock:verify {--location= : Only check one location ID}')]
#[Description('Verify that stock_levels match the immutable stock_movements ledger and active reservations')]
class StockVerify extends Command
{
    public function handle(): int
    {
        $locationId = $this->option('location') ? (int) $this->option('location') : null;

        $movementSums = StockMovement::query()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('location_id, product_variant_id, SUM(quantity) as ledger_on_hand')
            ->groupBy('location_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $r->location_id.':'.$r->product_variant_id);

        $reservationSums = StockReservation::query()
            ->where('status', ReservationStatus::Active)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('location_id, product_variant_id, SUM(quantity) as active_reserved')
            ->groupBy('location_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($r) => $r->location_id.':'.$r->product_variant_id);

        $levels = StockLevel::query()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get()
            ->keyBy(fn (StockLevel $l) => $l->location_id.':'.$l->product_variant_id);

        $allKeys = array_unique([
            ...$movementSums->keys()->all(),
            ...$reservationSums->keys()->all(),
            ...$levels->keys()->all(),
        ]);

        $mismatches = [];

        foreach ($allKeys as $key) {
            [$locId, $varId] = array_map('intval', explode(':', $key));
            $ledgerOnHand = (int) ($movementSums->get($key)?->ledger_on_hand ?? 0);
            $activeReserved = (int) ($reservationSums->get($key)?->active_reserved ?? 0);
            $level = $levels->get($key);
            $levelOnHand = (int) ($level?->on_hand ?? 0);
            $levelReserved = (int) ($level?->reserved ?? 0);

            if ($ledgerOnHand !== $levelOnHand || $activeReserved !== $levelReserved) {
                $mismatches[] = [
                    'location_id' => $locId,
                    'product_variant_id' => $varId,
                    'level_on_hand' => $levelOnHand,
                    'ledger_on_hand' => $ledgerOnHand,
                    'level_reserved' => $levelReserved,
                    'active_reserved' => $activeReserved,
                ];
            }
        }

        if ($mismatches !== []) {
            $this->error(count($mismatches).' stock level mismatch(es) found:');
            $this->table(
                ['Location ID', 'Variant ID', 'Level On Hand', 'Ledger On Hand', 'Level Reserved', 'Active Reserved'],
                $mismatches,
            );

            return self::FAILURE;
        }

        $this->info('Stock verification passed: all stock levels match movements and reservations ('
            .count($allKeys).' pairs checked).');

        return self::SUCCESS;
    }
}
