<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case Ready = 'ready';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::InProduction => 'In production',
            self::Ready => 'Ready',
            self::PartiallyFulfilled => 'Partially fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canConfirm(): bool
    {
        return $this === self::Draft;
    }

    public function canFulfil(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::InProduction,
            self::Ready,
            self::PartiallyFulfilled,
        ], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Confirmed,
            self::InProduction,
            self::Ready,
        ], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
