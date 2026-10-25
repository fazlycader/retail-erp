<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\WooPluginPackage;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooWebhook;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StorefrontEnquiry;
use App\Models\WooLink;
use App\Models\WooSyncItem;
use App\Models\WooSyncLog;
use App\Models\WooWebhookEvent;
use App\Services\Woo\OnlineStock;
use App\Services\Woo\WooClient;
use App\Services\Woo\WooPusher;
use App\Services\Woo\WooReconciler;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Online store (admins and managers): WooCommerce sync status, the dirty queue and last errors (with
 * retry), unmatched SKUs, web orders needing attention, failed webhooks, showroom visit requests, the
 * online stock of every online variant with its buffer, and the settings (admins).
 */
class OnlineStoreController extends Controller
{
    public function index(Request $request, WooClient $woo): Response
    {
        $settings = CompanySetting::current();
        $variants = ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('publish_online', true))
            ->with('product:id,name,type')->orderBy('sku')->limit(500)->get(['id', 'product_id', 'sku', 'name', 'online_buffer', 'is_active']);
        $stock = OnlineStock::forVariants($variants->pluck('id')->all());
        $linked = WooLink::query()->where('local_type', WooLink::VARIANT)->whereIn('local_id', $variants->pluck('id'))->pluck('local_id')->flip();

        return Inertia::render('Admin/Online/Index', [
            'status' => [
                'configured' => $woo->configured(),
                'last_push_at' => $settings->woo_last_push_at?->toIso8601String(),
                'last_reconcile_at' => $settings->woo_last_reconcile_at?->toIso8601String(),
                'queue' => WooSyncItem::query()->count(),
                'failing' => WooSyncItem::query()->where('attempts', '>', 0)->count(),
                'linked_products' => WooLink::query()->where('local_type', WooLink::PRODUCT)->count(),
                'online_products' => Product::query()->where('publish_online', true)->count(),
            ],
            'failing' => WooSyncItem::query()->where('attempts', '>', 0)->latest('updated_at')->limit(20)->get()
                ->map(fn (WooSyncItem $i) => ['id' => $i->id, 'item' => $i->item_key, 'attempts' => $i->attempts, 'error' => $i->last_error, 'next_at' => $i->available_at?->toIso8601String()]),
            'log' => WooSyncLog::query()->whereIn('level', ['warning', 'error'])->latest('id')->limit(30)->get()
                ->map(fn (WooSyncLog $l) => ['level' => $l->level, 'action' => $l->action, 'message' => $l->message, 'at' => $l->created_at?->toIso8601String()]),
            'webhooks' => WooWebhookEvent::query()->where('status', 'failed')->latest('id')->limit(20)->get()
                ->map(fn (WooWebhookEvent $e) => ['id' => $e->id, 'topic' => $e->topic, 'woo_order_id' => $e->woo_order_id, 'error' => $e->error, 'at' => $e->created_at?->toIso8601String()]),
            'attention' => SalesOrder::query()->where('needs_attention', true)->whereNotNull('woo_order_id')->with('customer:id,name')->latest('id')->limit(30)->get()
                ->map(fn (SalesOrder $o) => ['id' => $o->id, 'number' => $o->number, 'woo_number' => $o->woo_order_number, 'woo_status' => $o->woo_status, 'customer' => $o->customer?->name, 'reason' => $o->attention_reason, 'grand_total' => $o->grand_total]),
            'unmatched' => Cache::get('woo:last-link-report'),
            'unlinked' => $variants->reject(fn ($v) => isset($linked[$v->id]))->pluck('sku')->take(50)->values(),
            'enquiries' => StorefrontEnquiry::query()->where('status', '!=', 'closed')->with(['location:id,code', 'variant:id,sku'])->latest('id')->limit(30)->get()
                ->map(fn (StorefrontEnquiry $e) => ['id' => $e->id, 'name' => $e->name, 'phone' => $e->phone, 'email' => $e->email, 'sku' => $e->sku, 'location' => $e->location?->code, 'preferred_date' => $e->preferred_date?->toDateString(), 'message' => $e->message, 'status' => $e->status, 'at' => $e->created_at?->toIso8601String()]),
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'sku' => $v->sku, 'name' => trim(($v->product?->name ?? '').($v->name ? ' — '.$v->name : '')),
                'made_to_order' => $v->product?->type?->value === 'made_to_order',
                'buffer' => Quantity::toDecimal($v->online_buffer), 'online' => $stock[$v->id] ?? 0, 'linked' => isset($linked[$v->id]),
            ]),
            'settings' => $request->user()->role === Role::Admin ? [
                'online_stock_locations' => $settings->onlineLocationIds(),
                'web_fulfilment_location_id' => $settings->web_fulfilment_location_id,
                'web_reserve_at' => $settings->web_reserve_at ?? 'fulfilment',
                'woo_sync_prices' => (bool) $settings->woo_sync_prices,
                'woo_paid_statuses' => $settings->wooPaidStatuses(),
                'woo_backorder_text' => $settings->woo_backorder_text,
            ] : null,
            'locations' => Location::query()->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']),
            'plugin' => ['version' => WooPluginPackage::version()],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403);
        $data = $request->validate([
            'online_stock_locations' => ['array'],
            'online_stock_locations.*' => ['integer', Rule::exists('locations', 'id')],
            'web_fulfilment_location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'web_reserve_at' => ['required', 'in:fulfilment,stock'],
            'woo_sync_prices' => ['boolean'],
            'woo_paid_statuses' => ['array', 'min:1'],
            'woo_paid_statuses.*' => ['string', 'in:pending,on-hold,processing,completed'],
            'woo_backorder_text' => ['nullable', 'string', 'max:120'],
        ]);

        $settings = CompanySetting::current();
        $before = $settings->only(array_keys($data));
        $settings->update([...$data, 'online_stock_locations' => array_values(array_map('intval', $data['online_stock_locations'] ?? [])), 'woo_sync_prices' => $request->boolean('woo_sync_prices')]);
        AuditLog::record('settings.online_store', null, 'Online store settings changed', $before, $settings->only(array_keys($data)), $request->user());

        // Which stock is online (or prices) may have changed: re-send everything.
        WooPusher::markAll();

        return back()->with('success', 'Online store settings saved. Every online product will be re-sent.');
    }

    public function updateBuffer(Request $request, ProductVariant $variant): RedirectResponse
    {
        $data = $request->validate(['buffer' => ['required', 'string', 'max:20']]);
        $variant->update(['online_buffer' => max(0, Quantity::parse($data['buffer']))]);

        return back()->with('success', "Online buffer for {$variant->sku} saved.");
    }

    public function pushAll(WooPusher $pusher): RedirectResponse
    {
        $count = WooPusher::markAll();
        $s = $pusher->run(20);

        return back()->with($s['failed'] ? 'error' : 'success', "{$count} product(s) queued; sent {$s['products']} product(s) and {$s['variations']} variation(s) now ({$s['skipped']} unchanged, {$s['failed']} failed). The rest go with the next run.");
    }

    public function reconcile(WooReconciler $reconciler): RedirectResponse
    {
        $s = $reconciler->run(25);

        return back()->with('success', "Checked {$s['orders']} order(s); {$s['stock_mismatches']} stock difference(s) queued.".($s['failed'] ? " {$s['failed']} failed (see the log)." : ''));
    }

    public function retryItem(WooSyncItem $item): RedirectResponse
    {
        $item->forceFill(['available_at' => null])->save();

        return back()->with('success', "{$item->item_key} will be retried with the next push.");
    }

    public function retryWebhook(WooWebhookEvent $event): RedirectResponse
    {
        abort_unless($event->status === 'failed' && is_array($event->payload), 422);
        $event->forceFill(['status' => 'received'])->save();
        ProcessWooWebhook::dispatch($event->id, $event->payload);

        return back()->with('success', 'The order will be processed again.');
    }

    public function resolve(Request $request, SalesOrder $order): RedirectResponse
    {
        abort_unless($request->user()->worksAt($order->location_id), 403);
        $order->forceFill(['needs_attention' => false])->save();
        AuditLog::record('woo.attention_resolved', $order, "Marked web order {$order->number} as handled", ['reason' => $order->attention_reason], null, $request->user());

        return back()->with('success', "{$order->number} marked as handled.");
    }

    public function enquiry(Request $request, StorefrontEnquiry $enquiry): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:new,contacted,closed']]);
        $enquiry->forceFill(['status' => $data['status'], 'handled_by' => $request->user()->id])->save();

        return back()->with('success', 'Visit request updated.');
    }

    public function plugin(): BinaryFileResponse
    {
        $path = WooPluginPackage::zipPath();
        if (! is_file($path)) {
            WooPluginPackage::build();
        }

        return response()->download($path);
    }
}
