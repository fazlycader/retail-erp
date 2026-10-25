<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4: Suppliers, purchase orders, goods received notes (GRNs), supplier returns,
 * supplier bills and supplier payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('contact_person', 120)->nullable()->after('name');
            $table->string('phone', 40)->nullable()->after('contact_person');
            $table->string('secondary_phone', 40)->nullable()->after('phone');
            $table->string('email')->nullable()->after('secondary_phone');
            $table->text('address')->nullable()->after('email');
            $table->unsignedSmallInteger('payment_terms_days')->default(30)->after('address');
            $table->unsignedBigInteger('credit_limit')->default(0)->after('payment_terms_days'); // cents
            $table->text('bank_details')->nullable()->after('credit_limit'); // encrypted cast
            $table->string('vat_number', 60)->nullable()->after('bank_details');
            $table->text('notes')->nullable()->after('vat_number');
            $table->boolean('is_active')->default(true)->after('notes');
            $table->unsignedBigInteger('sync_version')->default(0)->after('is_active');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // deliver-to location
            $table->string('status', 24)->default('draft'); // App\Enums\PurchaseOrderStatus
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0); // cents
            $table->unsignedBigInteger('discount_total')->default(0); // cents
            $table->unsignedBigInteger('tax_total')->default(0); // cents
            $table->unsignedBigInteger('total')->default(0); // cents
            $table->unsignedBigInteger('total_amount')->default(0); // cents
            $table->text('note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('supplier_sku', 120)->nullable();
            // Optional back-to-back link to a sales_order_line (table added in Step 5).
            $table->unsignedBigInteger('sales_order_line_id')->nullable()->index();
            $table->unsignedBigInteger('ordered_qty'); // milli-units
            $table->unsignedBigInteger('received_qty')->default(0); // milli-units
            $table->unsignedBigInteger('unit_cost')->default(0); // cents
            $table->unsignedBigInteger('discount_cents')->default(0); // cents
            $table->unsignedInteger('tax_rate_bp')->default(0); // basis points
            $table->unsignedBigInteger('tax_cents')->default(0); // cents
            $table->unsignedBigInteger('line_total')->default(0); // cents (including tax, after discount)
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'purchase_order_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('supplier_delivery_note', 120)->nullable();
            $table->date('business_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0); // cents
            $table->unsignedBigInteger('tax_total')->default(0); // cents
            $table->unsignedBigInteger('total')->default(0); // cents
            $table->unsignedBigInteger('total_amount')->default(0); // cents
            $table->text('note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'received_at']);
            $table->index(['supplier_id', 'received_at']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('received_qty'); // milli-units (total received including damaged_qty)
            $table->unsignedBigInteger('damaged_qty')->default(0); // milli-units (damaged on arrival -> damage movement)
            $table->unsignedBigInteger('unit_cost')->default(0); // cents
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->json('serials')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->date('business_date')->nullable();
            $table->date('returned_at')->nullable();
            $table->string('reason');
            $table->unsignedBigInteger('credit_expected')->default(0); // cents
            $table->unsignedBigInteger('tax_total')->default(0); // cents
            $table->unsignedBigInteger('total')->default(0); // cents
            $table->text('note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'returned_at']);
            $table->index(['supplier_id', 'returned_at']);
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->unsignedBigInteger('unit_cost')->default(0); // cents
            $table->unsignedBigInteger('line_total')->default(0); // cents
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_invoice_number', 120);
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0); // cents
            $table->unsignedBigInteger('tax_total')->default(0); // cents
            $table->unsignedBigInteger('total_amount')->default(0); // cents
            $table->unsignedBigInteger('paid_amount')->default(0); // cents
            $table->string('status', 20)->default('unpaid'); // App\Enums\SupplierBillStatus
            $table->text('note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_invoice_number']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('goods_receipt_supplier_bill', function (Blueprint $table) {
            $table->foreignId('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();

            $table->primary(['supplier_bill_id', 'goods_receipt_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('payment_date')->nullable();
            $table->date('paid_at')->nullable();
            $table->unsignedBigInteger('amount'); // cents
            $table->unsignedBigInteger('unallocated_amount')->default(0); // cents
            $table->string('method', 24)->default('bank_transfer'); // App\Enums\SupplierPaymentMethod
            $table->string('cheque_number', 80)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('cheque_bank', 120)->nullable();
            $table->string('reference', 160)->nullable();
            $table->text('note')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['supplier_id', 'paid_at']);
        });

        Schema::create('supplier_bill_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // cents
            $table->timestamps();

            $table->index(['supplier_bill_id', 'supplier_payment_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'supplier_bill_payment',
            'supplier_payments',
            'goods_receipt_supplier_bill',
            'supplier_bills',
            'supplier_return_lines',
            'supplier_returns',
            'goods_receipt_lines',
            'goods_receipts',
            'purchase_order_lines',
            'purchase_orders',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'contact_person', 'phone', 'secondary_phone', 'email', 'address',
                'payment_terms_days', 'credit_limit', 'bank_details', 'vat_number',
                'notes', 'is_active', 'sync_version',
            ]);
        });
    }
};
