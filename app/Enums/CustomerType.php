<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Business => 'Business',
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
