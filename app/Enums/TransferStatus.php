<?php

namespace App\Enums;

/** draft → requested → dispatched (in transit) → received | partially_received → closed | cancelled. */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case PartiallyReceived = 'partially_received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Requested => 'Requested',
            self::Dispatched => 'In transit',
            self::Received => 'Received',
            self::PartiallyReceived => 'Short receipt (discrepancy)',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canDispatch(): bool
    {
        return in_array($this, [self::Draft, self::Requested], true);
    }

    public function canReceive(): bool
    {
        return $this === self::Dispatched;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Requested], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
