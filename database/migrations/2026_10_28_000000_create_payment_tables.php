<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6: payment methods, payments (receipts), credit notes, refunds, sales returns, held POS carts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_days')->default(7)->after('cash_rounding_unit');
            $table->unsignedSmallInteger('credit_note_expiry_days')->default(365)->after('return_days'); // 0 = never expires
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('share_token', 40)->nullable()->unique()->after('invoice_number');
            $table->unsignedBigInteger('returned_total')->default(0)->after('balance_due'); // cents, value of returned goods
            $table->unsignedBigInteger('refunded_total')->default(0)->after('returned_total'); // cents, refunds + credit notes issued from returns
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique(); // App\Enums\PaymentMethodCode
            $table->string('name', 60);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('opens_cash_drawer')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('payment_methods')->insert(array_map(fn (array $row) => $row + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now], [
            ['code' => 'cash', 'name' => 'Cash', 'requires_reference' => false, 'opens_cash_drawer' => true, 'sort' => 1],
            ['code' => 'card', 'name' => 'Card', 'requires_reference' => true, 'opens_cash_drawer' => false, 'sort' => 2],
            ['code' => 'bank_transfer', 'name' => 'Bank transfer', 'requires_reference' => true, 'opens_cash_drawer' => false, 'sort' => 3],
            ['code' => 'cheque', 'name' => 'Cheque', 'requires_reference' => false, 'opens_cash_drawer' => false, 'sort' => 4],
            ['code' => 'lankaqr', 'name' => 'LankaQR', 'requires_reference' => true, 'opens_cash_drawer' => false, 'sort' => 5],
            ['code' => 'online', 'name' => 'Online (web)', 'requires_reference' => true, 'opens_cash_drawer' => false, 'sort' => 6],
            ['code' => 'credit_note', 'name' => 'Credit note', 'requires_reference' => false, 'opens_cash_drawer' => false, 'sort' => 7],
            ['code' => 'other', 'name' => 'Other', 'requires_reference' => true, 'opens_cash_drawer' => false, 'sort' => 8],
        ]));

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 20); // App\Enums\ReturnOutcome
            $table->unsignedBigInteger('total'); // cents, value of the returned goods
            $table->unsignedBigInteger('settled_amount')->default(0); // cents refunded or credited (the rest only reduced the balance due)
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('exchange_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->date('business_date');
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->string('condition', 20); // App\Enums\ReturnCondition
            $table->foreignId('restock_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('amount'); // cents
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('share_token', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount'); // cents
            $table->unsignedBigInteger('remaining'); // cents
            $table->date('expires_on')->nullable();
            $table->string('status', 16)->default('open'); // App\Enums\CreditNoteStatus
            $table->string('reason', 255)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('share_token', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete(); // null = on-account
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // cents applied to the order / account
            $table->unsignedBigInteger('tendered')->nullable(); // cash handed over
            $table->unsignedBigInteger('change_given')->default(0);
            $table->string('reference', 100)->nullable();
            $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cheque_bank', 80)->nullable();
            $table->string('cheque_branch', 80)->nullable();
            $table->string('cheque_number', 30)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_status', 16)->nullable(); // App\Enums\ChequeStatus
            $table->timestamp('cheque_status_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable()->index(); // FK added in step 7
            $table->date('business_date');
            $table->string('status', 16)->default('completed'); // App\Enums\PaymentRecordStatus
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('void_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
            $table->index(['sales_order_id', 'status']);
            $table->index(['cheque_status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_return_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // cents
            $table->string('reference', 100)->nullable();
            $table->string('reason', 255);
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable()->index(); // step 7: cash refunds need an open shift
            $table->date('business_date');
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
        });

        Schema::create('pos_held_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 80);
            $table->json('cart');
            $table->timestamps();

            $table->index(['location_id', 'id']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['notifications', 'pos_held_carts', 'refunds', 'payments', 'credit_notes', 'sales_return_lines', 'sales_returns', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'returned_total', 'refunded_total']);
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['return_days', 'credit_note_expiry_days']);
        });
    }
};
