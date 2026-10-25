<?php

namespace App\Enums;

enum SalesOrderKind: string
{
    case CashAndCarry = 'cash_and_carry';
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::CashAndCarry => 'Cash & Carry',
            self::Order => 'Sales Order',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
