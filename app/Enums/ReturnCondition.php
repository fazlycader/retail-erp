<?php

namespace App\Enums;

enum ReturnCondition: string
{
    case Resaleable = 'resaleable';
    case Display = 'display';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Resaleable => 'Resaleable',
            self::Display => 'Display piece',
            self::Damaged => 'Damaged',
        };
    }

    public function stockUnitCondition(): StockUnitCondition
    {
        return match ($this) {
            self::Resaleable => StockUnitCondition::New,
            self::Display => StockUnitCondition::Display,
            self::Damaged => StockUnitCondition::Damaged,
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
