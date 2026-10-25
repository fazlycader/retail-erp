<?php

namespace App\Enums;

enum ProductionSource: string
{
    case Workshop = 'workshop';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Workshop => 'Our workshop',
            self::Supplier => 'Outside supplier',
        };
    }
}
