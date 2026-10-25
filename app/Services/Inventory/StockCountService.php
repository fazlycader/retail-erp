<?php

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLevel;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Physical stock counts:
 * open → counting (scan barcode for +1 or enter quantities; blind option hides system_qty from counters)
 * → review (variance and value shown) → posted (manager posts count_gain / count_loss movements).
 */
class StockCountService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * Start a stock count at a location and freeze current system quantities on each line.
     *
     * @param  array{
     *     location_id: int,
     *     scope: StockCountScope|string,
     *     category_id?: int|null,
     *     variant_ids?: array<int, int>,
     *     blind?: bool,
     *     note?: string|null,
     * }  $data
     */
    public function start(array $data, User $actor): StockCount
    {
        $locationId = (int) $data['location_id'];
        $scope = $data['scope'] instanceof StockCountScope
            ? $data['scope']
            : StockCountScope::from((string) $data['scope']);

        return DB::transaction(function () use ($data, $locationId, $scope, $actor) {
            $location = Location::query()->findOrFail($locationId);

            $query = ProductVariant::query()
                ->where('is_active', true)
                ->whereHas('product', function ($q) use ($scope, $data) {
                    $q->where('is_active', true)
                        ->where('type', '!=', ProductType::Service->value);

                    if ($scope === StockCountScope::Category) {
                        $catId = (int) ($data['category_id'] ?? 0);
                        if ($catId <= 0) {
                            throw new InvalidArgumentException('Select a category for a category stock count.');
                        }
                        $childIds = Category::query()->where('parent_id', $catId)->pluck('id')->all();
                        $grandChildIds = $childIds ? Category::query()->whereIn('parent_id', $childIds)->pluck('id')->all() : [];
                        $q->whereIn('category_id', [$catId, ...$childIds, ...$grandChildIds]);
                    }
                });

            if ($scope === StockCountScope::Selected) {
                $ids = array_values(array_unique(array_map('intval', (array) ($data['variant_ids'] ?? []))));
                if ($ids === []) {
                    throw new InvalidArgumentException('Select at least one variant to count.');
                }
                $query->whereIn('id', $ids);
            }

            $variants = $query->orderBy('sku')->get();

            if ($variants->isEmpty()) {
                throw new InvalidArgumentException('No stock-tracked variants match this count scope.');
            }

            $seq = Counter::next('stock_count', "location:{$location->id}");
            $number = sprintf('CNT-%s-%06d', $location->code, $seq);

            $count = StockCount::query()->create([
                'number' => $number,
                'location_id' => $locationId,
                'scope' => $scope,
                'category_id' => $scope === StockCountScope::Category ? (int) $data['category_id'] : null,
                'blind' => (bool) ($data['blind'] ?? false),
                'status' => StockCountStatus::Counting,
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                'started_by' => $actor->id,
                'started_at' => now(),
                'business_date' => BusinessDay::current($locationId),
            ]);

            $levels = StockLevel::query()
                ->where('location_id', $locationId)
                ->whereIn('product_variant_id', $variants->pluck('id'))
                ->pluck('on_hand', 'product_variant_id');

            $now = now();
            $rows = [];
            foreach ($variants as $variant) {
                $systemQty = (int) ($levels[$variant->id] ?? 0);
                $rows[] = [
                    'stock_count_id' => $count->id,
                    'product_variant_id' => $variant->id,
                    'system_qty' => $systemQty,
                    'counted_qty' => null,
                    'variance_qty' => 0,
                    'unit_cost' => $variant->average_cost,
                    'variance_value' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                StockCountLine::query()->insert($chunk);
            }

            AuditLog::record('stock_count.started', $count, "Stock count {$count->number} started ({$variants->count()} items)", null, ['scope' => $scope->value, 'blind' => $count->blind], $actor);

            return $count->load(['lines.variant.product', 'location', 'category']);
        });
    }

    /**
     * Scanner-friendly increment: scan a SKU or barcode to add +$step (default 1 unit = 1000 milli-units)
     * to the matching line's counted_qty.
     */
    public function scan(StockCount $count, string $code, User $actor, int $stepMilli = Quantity::SCALE): StockCountLine
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('Enter or scan a barcode or SKU.');
        }

        return DB::transaction(function () use ($count, $code, $actor, $stepMilli) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if (! in_array($count->status, [StockCountStatus::Open, StockCountStatus::Counting], true)) {
                throw new InvalidArgumentException('This stock count is no longer open for counting.');
            }

            $variant = ProductVariant::query()
                ->where('barcode', $code)
                ->orWhere('sku', $code)
                ->first();

            if (! $variant) {
                throw new InvalidArgumentException("No variant found with barcode or SKU \"{$code}\".");
            }

            $line = StockCountLine::query()
                ->where('stock_count_id', $count->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if (! $line) {
                throw new InvalidArgumentException("{$variant->sku} is not part of this stock count.");
            }

            $newCounted = ($line->counted_qty ?? 0) + $stepMilli;
            $this->updateLineCount($line, $newCounted, $actor);

            if ($count->status === StockCountStatus::Open) {
                $count->update(['status' => StockCountStatus::Counting]);
            }

            return $line->load('variant.product');
        });
    }

    /**
     * Save counted quantities for one or more lines.
     *
     * @param  array<int, array{counted_qty: int|null, note?: string|null}|int|null>  $countsByLineId
     */
    public function recordCounts(StockCount $count, array $countsByLineId, User $actor): StockCount
    {
        return DB::transaction(function () use ($count, $countsByLineId, $actor) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if (! in_array($count->status, [StockCountStatus::Open, StockCountStatus::Counting, StockCountStatus::Review], true)) {
                throw new InvalidArgumentException('Posted stock counts cannot be edited.');
            }

            $lines = StockCountLine::query()
                ->where('stock_count_id', $count->id)
                ->whereIn('id', array_keys($countsByLineId))
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $payload = $countsByLineId[$line->id];
                $counted = is_array($payload) ? $payload['counted_qty'] : $payload;
                $note = is_array($payload) && array_key_exists('note', $payload) ? $payload['note'] : $line->note;

                if ($counted === null) {
                    $line->update([
                        'counted_qty' => null,
                        'variance_qty' => 0,
                        'variance_value' => 0,
                        'note' => $note,
                    ]);

                    continue;
                }

                $this->updateLineCount($line, max(0, (int) $counted), $actor, $note);
            }

            if ($count->status === StockCountStatus::Open) {
                $count->update(['status' => StockCountStatus::Counting]);
            }

            return $count->fresh(['lines.variant.product', 'location']);
        });
    }

    /**
     * Move the count to Review state (requires all lines to have a counted quantity, or fills uncounted as 0 if requested).
     */
    public function submitForReview(StockCount $count, User $actor, bool $zeroUncounted = false): StockCount
    {
        return DB::transaction(function () use ($count, $actor, $zeroUncounted) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if (! in_array($count->status, [StockCountStatus::Open, StockCountStatus::Counting], true)) {
                throw new InvalidArgumentException('Count cannot be submitted for review from its current state.');
            }

            if ($zeroUncounted) {
                foreach ($count->lines()->whereNull('counted_qty')->lockForUpdate()->get() as $line) {
                    $this->updateLineCount($line, 0, $actor);
                }
            } elseif ($count->lines()->whereNull('counted_qty')->exists()) {
                throw new InvalidArgumentException('Some items have not been counted yet. Count them or mark uncounted items as zero.');
            }

            $count->update([
                'status' => StockCountStatus::Review,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            return $count->fresh(['lines.variant.product', 'location']);
        });
    }

    /**
     * Post the stock count (manager/admin only): creates count_gain and count_loss movements
     * for lines with a non-zero variance against the frozen system_qty.
     */
    public function post(StockCount $count, User $manager): StockCount
    {
        if (! $manager->role->canOverride()) {
            throw new InvalidArgumentException('Only a manager or admin can post a stock count.');
        }

        return DB::transaction(function () use ($count, $manager) {
            $count = StockCount::query()->with('lines.variant')->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if ($count->status === StockCountStatus::Posted) {
                throw new InvalidArgumentException('This stock count has already been posted.');
            }

            if ($count->lines->contains(fn (StockCountLine $l) => $l->counted_qty === null)) {
                throw new InvalidArgumentException('All lines must be counted before posting.');
            }

            $entries = [];

            foreach ($count->lines as $line) {
                if ($line->variance_qty === 0) {
                    continue;
                }

                $entries[] = [
                    'product_variant_id' => $line->product_variant_id,
                    'location_id' => $count->location_id,
                    'type' => $line->variance_qty > 0 ? MovementType::CountGain : MovementType::CountLoss,
                    'quantity' => $line->variance_qty,
                    'unit_cost' => $line->unit_cost,
                    'reference' => $count,
                    'user_id' => $manager->id,
                    'business_date' => BusinessDay::current($count->location_id),
                    'allow_negative' => true,
                    'note' => $line->note ?: "Stock count {$count->number}",
                ];
            }

            if ($entries !== []) {
                $this->stock->moveMany($entries);
            }

            $count->update([
                'status' => StockCountStatus::Posted,
                'posted_by' => $manager->id,
                'posted_at' => now(),
            ]);

            AuditLog::record(
                'stock_count.posted',
                $count,
                "Stock count {$count->number} posted ({$count->lines->where('variance_qty', '!=', 0)->count()} variances)",
                null,
                ['variances' => count($entries)],
                $manager,
            );

            return $count->fresh(['lines.variant.product', 'location']);
        });
    }

    private function updateLineCount(StockCountLine $line, int $countedQty, User $actor, ?string $note = null): void
    {
        $varianceQty = $countedQty - $line->system_qty;
        $varianceValue = Quantity::times($varianceQty, $line->unit_cost);

        $line->update([
            'counted_qty' => $countedQty,
            'variance_qty' => $varianceQty,
            'variance_value' => $varianceValue,
            'counted_by' => $actor->id,
            'counted_at' => now(),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);
    }
}
