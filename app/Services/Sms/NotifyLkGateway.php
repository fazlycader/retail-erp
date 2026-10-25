<?php

namespace App\Services\Sms;

use App\Facades\Integrations;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Notify.lk (Sri Lanka). Credentials come from Settings → Integrations → SMS. */
final class NotifyLkGateway implements SmsGateway
{
    public const URL = 'https://app.notify.lk/api/v1/send';

    public function configured(): bool
    {
        return filled(Integrations::get('NOTIFYLK_USER_ID')) && filled(Integrations::get('NOTIFYLK_API_KEY')) && filled(Integrations::get('NOTIFYLK_SENDER_ID'));
    }

    public function send(string $to, string $body): ?string
    {
        $response = Http::asForm()->timeout(10)->retry([500, 2000], throw: false)->post(self::URL, [
            'user_id' => Integrations::get('NOTIFYLK_USER_ID'),
            'api_key' => Integrations::get('NOTIFYLK_API_KEY'),
            'sender_id' => Integrations::get('NOTIFYLK_SENDER_ID'),
            'to' => ltrim($to, '+'), // 94XXXXXXXXX
            'message' => $body,
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException('Notify.lk '.$response->status().': '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200));
        }

        return is_scalar($response->json('data')) ? (string) $response->json('data') : null;
    }
}
