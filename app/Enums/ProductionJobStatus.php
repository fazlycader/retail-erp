<?php

namespace App\Enums;

enum ProductionJobStatus: string
{
    /** Waiting to start (workshop) or not yet ordered from the supplier. */
    case Pending = 'pending';
    case InProduction = 'in_production';
    /** Finished piece in stock, reserved for the customer's order. */
    case Ready = 'ready';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not started',
            self::InProduction => 'In production',
            self::Ready => 'Ready',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InProduction], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
