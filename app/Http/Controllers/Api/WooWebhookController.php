<?php

namespace App\Http\Controllers\Api;

use App\Facades\Integrations;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooWebhook;
use App\Models\WooSyncLog;
use App\Models\WooWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/woo/webhook (order.created, order.updated). Checks X-WC-Webhook-Signature (base64
 * HMAC-SHA256 of the raw body with WOO_WEBHOOK_SECRET, constant-time), records each delivery once
 * (X-WC-Webhook-Delivery-ID), answers at once and leaves the work to a queued job. A bad or missing
 * signature is refused with 401 and logged without the body.
 */
class WooWebhookController extends Controller
{
    private const TOPICS = ['order.created', 'order.updated'];

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! self::validSignature($raw, (string) $request->header('X-WC-Webhook-Signature'))) {
            WooSyncLog::write('warning', 'webhook', 'Refused a webhook with a missing or wrong signature.', [
                'ip' => $request->ip(), 'topic' => $request->header('X-WC-Webhook-Topic'), 'delivery_id' => $request->header('X-WC-Webhook-Delivery-ID'),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $deliveryId = (string) $request->header('X-WC-Webhook-Delivery-ID');

        // WooCommerce pings a new webhook with "webhook_id=…" to check the URL works.
        if ($topic === '' && str_starts_with($raw, 'webhook_id=')) {
            return response()->json(['status' => 'pong']);
        }

        if ($deliveryId === '' || ! in_array($topic, self::TOPICS, true)) {
            return response()->json(['status' => 'ignored']);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Not JSON.'], 400);
        }

        try {
            $event = new WooWebhookEvent;
            $event->forceFill(['delivery_id' => $deliveryId, 'topic' => $topic, 'woo_order_id' => (int) ($payload['id'] ?? 0) ?: null, 'status' => 'received'])->save();
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']); // redelivery: already recorded
        }

        ProcessWooWebhook::dispatch($event->id, $payload);

        return response()->json(['status' => 'queued']);
    }

    public static function validSignature(string $raw, string $signature): bool
    {
        $secret = (string) Integrations::get('WOO_WEBHOOK_SECRET');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $signature);
    }
}
