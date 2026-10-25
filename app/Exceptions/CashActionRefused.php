<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A shift, cash, deposit or day-close action that can't go ahead. $reason is a short code for
 * screens: invalid, conflict, shift_required, note_required, blocked, warnings, day_locked.
 */
class CashActionRefused extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->reason === 'conflict' ? 409 : 422);
    }
}
