<?php

namespace App\Enums;

enum SalesLineSource: string
{
    case Stock = 'stock';
    case SupplierOrder = 'supplier_order';
    case Make = 'make';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'From stock',
            self::SupplierOrder => 'Back-to-back supplier PO',
            self::Make => 'Made to order (workshop)',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
