<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10: WooCommerce. Integration credentials (encrypted, edited on the Integrations screen),
 * links between local records and Woo ids, the outbound sync queue, inbound webhook deliveries, a
 * sync log, online-store settings, web-order fields on sales orders and storefront enquiries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // External-service credentials (ported from the dental system). Values are ENCRYPTED with APP_KEY.
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('key_name', 80)->unique();      // e.g. WOO_CONSUMER_KEY
            $table->text('value')->nullable();              // encrypted; null = not set
            $table->text('previous_value')->nullable();     // one step back (encrypted), swapped by Undo
            $table->timestamp('previous_changed_at')->nullable();
            $table->boolean('is_secret')->default(true);
            $table->string('description', 200);
            $table->text('guide')->nullable();              // markdown: "How do I get this?"
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('provider');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->json('online_stock_locations')->nullable()->after('daily_email_time'); // location ids whose stock is sold online
            $table->foreignId('web_fulfilment_location_id')->nullable()->after('online_stock_locations')->constrained('locations')->nullOnDelete();
            $table->string('web_reserve_at', 12)->default('fulfilment')->after('web_fulfilment_location_id'); // fulfilment | stock
            $table->boolean('woo_sync_prices')->default(true)->after('web_reserve_at');
            $table->json('woo_paid_statuses')->nullable()->after('woo_sync_prices'); // default ["processing","completed"]
            $table->string('woo_backorder_text', 120)->nullable()->after('woo_paid_statuses'); // e.g. "Made to order: 3–4 weeks"
            $table->timestamp('woo_order_cursor')->nullable()->after('woo_backorder_text'); // reconcile: orders modified after this
            $table->timestamp('woo_last_push_at')->nullable()->after('woo_order_cursor');
            $table->timestamp('woo_last_reconcile_at')->nullable()->after('woo_last_push_at');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('online_buffer')->default(0)->after('reorder_qty'); // milli-units held back from the web
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('woo_order_id')->nullable()->unique()->after('quotation_id');
            $table->string('woo_order_number', 40)->nullable()->after('woo_order_id');
            $table->string('woo_status', 30)->nullable()->after('woo_order_number');
            $table->boolean('needs_attention')->default(false)->after('woo_status');
            $table->text('attention_reason')->nullable()->after('needs_attention');
        });

        // Local record ↔ WooCommerce id (product, variant, category, order).
        Schema::create('woo_links', function (Blueprint $table) {
            $table->id();
            $table->string('local_type', 20); // product | variant | category | order
            $table->unsignedBigInteger('local_id');
            $table->unsignedBigInteger('woo_id');
            $table->unsignedBigInteger('woo_parent_id')->nullable(); // a variation's Woo product
            $table->string('last_pushed_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['local_type', 'local_id']);
            $table->index(['local_type', 'woo_id']);
        });

        // Dirty items waiting to be pushed; one row per item (coalesced by item_key).
        Schema::create('woo_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 60)->unique(); // product:12 | order:34
            $table->string('kind', 20);               // product | order_status
            $table->unsignedBigInteger('subject_id');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable(); // backoff after a failure
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index('available_at');
        });

        Schema::create('woo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 80)->unique();
            $table->string('topic', 40);
            $table->unsignedBigInteger('woo_order_id')->nullable()->index();
            $table->string('status', 12)->default('received'); // received | processed | failed
            $table->json('payload')->nullable(); // kept only when processing failed (for retry)
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woo_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('level', 8);   // info | warning | error
            $table->string('action', 40); // push | link | webhook | intake | reconcile | status
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['level', 'id']);
        });

        // "Reserve to view in showroom" leads from the WordPress plugin.
        Schema::create('storefront_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('email', 160)->nullable();
            $table->string('sku', 64)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->date('preferred_date')->nullable();
            $table->string('message', 1000)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('status', 12)->default('new'); // new | contacted | closed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['storefront_enquiries', 'woo_sync_log', 'woo_webhook_events', 'woo_sync_queue', 'woo_links'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique(['woo_order_id']);
            $table->dropColumn(['woo_order_id', 'woo_order_number', 'woo_status', 'needs_attention', 'attention_reason']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('online_buffer');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('web_fulfilment_location_id');
            $table->dropColumn(['online_stock_locations', 'web_reserve_at', 'woo_sync_prices', 'woo_paid_statuses', 'woo_backorder_text', 'woo_order_cursor', 'woo_last_push_at', 'woo_last_reconcile_at']);
        });

        Schema::dropIfExists('integration_credentials');
    }
};
