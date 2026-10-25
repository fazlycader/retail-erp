<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5: Customers, quotations, sales orders, sales order lines, and status history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('quote_validity_days')->default(14)->after('adjustment_approval_value');
            $table->unsignedInteger('sales_max_discount_bp')->default(1000)->after('quote_validity_days'); // 10%
            $table->unsignedInteger('cashier_max_discount_bp')->default(500)->after('sales_max_discount_bp'); // 5%
            $table->unsignedInteger('require_advance_bp')->default(0)->after('cashier_max_discount_bp');
            $table->unsignedInteger('cash_rounding_unit')->default(0)->after('require_advance_bp'); // cents (0 = off, 100 = Rs.1)
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('phone', 20)->unique(); // normalized +94XXXXXXXXX
            $table->string('secondary_phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('nic', 20)->nullable()->index();
            $table->string('type', 20)->default('individual'); // App\Enums\CustomerType
            $table->string('vat_number', 60)->nullable();
            $table->json('addresses')->nullable(); // up to 5 addresses [{label, line1, line2, city, district, maps_url, is_default}]
            $table->text('notes')->nullable();
            $table->boolean('sms_opt_out')->default(false);
            $table->unsignedBigInteger('credit_limit')->default(0); // cents (for Step 13)
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_reason')->nullable();
            $table->timestamp('first_purchase_at')->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->unsignedInteger('total_orders_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0); // cents
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('share_token', 40)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('draft'); // App\Enums\QuotationStatus
            $table->string('lost_reason')->nullable();
            $table->date('valid_until');
            $table->date('follow_up_date')->nullable();
            $table->boolean('prices_include_tax')->default(true);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('items_gross_total')->default(0);
            $table->unsignedBigInteger('line_discount_total')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->string('order_discount_type', 16)->nullable(); // amount | percent
            $table->unsignedBigInteger('order_discount_value')->default(0);
            $table->unsignedBigInteger('order_discount_cents')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('delivery_charge')->default(0);
            $table->bigInteger('rounding_cents')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('converted_sales_order_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['follow_up_date', 'status']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_service')->default(false);
            $table->string('sku_snapshot', 64)->nullable();
            $table->string('name_snapshot', 255);
            $table->json('attributes_snapshot')->nullable();
            $table->text('custom_spec')->nullable();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->unsignedBigInteger('unit_price'); // cents
            $table->string('discount_type', 16)->nullable(); // amount | percent
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('line_subtotal')->default(0);
            $table->unsignedBigInteger('order_discount_share')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('invoice_number', 32)->nullable()->unique();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // selling location
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->default('showroom'); // App\Enums\SalesChannel
            $table->string('kind', 24)->default('order'); // App\Enums\SalesOrderKind
            $table->string('status', 24)->default('draft'); // App\Enums\SalesOrderStatus
            $table->string('payment_status', 24)->default('unpaid'); // App\Enums\PaymentStatus
            $table->unsignedBigInteger('advance_required_cents')->default(0);
            $table->boolean('advance_pending')->default(false);
            $table->boolean('prices_include_tax')->default(true);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('items_gross_total')->default(0);
            $table->unsignedBigInteger('line_discount_total')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->string('order_discount_type', 16)->nullable();
            $table->unsignedBigInteger('order_discount_value')->default(0);
            $table->unsignedBigInteger('order_discount_cents')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('delivery_charge')->default(0);
            $table->bigInteger('rounding_cents')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('balance_due')->default(0);
            $table->string('fulfilment_type', 20)->default('collect'); // App\Enums\FulfilmentType
            $table->json('delivery_address')->nullable();
            $table->date('requested_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->date('business_date')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['business_date', 'location_id']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_service')->default(false);
            $table->string('sku_snapshot', 64)->nullable();
            $table->string('name_snapshot', 255);
            $table->json('attributes_snapshot')->nullable();
            $table->text('custom_spec')->nullable();
            $table->string('source', 24)->default('stock'); // App\Enums\SalesLineSource
            $table->foreignId('fulfil_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->unsignedBigInteger('reserved_qty')->default(0);
            $table->unsignedBigInteger('fulfilled_qty')->default(0);
            $table->unsignedBigInteger('returned_qty')->default(0);
            $table->unsignedBigInteger('unit_price'); // cents
            $table->unsignedBigInteger('min_price_snapshot')->default(0); // cents
            $table->unsignedBigInteger('unit_cost_snapshot')->default(0); // cents
            $table->string('discount_type', 16)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('line_subtotal')->default(0);
            $table->unsignedBigInteger('order_discount_share')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['sales_order_id', 'source']);
        });

        Schema::create('sales_order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sales_order_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'sales_order_status_histories',
            'sales_order_lines',
            'sales_orders',
            'quotation_lines',
            'quotations',
            'customers',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'quote_validity_days',
                'sales_max_discount_bp',
                'cashier_max_discount_bp',
                'require_advance_bp',
                'cash_rounding_unit',
            ]);
        });
    }
};
