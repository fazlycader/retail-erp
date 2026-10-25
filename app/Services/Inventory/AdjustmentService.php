<?php

namespace App\Services\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\DocumentType;
use App\Enums\MovementType;
use App\Enums\StockUnitCondition;
use App\Enums\StockUnitStatus;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockUnit;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Counter;
use App\Support\ManagerApproval;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates stock adjustments (gains, losses, damage, samples, corrections) and posts their
 * ledger movements. Adjustments whose total cost value exceeds company_settings.adjustment_approval_value
 * require a manager PIN (or an acting admin/manager if they provide their own or another manager's PIN,
 * or if the actor is not a manager/admin they must obtain ManagerApproval::verify()).
 */
class AdjustmentService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array{
     *     location_id: int,
     *     reason: AdjustmentReason|string,
     *     note?: string|null,
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         quantity: int,
     *         unit_cost?: int|null,
     *         stock_unit_id?: int|null,
     *         serial_number?: string|null,
     *         note?: string|null,
     *     }>,
     *     approver_id?: int|null,
     *     pin?: string|null,
     * }  $data
     */
    public function create(array $data, User $actor): StockAdjustment
    {
        $locationId = (int) $data['location_id'];
        $reason = $data['reason'] instanceof AdjustmentReason
            ? $data['reason']
            : AdjustmentReason::from((string) $data['reason']);

        if (empty($data['lines'])) {
            throw new InvalidArgumentException('An adjustment needs at least one line.');
        }

        return DB::transaction(function () use ($data, $locationId, $reason, $actor) {
            $location = Location::query()->findOrFail($locationId);
            $variantIds = array_values(array_unique(array_map(fn ($l) => (int) $l['product_variant_id'], $data['lines'])));
            sort($variantIds);

            $variants = ProductVariant::query()
                ->withTrashed()
                ->with('product')
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');

            $preparedLines = [];
            $totalValue = 0;

            foreach ($data['lines'] as $rawLine) {
                $variantId = (int) $rawLine['product_variant_id'];
                $variant = $variants->get($variantId);

                if (! $variant) {
                    throw new InvalidArgumentException("Variant [{$variantId}] not found.");
                }

                $qty = (int) $rawLine['quantity'];
                if ($qty === 0) {
                    throw new InvalidArgumentException("Adjustment quantity for {$variant->sku} cannot be zero.");
                }

                $defaultDirection = $reason->defaultDirection();
                if ($defaultDirection !== null) {
                    $qty = $defaultDirection * abs($qty);
                }

                $unitCost = isset($rawLine['unit_cost']) ? (int) $rawLine['unit_cost'] : $variant->average_cost;
                $lineValue = abs(Quantity::times(abs($qty), $unitCost));
                $totalValue += $lineValue;

                $preparedLines[] = [
                    'variant' => $variant,
                    'product_variant_id' => $variant->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_value' => $lineValue,
                    'stock_unit_id' => isset($rawLine['stock_unit_id']) ? (int) $rawLine['stock_unit_id'] : null,
                    'serial_number' => isset($rawLine['serial_number']) && trim((string) $rawLine['serial_number']) !== '' ? trim((string) $rawLine['serial_number']) : null,
                    'note' => isset($rawLine['note']) && trim((string) $rawLine['note']) !== '' ? trim((string) $rawLine['note']) : null,
                ];
            }

            $threshold = CompanySetting::current()->adjustment_approval_value;
            $approverId = null;

            if ($totalValue > $threshold) {
                $approver = $this->verifyApproval(
                    $actor,
                    $locationId,
                    isset($data['approver_id']) ? (int) $data['approver_id'] : null,
                    $data['pin'] ?? null,
                    "stock adjustment ({$reason->label()})",
                );
                $approverId = $approver->id;
            } elseif ($actor->role->canOverride()) {
                $approverId = $actor->id;
            }

            $prefix = CompanySetting::current()->prefixFor(DocumentType::Adjustment);
            $seq = Counter::next(DocumentType::Adjustment->value, "location:{$location->id}");
            $number = sprintf('%s-%s-%06d', $prefix, $location->code, $seq);

            $adjustment = StockAdjustment::query()->create([
                'number' => $number,
                'location_id' => $locationId,
                'reason' => $reason,
                'total_value' => $totalValue,
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                'created_by' => $actor->id,
                'approved_by' => $approverId,
                'business_date' => BusinessDay::current($locationId),
            ]);

            $movementEntries = [];

            foreach ($preparedLines as $line) {
                $stockUnitId = $line['stock_unit_id'];

                if ($line['variant']->product?->is_serialized) {
                    $stockUnitId = $this->handleSerializedAdjustment($line, $locationId, $reason);
                }

                $adjLine = $adjustment->lines()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'stock_unit_id' => $stockUnitId,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_value' => $line['line_value'],
                    'note' => $line['note'],
                ]);

                $movementType = match (true) {
                    $reason === AdjustmentReason::Damaged && $line['quantity'] < 0 => MovementType::Damage,
                    $line['quantity'] > 0 => MovementType::AdjustmentIn,
                    default => MovementType::AdjustmentOut,
                };

                $movementEntries[] = [
                    'product_variant_id' => $line['product_variant_id'],
                    'location_id' => $locationId,
                    'type' => $movementType,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'reference' => $adjustment,
                    'stock_unit_id' => $stockUnitId,
                    'user_id' => $actor->id,
                    'business_date' => $adjustment->business_date->toDateString(),
                    'note' => $line['note'] ?? $adjustment->note ?? $reason->label(),
                ];
            }

            $this->stock->moveMany($movementEntries);

            AuditLog::record(
                'stock.adjustment_created',
                $adjustment,
                "Stock adjustment {$adjustment->number} ({$reason->label()})",
                null,
                ['total_value' => $totalValue, 'approved_by' => $approverId],
                $actor,
            );

            return $adjustment->load(['lines.variant.product', 'location', 'creator', 'approver']);
        });
    }

    /**
     * Verify manager PIN when an adjustment exceeds adjustment_approval_value.
     * Allows a manager/admin to enter their own PIN or another manager's PIN.
     */
    private function verifyApproval(User $actor, int $locationId, ?int $approverId, ?string $pin, string $what): User
    {
        // If actor is a manager/admin and enters their own PIN (approverId === actor->id), allow self-PIN verification
        if ($approverId === $actor->id && $actor->role->canOverride() && $actor->is_active && $actor->worksAt($locationId) && filled($pin)) {
            if ($actor->attemptPin($pin, $what)) {
                return $actor;
            }
        }

        return ManagerApproval::verify($actor, $locationId, $approverId, $pin, 'stock.adjustment', null, $what);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function handleSerializedAdjustment(array $line, int $locationId, AdjustmentReason $reason): ?int
    {
        if ($line['quantity'] > 0 && $line['serial_number']) {
            $unit = StockUnit::query()->where('serial_number', $line['serial_number'])->lockForUpdate()->first();
            if ($unit) {
                $unit->update([
                    'location_id' => $locationId,
                    'status' => StockUnitStatus::InStock,
                ]);

                return $unit->id;
            }

            $created = StockUnit::query()->create([
                'serial_number' => $line['serial_number'],
                'product_variant_id' => $line['product_variant_id'],
                'location_id' => $locationId,
                'status' => StockUnitStatus::InStock,
                'condition' => StockUnitCondition::New,
                'unit_cost' => $line['unit_cost'],
                'received_at' => now(),
                'note' => $line['note'],
            ]);

            return $created->id;
        }

        if ($line['quantity'] < 0 && ($line['stock_unit_id'] || $line['serial_number'])) {
            $unit = $line['stock_unit_id']
                ? StockUnit::query()->whereKey($line['stock_unit_id'])->lockForUpdate()->first()
                : StockUnit::query()->where('serial_number', $line['serial_number'])->lockForUpdate()->first();

            if ($unit) {
                $unit->update([
                    'status' => $reason === AdjustmentReason::Damaged ? StockUnitStatus::Damaged : StockUnitStatus::WrittenOff,
                    'condition' => $reason === AdjustmentReason::Damaged ? StockUnitCondition::Damaged : $unit->condition,
                    'location_id' => null,
                ]);

                return $unit->id;
            }
        }

        return $line['stock_unit_id'];
    }
}
