<?php

namespace App\Services\GoLive;

/**
 * One row (or one group of rows, e.g. an order's lines) of a go-live file and what will / did happen
 * to it. "Plain" = a straightforward create with nothing to mention; every other row goes in the report.
 */
final class ImportRow
{
    public const CREATE = 'create';

    public const SKIP = 'skip';

    public const FAIL = 'fail';

    /** @var array<int, string> */
    public array $reasons = [];

    /** @var array<int, string> */
    public array $notes = [];

    public string $status = self::CREATE;

    /** @var array<string, mixed> parsed values the importer will write */
    public array $data = [];

    /**
     * @param  array<int, int>  $lines  file line numbers (several for grouped rows)
     * @param  array<string, string>  $raw  the row(s) as read, by header
     */
    public function __construct(public array $lines, public array $raw, public ?string $key = null) {}

    public function fail(string $reason): self
    {
        $this->reasons[] = $reason;
        $this->status = self::FAIL;

        return $this;
    }

    public function skip(string $reason): self
    {
        $this->notes[] = $reason;
        if ($this->status !== self::FAIL) {
            $this->status = self::SKIP;
        }

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function isPlain(): bool
    {
        return $this->status === self::CREATE && $this->notes === [] && $this->reasons === [];
    }

    public function message(): string
    {
        return implode(' ', [...$this->reasons, ...$this->notes]);
    }
}
