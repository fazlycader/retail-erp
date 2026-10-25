<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money held as an integer in the smallest currency unit (cents).
 * Never construct money from floats; use fromCents() or fromDecimal() with a string.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public const DECIMALS = 2;

    public function __construct(
        public int $cents,
        public string $currency = 'LKR',
    ) {}

    public static function fromCents(int $cents, ?string $currency = null): self
    {
        return new self($cents, $currency ?? self::defaultCurrency());
    }

    /**
     * Parse a decimal string such as "1250.5" or "1,250.50" into cents without float maths.
     */
    public static function fromDecimal(string|int $amount, ?string $currency = null): self
    {
        $amount = str_replace([',', ' '], '', trim((string) $amount));

        if (! preg_match('/^(-)?(\d*)(?:\.(\d{0,'.self::DECIMALS.'}))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $cents = (int) ($m[2] ?: '0') * 10 ** self::DECIMALS
            + (int) str_pad($m[3] ?? '', self::DECIMALS, '0');

        return self::fromCents($m[1] === '-' ? -$cents : $cents, $currency);
    }

    public static function defaultCurrency(): string
    {
        return config('app.currency', 'LKR');
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->cents * $quantity, $this->currency);
    }

    /**
     * Plain decimal string, e.g. "1250.50". Useful for form inputs.
     */
    public function toDecimal(): string
    {
        $abs = abs($this->cents);
        $unit = 10 ** self::DECIMALS;

        return ($this->cents < 0 ? '-' : '')
            .intdiv($abs, $unit).'.'.str_pad((string) ($abs % $unit), self::DECIMALS, '0', STR_PAD_LEFT);
    }

    /**
     * Human format, e.g. "LKR 1,250.50".
     */
    public function format(bool $withCurrency = true): string
    {
        $abs = abs($this->cents);
        $unit = 10 ** self::DECIMALS;

        $formatted = number_format(intdiv($abs, $unit)).'.'.str_pad((string) ($abs % $unit), self::DECIMALS, '0', STR_PAD_LEFT);

        return ($this->cents < 0 ? '-' : '').($withCurrency ? $this->currency.' ' : '').$formatted;
    }

    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Cannot combine money in different currencies.');
        }
    }
}
