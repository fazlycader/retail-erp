<?php

namespace App\Enums;

enum SalesChannel: string
{
    case Showroom = 'showroom';
    case Phone = 'phone';
    case Web = 'web';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Showroom => 'Showroom',
            self::Phone => 'Phone',
            self::Web => 'Web',
            self::Whatsapp => 'WhatsApp',
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
