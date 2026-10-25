<?php

namespace App\Enums;

enum StockCountScope: string
{
    case Full = 'full';
    case Category = 'category';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full location count',
            self::Category => 'Single category',
            self::Selected => 'Selected variants',
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
