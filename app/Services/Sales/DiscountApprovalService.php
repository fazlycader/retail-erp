<?php

namespace App\Services\Sales;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\PriceFloor;
use App\Support\BasisPoints;
use App\Support\ManagerApproval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Enforces role discount limits (cashier_max_discount_bp, sales_max_discount_bp)
 * and variant min_price floors (PriceFloor) across Quotations, Sales Orders, and POS.
 */
class DiscountApprovalService
{
    public function __construct(
        private readonly PriceFloor $priceFloor,
    ) {}

    /**
     * Maximum discount (in basis points) a user may grant without a manager PIN.
     */
    public function maxDiscountBpFor(User $user): int
    {
        $settings = CompanySetting::current();

        return match ($user->role) {
            Role::Admin, Role::Manager => 10000,
            Role::Cashier => (int) $settings->cashier_max_discount_bp,
            default => (int) $settings->sales_max_discount_bp,
        };
    }

    /**
     * Authorise all line prices and discounts for a priced calculation result.
     * Returns the approving manager User if an override was needed and granted, or null if within normal limits.
     *
     * @param  array<string, mixed>  $pricedResult  Result from PricingService::calculate()
     * @param  Collection<int, ProductVariant>  $variantsById
     */
    public function authorise(
        User $actor,
        int $locationId,
        array $pricedResult,
        Collection $variantsById,
        ?int $approverId = null,
        ?string $pin = null,
        ?Model $subject = null,
    ): ?User {
        $settings = CompanySetting::current();
        $roleLimitBp = $this->maxDiscountBpFor($actor);
        $salesLimitBp = (int) $settings->sales_max_discount_bp;

        $approver = null;
        $exceededReasons = [];

        foreach ($pricedResult['lines'] as $line) {
            $variantId = isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null;
            /** @var ProductVariant|null $variant */
            $variant = $variantId ? $variantsById->get($variantId) : null;

            if ($variant) {
                $floorApprover = $this->priceFloor->authorise(
                    $actor,
                    $variant,
                    (int) $line['effective_unit_price'],
                    $locationId,
                    $approverId,
                    $pin,
                );
                if ($floorApprover) {
                    $approver = $floorApprover;
                }
            }

            $lineDiscountBp = (int) ($line['effective_discount_bp'] ?? 0);
            if ($lineDiscountBp > $roleLimitBp) {
                $sku = $variant?->sku ?? ($line['name_snapshot'] ?? 'item');
                $exceededReasons[] = "{$sku} discount ".BasisPoints::toPercent($lineDiscountBp).'% > limit '.BasisPoints::toPercent($roleLimitBp).'%';
            } elseif ($actor->role->canOverride() && $lineDiscountBp > $salesLimitBp) {
                $approver ??= $actor;
            }
        }

        $totalDiscountBp = (int) ($pricedResult['total_discount_bp'] ?? 0);
        if ($totalDiscountBp > $roleLimitBp) {
            $exceededReasons[] = 'order discount '.BasisPoints::toPercent($totalDiscountBp).'% > limit '.BasisPoints::toPercent($roleLimitBp).'%';
        } elseif ($actor->role->canOverride() && $totalDiscountBp > $salesLimitBp) {
            $approver ??= $actor;
        }

        if ($exceededReasons !== []) {
            $what = implode('; ', $exceededReasons);

            if (! $approver) {
                $approver = $actor->role->canOverride()
                    ? $actor
                    : ManagerApproval::verify($actor, $locationId, $approverId, $pin, 'sales.discount_override', $subject, $what);
            }

            AuditLog::record(
                'sales.discount_override',
                $subject,
                "Discount override ({$what}) approved by {$approver->name}",
                null,
                [
                    'total_discount_bp' => $totalDiscountBp,
                    'role_limit_bp' => $roleLimitBp,
                    'approved_by' => $approver->id,
                ],
                $actor,
            );
        } elseif ($approver && $totalDiscountBp > $salesLimitBp) {
            AuditLog::record(
                'sales.discount_override',
                $subject,
                'Manager discount '.BasisPoints::toPercent($totalDiscountBp)."% by {$approver->name}",
                null,
                [
                    'total_discount_bp' => $totalDiscountBp,
                    'approved_by' => $approver->id,
                ],
                $actor,
            );
        }

        return $approver;
    }
}
