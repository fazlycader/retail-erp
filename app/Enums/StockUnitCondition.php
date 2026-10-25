<?php

namespace App\Enums;

enum StockUnitCondition: string
{
    case New = 'new';
    case Display = 'display';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Display => 'Display piece',
            self::Damaged => 'Damaged',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
