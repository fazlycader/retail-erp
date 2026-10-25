<?php

namespace App\Jobs;

use App\Models\WooSyncLog;
use App\Models\WooWebhookEvent;
use App\Services\Woo\OrderIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A recorded WooCommerce order webhook → OrderIntake. The payload travels in the job; it is kept on
 * the event only if processing fails (so staff can retry it from the Online store page).
 */
class ProcessWooWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $eventId, public array $payload) {}

    public function handle(OrderIntake $intake): void
    {
        $event = WooWebhookEvent::query()->find($this->eventId);
        if (! $event || $event->status === 'processed') {
            return;
        }

        // One order at a time (a created and an updated delivery can arrive together).
        Cache::lock('woo-order:'.($this->payload['id'] ?? 0), 60)->block(30, function () use ($intake) {
            $intake->process($this->payload, 'webhook');
        });

        $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'payload' => null, 'error' => null, 'attempts' => $event->attempts + 1])->save();
    }

    public function failed(Throwable $e): void
    {
        WooWebhookEvent::query()->whereKey($this->eventId)->update([
            'status' => 'failed', 'payload' => json_encode($this->payload), 'error' => mb_substr($e->getMessage(), 0, 500),
        ]);
        WooSyncLog::write('error', 'webhook', 'Woo order #'.($this->payload['number'] ?? $this->payload['id'] ?? '?').': '.$e->getMessage(), ['event_id' => $this->eventId]);
    }
}
