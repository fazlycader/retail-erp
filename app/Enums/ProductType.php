<?php

namespace App\Enums;

enum ProductType: string
{
    /** Normally kept in stock. */
    case Stock = 'stock';
    /** Ordered from the factory when sold. */
    case MadeToOrder = 'made_to_order';
    /** Delivery, assembly, fabric treatment…: never stocked. */
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stock item',
            self::MadeToOrder => 'Made to order',
            self::Service => 'Service',
        };
    }

    public function keepsStock(): bool
    {
        return $this !== self::Service;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
