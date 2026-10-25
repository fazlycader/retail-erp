<?php

namespace App\Enums;

enum StockUnitStatus: string
{
    case InStock = 'in_stock';
    case Reserved = 'reserved';
    case InTransit = 'in_transit';
    case Sold = 'sold';
    case Returned = 'returned';
    case Damaged = 'damaged';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In stock',
            self::Reserved => 'Reserved',
            self::InTransit => 'In transit',
            self::Sold => 'Sold',
            self::Returned => 'Returned',
            self::Damaged => 'Damaged',
            self::WrittenOff => 'Written off',
        };
    }

    public function countsAsOnHand(): bool
    {
        return in_array($this, [self::InStock, self::Reserved], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
