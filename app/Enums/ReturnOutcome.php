<?php

namespace App\Enums;

enum ReturnOutcome: string
{
    case Refund = 'refund';
    case CreditNote = 'credit_note';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::CreditNote => 'Credit note',
            self::Exchange => 'Exchange',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
