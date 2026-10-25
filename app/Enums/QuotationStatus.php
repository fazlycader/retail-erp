<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Lost = 'lost';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
            self::Lost => 'Lost',
            self::Converted => 'Converted to order',
        };
    }

    public function canEdit(): bool
    {
        return in_array($this, [self::Draft, self::Sent], true);
    }

    public function canConvert(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::Accepted], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
