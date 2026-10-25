<?php

namespace App\Http\Controllers\Api;

use App\Enums\LocationType;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StorefrontEnquiry;
use App\Models\WooSyncLog;
use App\Rules\SriLankanPhone;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Called by the WordPress plugin (mvrk-woo-connector) with a Sanctum token that has the
 * storefront:read ability. No signatures, no secrets in WordPress beyond that token.
 *
 * - GET  /api/storefront/availability?sku=  per-showroom availability of one variant (buckets, not
 *   exact counts: "In stock", "Only 2 left", "Ask us").
 * - POST /api/storefront/enquiries          "Reserve to view in showroom" lead (rate limited per
 *   visitor IP, which the plugin forwards in X-Visitor-IP).
 */
class StorefrontController extends Controller
{
    public function availability(Request $request): JsonResponse
    {
        $sku = trim((string) $request->query('sku'));
        $variant = $sku === '' ? null : ProductVariant::query()->with('product:id,name,type,publish_online,is_active')->where('sku', $sku)->first();

        if (! $variant || ! $variant->is_active || ! $variant->product?->publish_online || ! $variant->product->is_active) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $showrooms = Location::query()->where('is_active', true)->where('type', LocationType::Showroom->value)->orderBy('id')->get(['id', 'name', 'code', 'address', 'phone']);
        $levels = StockLevel::query()->where('product_variant_id', $variant->id)->whereIn('location_id', $showrooms->pluck('id'))->get()->keyBy('location_id');
        $madeToOrder = $variant->product->type === ProductType::MadeToOrder;

        return response()->json([
            'sku' => $variant->sku,
            'name' => trim($variant->product->name.($variant->name ? ' — '.$variant->name : '')),
            'made_to_order' => $madeToOrder,
            'locations' => $showrooms->map(function (Location $l) use ($levels, $madeToOrder) {
                $units = intdiv(max(0, (int) (($levels[$l->id]?->on_hand ?? 0) - ($levels[$l->id]?->reserved ?? 0))), Quantity::SCALE);
                [$status, $label] = match (true) {
                    $units >= 3 => ['in_stock', 'In stock'],
                    $units > 0 => ['low', "Only {$units} left"],
                    $madeToOrder => ['order', 'Made to order'],
                    default => ['out', 'Ask us'],
                };

                return ['code' => $l->code, 'name' => $l->name, 'address' => $l->address, 'phone' => $l->phone ? SriLankanPhone::display($l->phone) : null, 'status' => $status, 'label' => $label];
            })->values(),
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function enquiry(Request $request): JsonResponse
    {
        $visitor = filter_var($request->header('X-Visitor-IP'), FILTER_VALIDATE_IP) ?: $request->ip();
        $key = 'storefront-enquiry:'.$request->user()?->currentAccessToken()?->id.':'.$visitor;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many requests. Please call the showroom instead.'], 429);
        }
        RateLimiter::hit($key, 3600);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', new SriLankanPhone],
            'email' => ['nullable', 'email', 'max:160'],
            'sku' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:10'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:1000'],
            'website' => ['prohibited'], // honeypot: bots fill every field
        ]);

        $variant = filled($data['sku'] ?? null) ? ProductVariant::query()->where('sku', $data['sku'])->first() : null;
        $location = filled($data['location'] ?? null) ? Location::query()->where('code', Str::upper($data['location']))->where('is_active', true)->first() : null;

        $enquiry = new StorefrontEnquiry;
        $enquiry->forceFill([
            'name' => trim($data['name']),
            'phone' => SriLankanPhone::normalize($data['phone']),
            'email' => $data['email'] ?? null,
            'sku' => $variant?->sku ?? ($data['sku'] ?? null),
            'product_variant_id' => $variant?->id,
            'location_id' => $location?->id,
            'preferred_date' => $data['preferred_date'] ?? null,
            'message' => $data['message'] ?? null,
            'ip' => $visitor,
        ])->save();

        WooSyncLog::write('info', 'enquiry', "Showroom visit request from {$enquiry->name} for ".($enquiry->sku ?? 'no item').'.', ['enquiry_id' => $enquiry->id]);

        return response()->json(['message' => 'Thank you. The showroom will call you to confirm.', 'id' => $enquiry->id], 201);
    }

    /** For the plugin's "Test connection". */
    public function ping(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'app' => config('app.name'), 'time' => now()->toIso8601String()]);
    }
}
