<?php

namespace App\Services\Catalog;

use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ManagerApproval;
use App\Support\Money;

/**
 * "Selling below the minimum price needs a manager." Sales (step 3+) call authorise() for each
 * line: admins and managers may go below the floor themselves (audited); anyone else needs a
 * manager's PIN (ManagerApproval, which throws ApprovalRefused). Returns who approved, or null
 * when the price is at or above the floor.
 */
class PriceFloor
{
    public function authorise(User $actor, ProductVariant $variant, int $price, int $locationId, ?int $approverId = null, ?string $pin = null): ?User
    {
        if (! $variant->belowMinimum($price)) {
            return null;
        }

        $what = "selling {$variant->sku} at ".Money::fromCents($price)->format().' (minimum '.Money::fromCents($variant->min_price)->format().')';

        $approver = $actor->role->canOverride()
            ? $actor
            : ManagerApproval::verify($actor, $locationId, $approverId, $pin, 'price.below_minimum', $variant, $what);

        AuditLog::record('price.below_minimum', $variant, ucfirst($what).", approved by {$approver->name}", null, ['price' => $price, 'min_price' => $variant->min_price, 'approved_by' => $approver->id], $actor);

        return $approver;
    }
}
