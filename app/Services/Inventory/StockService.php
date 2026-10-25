<?php

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Exceptions\InsufficientStock;
use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\BusinessDay;
use App\Support\Quantity;
use App\Support\SyncVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single writer of stock_levels and stock_movements. Quantities are milli-units; only raw
 * materials (Product::isMaterial()) may move in fractions of a unit.
 *
 * All level locks are acquired in (location_id ASC, product_variant_id ASC) order
 * using MySQL's atomic INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
 * followed by SELECT ... FOR UPDATE so concurrent transfers and sales never deadlock.
 */
class StockService
{
    /**
     * Record a single stock movement and update the location's stock level.
     *
     * @param  array{
     *     product_variant_id?: int,
     *     variant?: ProductVariant,
     *     location_id: int,
     *     type: MovementType|string,
     *     quantity: int,
     *     unit_cost?: int|null,
     *     reference?: Model|null,
     *     reference_type?: string|null,
     *     reference_id?: int|null,
     *     stock_unit_id?: int|null,
     *     user_id?: int|null,
     *     business_date?: string|null,
     *     note?: string|null,
     *     allow_negative?: bool|null,
     * }  $entry
     */
    public function move(array $entry): StockMovement
    {
        return $this->moveMany([$entry])[0];
    }

    /**
     * Record multiple stock movements atomically in deadlock-safe lock order.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, StockMovement>
     */
    public function moveMany(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        return DB::transaction(function () use ($entries) {
            $normalized = array_map(fn (array $entry) => $this->normalizeEntry($entry), $entries);

            // Collect and sort unique (location_id, product_variant_id) pairs to prevent deadlocks.
            $pairs = [];
            $variantIds = [];
            foreach ($normalized as $item) {
                $key = $item['location_id'].':'.$item['product_variant_id'];
                $pairs[$key] = [
                    'location_id' => $item['location_id'],
                    'product_variant_id' => $item['product_variant_id'],
                ];
                $variantIds[$item['product_variant_id']] = $item['product_variant_id'];
            }

            usort($pairs, fn (array $a, array $b) => $a['location_id'] <=> $b['location_id']
                ?: $a['product_variant_id'] <=> $b['product_variant_id']);

            ksort($variantIds);
            $needsCostUpdate = collect($normalized)->contains(
                fn (array $item) => in_array($item['type'], [MovementType::Opening, MovementType::PurchaseReceipt, MovementType::ProductionIn], true) && $item['quantity'] > 0,
            );

            $variantsQuery = ProductVariant::query()
                ->withTrashed()
                ->with('product')
                ->whereIn('id', array_values($variantIds))
                ->orderBy('id');

            if ($needsCostUpdate) {
                $variantsQuery->lockForUpdate();
            }

            $variants = $variantsQuery->get()->keyBy('id');

            /** @var array<string, StockLevel> $levels */
            $levels = [];
            foreach ($pairs as $pair) {
                $key = $pair['location_id'].':'.$pair['product_variant_id'];
                $levels[$key] = $this->lockLevel($pair['location_id'], $pair['product_variant_id']);
            }

            $allowNegativeDefault = CompanySetting::current()->allow_negative_stock;
            $movements = [];
            $touchedLocations = [];

            foreach ($normalized as $item) {
                $variant = $variants->get($item['product_variant_id']);
                if (! $variant) {
                    throw new InvalidArgumentException("Product variant [{$item['product_variant_id']}] not found.");
                }

                if ($variant->product?->type === ProductType::Service) {
                    throw new InvalidArgumentException("Service product [{$variant->sku}] does not track stock.");
                }

                // Raw materials may move in fractions (2.5 m of fabric); everything else in whole units.
                if ($item['quantity'] % Quantity::SCALE !== 0 && ! $variant->product?->isMaterial()) {
                    throw new InvalidArgumentException("[{$variant->sku}] moves in whole units; only raw materials can move in fractions.");
                }

                $key = $item['location_id'].':'.$item['product_variant_id'];
                $level = $levels[$key];
                $signedQty = $item['quantity'];
                $allowNegative = $item['allow_negative'] ?? $allowNegativeDefault;

                $newOnHand = $level->on_hand + $signedQty;

                if ($signedQty < 0 && ! $allowNegative) {
                    $available = max(0, $level->on_hand - $level->reserved);
                    if ($newOnHand < 0 || $newOnHand < $level->reserved) {
                        throw InsufficientStock::forVariant($variant, $item['location_id'], abs($signedQty), $available);
                    }
                }

                $unitCost = $item['unit_cost'] ?? $variant->average_cost ?? 0;

                // Update weighted average cost on opening stock, purchase receipt or a finished production job.
                if (in_array($item['type'], [MovementType::Opening, MovementType::PurchaseReceipt, MovementType::ProductionIn], true) && $signedQty > 0) {
                    $costQty = $item['cost_qty'] ?? $signedQty;
                    if ($costQty > 0) {
                        $this->updateAverageCost($variant, $costQty, $unitCost, $levels);
                    }
                }

                $level->on_hand = $newOnHand;
                $touchedLocations[$item['location_id']] = $item['location_id'];

                $movements[] = StockMovement::query()->create([
                    'product_variant_id' => $variant->id,
                    'location_id' => $item['location_id'],
                    'type' => $item['type'],
                    'quantity' => $signedQty,
                    'balance_after' => $newOnHand,
                    'unit_cost' => $unitCost,
                    'reference_type' => $item['reference_type'],
                    'reference_id' => $item['reference_id'],
                    'stock_unit_id' => $item['stock_unit_id'],
                    'user_id' => $item['user_id'],
                    'business_date' => $item['business_date'],
                    'note' => $item['note'],
                ]);
            }

            ksort($touchedLocations);
            $syncVersions = [];
            foreach ($touchedLocations as $locId) {
                $syncVersions[$locId] = SyncVersion::next($locId);
            }

            foreach ($levels as $level) {
                if (isset($syncVersions[$level->location_id])) {
                    $level->sync_version = $syncVersions[$level->location_id];
                    $level->save();
                }
            }

            return $movements;
        });
    }

    /**
     * Lock and return the StockLevel row for a (location, variant) pair without deadlocking.
     */
    public function lockLevel(int $locationId, int $variantId): StockLevel
    {
        $connection = DB::connection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Existing level (the normal case): lock it by primary key, which locks only that row.
            // The upsert below also gap-locks the end of the table, which deadlocks against other
            // tables' upserts (e.g. Counter) when a sale takes both in one transaction.
            // The lookup is a non-locking read (a locking read of a missing row would gap-lock too).
            $existingId = StockLevel::query()
                ->where('location_id', $locationId)
                ->where('product_variant_id', $variantId)
                ->value('id');

            if ($existingId) {
                return StockLevel::query()->whereKey($existingId)->lockForUpdate()->firstOrFail();
            }

            $now = now()->toDateTimeString();
            $connection->affectingStatement(
                'INSERT INTO `stock_levels` (`product_variant_id`, `location_id`, `on_hand`, `reserved`, `sync_version`, `created_at`, `updated_at`) VALUES (?, ?, 0, 0, 0, ?, ?) ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)',
                [$variantId, $locationId, $now, $now],
            );

            $id = (int) $connection->selectOne('SELECT LAST_INSERT_ID() AS id')->id;

            return StockLevel::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }

        DB::table('stock_levels')->insertOrIgnore([
            'product_variant_id' => $variantId,
            'location_id' => $locationId,
            'on_hand' => 0,
            'reserved' => 0,
            'sync_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLevel::query()
            ->where('location_id', $locationId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{
     *     product_variant_id: int,
     *     location_id: int,
     *     type: MovementType,
     *     quantity: int,
     *     unit_cost: int|null,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     stock_unit_id: int|null,
     *     user_id: int|null,
     *     business_date: string,
     *     note: string|null,
     *     allow_negative: bool|null,
     * }
     */
    private function normalizeEntry(array $entry): array
    {
        $variantId = isset($entry['variant']) && $entry['variant'] instanceof ProductVariant
            ? $entry['variant']->id
            : (int) ($entry['product_variant_id'] ?? 0);

        $locationId = (int) ($entry['location_id'] ?? 0);

        if ($variantId <= 0 || $locationId <= 0) {
            throw new InvalidArgumentException('Both product_variant_id and location_id are required for a stock movement.');
        }

        $type = $entry['type'] instanceof MovementType
            ? $entry['type']
            : MovementType::from((string) $entry['type']);

        $rawQty = (int) ($entry['quantity'] ?? 0);
        if ($rawQty === 0) {
            throw new InvalidArgumentException('Stock movement quantity cannot be zero.');
        }

        $signedQty = $type->sign() * abs($rawQty);

        $referenceType = $entry['reference_type'] ?? null;
        $referenceId = isset($entry['reference_id']) ? (int) $entry['reference_id'] : null;

        if (isset($entry['reference']) && $entry['reference'] instanceof Model) {
            $referenceType = $entry['reference']->getMorphClass();
            $referenceId = (int) $entry['reference']->getKey();
        }

        return [
            'product_variant_id' => $variantId,
            'location_id' => $locationId,
            'type' => $type,
            'quantity' => $signedQty,
            'unit_cost' => isset($entry['unit_cost']) ? (int) $entry['unit_cost'] : null,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'stock_unit_id' => isset($entry['stock_unit_id']) ? (int) $entry['stock_unit_id'] : null,
            'user_id' => array_key_exists('user_id', $entry) ? $entry['user_id'] : Auth::id(),
            'business_date' => $entry['business_date'] ?? BusinessDay::current($locationId),
            'note' => isset($entry['note']) && trim((string) $entry['note']) !== '' ? trim((string) $entry['note']) : null,
            'allow_negative' => array_key_exists('allow_negative', $entry) ? (bool) $entry['allow_negative'] : null,
            'cost_qty' => array_key_exists('cost_qty', $entry) ? (int) $entry['cost_qty'] : null,
        ];
    }

    /**
     * Weighted average cost:
     * new_avg = (on_hand_all_locations * old_avg + received_qty * unit_cost) / (on_hand_all + received_qty)
     * in integer cents, rounded half-up.
     *
     * @param  array<string, StockLevel>  $lockedLevels
     */
    private function updateAverageCost(ProductVariant $variant, int $addedQty, int $unitCost, array $lockedLevels): void
    {
        $lockedLocationIds = [];
        $totalOnHand = 0;

        foreach ($lockedLevels as $level) {
            if ($level->product_variant_id === $variant->id) {
                $totalOnHand += max(0, $level->on_hand);
                $lockedLocationIds[] = $level->location_id;
            }
        }

        $otherOnHand = (int) StockLevel::query()
            ->where('product_variant_id', $variant->id)
            ->whereNotIn('location_id', $lockedLocationIds)
            ->where('on_hand', '>', 0)
            ->sum('on_hand');

        $existingQty = max(0, $totalOnHand + $otherOnHand);
        $newTotalQty = $existingQty + $addedQty;

        if ($newTotalQty > 0) {
            $numerator = $existingQty * $variant->average_cost + $addedQty * $unitCost;
            $variant->average_cost = intdiv($numerator * 2 + $newTotalQty, $newTotalQty * 2);
        } else {
            $variant->average_cost = $unitCost;
        }

        $variant->last_cost = $unitCost;
        $variant->saveQuietly();
    }
}
