<?php

namespace App\Enums;

enum PaymentMethodCode: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case LankaQr = 'lankaqr';
    case Online = 'online';
    case CreditNote = 'credit_note';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::LankaQr => 'LankaQR',
            self::Online => 'Online (web)',
            self::CreditNote => 'Credit note',
            self::Other => 'Other',
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
