<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 8: daily summary tables for fast long-range reports (built from the same live queries),
 * background report exports, the daily owner email and the report settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('managers_see_profit')->default(false)->after('payout_approval_threshold');
            $table->json('report_emails')->nullable()->after('managers_see_profit');
            $table->string('daily_email_time', 5)->default('07:00')->after('report_emails'); // Asia/Colombo
        });

        // One row per location per business date (sales on invoices dated that day, returns,
        // payments and refunds taken that day). Money in cents.
        Schema::create('daily_location_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('invoices_count')->default(0);
            $table->bigInteger('items_gross')->default(0);
            $table->bigInteger('discounts')->default(0);
            $table->bigInteger('sales')->default(0); // invoice grand totals
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('delivery')->default(0);
            $table->bigInteger('revenue_ex_tax')->default(0); // item lines after discounts, without VAT
            $table->bigInteger('cost')->default(0);
            $table->unsignedInteger('returns_count')->default(0);
            $table->bigInteger('returns_total')->default(0);
            $table->bigInteger('returns_revenue_ex_tax')->default(0);
            $table->bigInteger('returns_cost')->default(0);
            $table->unsignedInteger('payments_count')->default(0);
            $table->bigInteger('collected')->default(0); // payments not voided
            $table->bigInteger('refunds')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->json('by_method')->nullable();
            $table->json('by_channel')->nullable();
            $table->json('by_salesperson')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'business_date']);
            $table->index('business_date');
        });

        // One row per location, business date and product variant (or custom item name).
        Schema::create('daily_variant_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name', 255);
            $table->string('sku', 64)->nullable();
            $table->string('category', 120)->nullable();
            $table->bigInteger('quantity')->default(0); // milli-units
            $table->bigInteger('revenue')->default(0); // line totals incl. VAT
            $table->bigInteger('revenue_ex_tax')->default(0);
            $table->bigInteger('discounts')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('returned_qty')->default(0);
            $table->bigInteger('returned_revenue')->default(0);
            $table->bigInteger('returned_revenue_ex_tax')->default(0);
            $table->bigInteger('returned_cost')->default(0);
            $table->date('last_sold_on')->nullable();

            $table->index(['location_id', 'business_date']);
            $table->index(['product_variant_id', 'business_date']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 40);
            $table->string('format', 4);
            $table->json('filters');
            $table->string('status', 10)->default('queued'); // queued | running | ready | failed
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // The daily owner email: one per business date (sent after the last location closes, or
        // by the schedule at daily_email_time).
        Schema::create('daily_report_sends', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->timestamp('sent_at');
            $table->json('unclosed_locations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['daily_report_sends', 'report_exports', 'daily_variant_summaries', 'daily_location_summaries'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['managers_see_profit', 'report_emails', 'daily_email_time']);
        });
    }
};
