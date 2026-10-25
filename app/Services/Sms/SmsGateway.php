<?php

namespace App\Services\Sms;

/** Sends one text message. Throws on failure; returns the provider's message reference. */
interface SmsGateway
{
    public function configured(): bool;

    /** @param  string  $to  +94XXXXXXXXX */
    public function send(string $to, string $body): ?string;
}
