<?php

namespace App\Enums;

enum CashMovementType: string
{
    case PayIn = 'pay_in';
    case PayOut = 'pay_out';
    case CashDrop = 'cash_drop';

    public function label(): string
    {
        return match ($this) {
            self::PayIn => 'Pay-in',
            self::PayOut => 'Pay-out',
            self::CashDrop => 'Cash drop',
        };
    }

    /** +1 adds cash to the drawer, −1 takes it out. */
    public function sign(): int
    {
        return $this === self::PayIn ? 1 : -1;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
