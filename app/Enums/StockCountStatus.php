<?php

namespace App\Enums;

/** open → counting → review → posted. */
enum StockCountStatus: string
{
    case Open = 'open';
    case Counting = 'counting';
    case Review = 'review';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Counting => 'Counting',
            self::Review => 'In review',
            self::Posted => 'Posted',
        };
    }

    public function editable(): bool
    {
        return in_array($this, [self::Open, self::Counting, self::Review], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
