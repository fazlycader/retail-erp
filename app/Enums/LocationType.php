<?php

namespace App\Enums;

enum LocationType: string
{
    case Warehouse = 'warehouse';
    case Showroom = 'showroom';
    /** Where custom pieces are made (production stages, raw materials, jobs board). */
    case Workshop = 'workshop';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
