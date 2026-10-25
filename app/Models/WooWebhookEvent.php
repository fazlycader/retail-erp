<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One WooCommerce webhook delivery (recorded once per X-WC-Webhook-Delivery-ID). */
class WooWebhookEvent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
