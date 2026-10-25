<?php

namespace App\Enums;

/** Where an outsourced job stands at the supplier. Received is set by the goods receipt. */
enum SupplierProgress: string
{
    case Ordered = 'ordered';
    case InProduction = 'in_production';
    case Shipped = 'shipped';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Ordered => 'Ordered',
            self::InProduction => 'In production',
            self::Shipped => 'Shipped',
            self::Received => 'Received',
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
