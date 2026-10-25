<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }

    public function canClose(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived, self::Received], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
