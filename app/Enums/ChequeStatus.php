<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Pending = 'pending';
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Deposited => 'Deposited',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
        };
    }

    /** Statuses a cheque may move to from this one. */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Deposited, self::Cleared, self::Bounced],
            self::Deposited => [self::Cleared, self::Bounced],
            self::Cleared, self::Bounced => [],
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
