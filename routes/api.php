<?php

use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\WooWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine endpoints (step 10, docs/WOOCOMMERCE.md).
*/

// WooCommerce webhooks: HMAC-signed with WOO_WEBHOOK_SECRET (checked in the controller).
Route::post('/woo/webhook', WooWebhookController::class)->middleware('throttle:120,1')->name('woo.webhook');

// The WordPress plugin: Sanctum token with the storefront:read ability.
Route::middleware(['auth:sanctum', 'abilities:storefront:read', 'throttle:120,1'])->prefix('storefront')->group(function () {
    Route::get('/ping', [StorefrontController::class, 'ping'])->name('storefront.ping');
    Route::get('/availability', [StorefrontController::class, 'availability'])->name('storefront.availability');
    Route::post('/enquiries', [StorefrontController::class, 'enquiry'])->name('storefront.enquiries');
});
