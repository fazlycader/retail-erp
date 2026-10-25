<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A manager approval (picked manager + PIN) was missing, wrong or rate limited. */
class ApprovalRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
    }
}
