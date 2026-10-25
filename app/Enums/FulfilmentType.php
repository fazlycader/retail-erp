<?php

namespace App\Enums;

enum FulfilmentType: string
{
    case Collect = 'collect';
    case Deliver = 'deliver';

    public function label(): string
    {
        return match ($this) {
            self::Collect => 'Customer collection',
            self::Deliver => 'Home delivery',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }
}
