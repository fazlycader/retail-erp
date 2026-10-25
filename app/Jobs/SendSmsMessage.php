<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use App\Services\Sms\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one logged SMS (failures are recorded on the message; never retried automatically). */
class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(SmsService $sms): void
    {
        if ($message = SmsMessage::query()->find($this->messageId)) {
            $sms->deliver($message);
        }
    }
}
