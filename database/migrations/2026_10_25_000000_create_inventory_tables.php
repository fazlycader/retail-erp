<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3: inventory, transfers, stock counts, adjustments, reservations, serialized units
 * and opening stock imports. Quantities are integer milli-units (App\Support\Quantity);
 * money is integer cents (App\Support\Money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Adjustments with a total value above this (in cents) require a manager PIN. Default LKR 10,000.00.
            $table->unsignedBigInteger('adjustment_approval_value')->default(1000000)->after('managers_edit_catalog');
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('on_hand')->default(0); // milli-units
            $table->unsignedBigInteger('reserved')->default(0); // milli-units
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_id', 'location_id']);
            $table->index(['location_id', 'sync_version']);
        });

        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('in_stock'); // App\Enums\StockUnitStatus
            $table->string('condition', 20)->default('new'); // App\Enums\StockUnitCondition
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->date('received_at')->nullable();
            $table->nullableMorphs('sold_reference');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'location_id', 'status']);
        });

        // Immutable ledger: never updated or deleted.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 30); // App\Enums\MovementType
            $table->bigInteger('quantity'); // signed milli-units
            $table->bigInteger('balance_after')->default(0); // on_hand after this movement
            $table->unsignedBigInteger('unit_cost')->default(0); // cents
            $table->nullableMorphs('reference');
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_variant_id', 'location_id', 'id']);
            $table->index(['location_id', 'business_date']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->nullableMorphs('reserved_for');
            $table->string('status', 20)->default('active'); // App\Enums\ReservationStatus
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'location_id', 'status']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('reason', 20); // App\Enums\AdjustmentReason
            $table->date('business_date');
            $table->unsignedBigInteger('total_value')->default(0); // cents
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->bigInteger('quantity'); // signed milli-units
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('line_value')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 24)->default('draft'); // App\Enums\TransferStatus
            $table->string('vehicle_note')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('resolution', 24)->nullable(); // write_off | return_to_source
            $table->string('resolution_note')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['from_location_id', 'status']);
            $table->index(['to_location_id', 'status']);
        });

        Schema::create('transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained('stock_units')->nullOnDelete();
            $table->unsignedBigInteger('requested_qty'); // milli-units
            $table->unsignedBigInteger('sent_qty')->default(0);
            $table->unsignedBigInteger('received_qty')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->string('discrepancy_reason')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('scope', 20)->default('full'); // App\Enums\StockCountScope
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('blind')->default(false);
            $table->string('status', 20)->default('open'); // App\Enums\StockCountStatus
            $table->date('business_date');
            $table->text('note')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->bigInteger('system_qty')->default(0); // frozen on_hand at start
            $table->bigInteger('counted_qty')->nullable();
            $table->bigInteger('variance_qty')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->bigInteger('variance_value')->default(0); // signed cents
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_variant_id']);
        });

        Schema::create('opening_stock_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('file_hash', 64)->nullable();
            $table->string('status', 20)->default('checked'); // checked | committed
            $table->unsignedInteger('total_rows')->default(0);
            $table->date('business_date')->nullable();
            $table->json('counts')->nullable(); // {create, fail, total_units, total_value}
            $table->text('error')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('dry_run_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('opening_stock_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_stock_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('sku', 64)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('quantity')->nullable(); // milli-units
            $table->unsignedBigInteger('unit_cost')->nullable(); // cents
            $table->json('serials')->nullable();
            $table->string('action', 12); // create | fail
            $table->json('reasons')->nullable();
            $table->string('result', 12)->nullable(); // created | failed
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'opening_stock_import_rows',
            'opening_stock_imports',
            'stock_count_lines',
            'stock_counts',
            'transfer_lines',
            'transfers',
            'stock_adjustment_lines',
            'stock_adjustments',
            'stock_reservations',
            'stock_movements',
            'stock_units',
            'stock_levels',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('company_settings', fn (Blueprint $table) => $table->dropColumn('adjustment_approval_value'));
    }
};
