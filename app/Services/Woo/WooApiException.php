<?php

namespace App\Services\Woo;

use RuntimeException;

/** WooCommerce answered with an error (or couldn't be reached) after the retries. */
class WooApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
