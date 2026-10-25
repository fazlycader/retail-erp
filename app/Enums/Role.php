<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Cashier = 'cashier';
    case Storekeeper = 'storekeeper';
    case Accountant = 'accountant';
    /** Workshop staff: the jobs board and job detail only, never prices or costs. */
    case Workshop = 'workshop';
    /** Delivery drivers use the isolated phone-first delivery app. */
    case Driver = 'driver';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Admins manage everyone everywhere; managers manage non-admin staff of their own locations.
     */
    public function canManageStaff(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /**
     * Manager overrides and approvals (PIN), shared terminals, the audit log.
     */
    public function canOverride(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /**
     * Admins work across every location (and may pick "All locations" where a page allows it).
     */
    public function seesAllLocations(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Roles this role is allowed to give to other staff. Managers cannot create admins.
     *
     * @return array<int, self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Admin => self::cases(),
            self::Manager => array_values(array_filter(self::cases(), fn (self $role) => $role !== self::Admin)),
            default => [],
        };
    }

    /**
     * @param  array<int, self>|null  $roles
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(?array $roles = null): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            $roles ?? self::cases(),
        );
    }
}
