<?php

namespace App\Services\Sms;

use App\Jobs\SendSmsMessage;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\SmsMessage;
use App\Rules\SriLankanPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Customer text messages from editable templates (company_settings.sms_templates). Every message is
 * logged in sms_messages; a dedupe key ("job:5:ready") makes sure a customer never gets the same
 * update twice. Sending happens on the queue after the surrounding transaction commits. With SMS
 * turned off or not set up, the message is logged as skipped (so staff can see what would have gone).
 */
class SmsService
{
    public function __construct(private SmsGateway $gateway) {}

    /** "{customer}" placeholders → values. Unknown placeholders are left out. */
    public static function render(string $template, array $vars): string
    {
        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template);

        return trim(preg_replace('/[ \t]+/', ' ', (string) $text));
    }

    /**
     * @param  array<string, string|int|null>  $vars
     */
    public function queue(string $template, ?Customer $customer, array $vars, ?Model $related = null, ?string $dedupeKey = null, ?int $locationId = null): ?SmsMessage
    {
        $phone = $customer?->phone ? SriLankanPhone::normalize($customer->phone) : null;
        if (! $phone || ($dedupeKey && SmsMessage::query()->where('dedupe_key', $dedupeKey)->exists())) {
            return null;
        }

        $settings = CompanySetting::current();
        $skip = match (true) {
            ! $settings->sms_enabled => 'SMS sending is turned off (Workshop → Setup).',
            ! $this->gateway->configured() => 'SMS is not set up (Settings → Integrations → SMS).',
            default => null,
        };

        $message = SmsMessage::query()->create([
            'location_id' => $locationId,
            'customer_id' => $customer?->id,
            'to_phone' => $phone,
            'template' => $template,
            'body' => self::render($settings->smsTemplate($template), $vars + ['company' => $settings->name, 'phone' => $settings->phone ? SriLankanPhone::display($settings->phone) : '']),
            'status' => $skip ? 'skipped' : 'queued',
            'error' => $skip,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'dedupe_key' => $dedupeKey,
        ]);

        if (! $skip) {
            DB::afterCommit(fn () => SendSmsMessage::dispatch($message->id));
        }

        return $message;
    }

    public function deliver(SmsMessage $message): void
    {
        if ($message->status !== 'queued') {
            return;
        }

        try {
            $ref = $this->gateway->send($message->to_phone, $message->body);
            $message->forceFill(['status' => 'sent', 'provider_ref' => $ref, 'sent_at' => now(), 'error' => null])->save();
        } catch (\Throwable $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }
    }
}
