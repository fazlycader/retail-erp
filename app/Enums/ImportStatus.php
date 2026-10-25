<?php

namespace App\Enums;

/** uploaded → (mapped) → checking → checked → committing → committed; failed = the run itself broke. */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Checking = 'checking';
    case Checked = 'checked';
    case Committing = 'committing';
    case Committed = 'committed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Waiting for column mapping',
            self::Checking => 'Dry run in progress',
            self::Checked => 'Dry run ready',
            self::Committing => 'Importing',
            self::Committed => 'Imported',
            self::Failed => 'Failed',
        };
    }

    public function running(): bool
    {
        return in_array($this, [self::Checking, self::Committing], true);
    }
}
