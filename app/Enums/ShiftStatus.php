<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed'; // counted; waiting for manager approval (variance over the threshold)
    case Approved = 'approved'; // closed and settled (within threshold, or approved)

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Needs approval',
            self::Approved => 'Approved',
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
