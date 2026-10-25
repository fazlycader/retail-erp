<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case Damaged = 'damaged';
    case Found = 'found';
    case Lost = 'lost';
    case Correction = 'correction';
    case Sample = 'sample';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Found => 'Found / surplus',
            self::Lost => 'Lost / theft',
            self::Correction => 'Data correction',
            self::Sample => 'Sample / showroom use',
            self::Other => 'Other (with note)',
        };
    }

    public function defaultDirection(): ?int
    {
        return match ($this) {
            self::Damaged, self::Lost, self::Sample => -1,
            self::Found => 1,
            self::Correction, self::Other => null,
        };
    }

    /**
     * @return array<int, array{value: string, label: string, direction: int|null}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r) => [
            'value' => $r->value,
            'label' => $r->label(),
            'direction' => $r->defaultDirection(),
        ], self::cases());
    }
}
