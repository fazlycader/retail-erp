<?php

namespace App\Enums;

enum CreditNoteStatus: string
{
    case Open = 'open';
    case Used = 'used';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Used => 'Used',
            self::Voided => 'Voided',
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
