<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 7: registers per location, shifts, cash movements and categories, bank deposits (cash from
 * the safe or a shift + cheques in bulk), end of day per location (business_days) and the cash
 * settings. Payments and refunds already carry a nullable shift_id (step 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->boolean('require_shift_for_payments')->default(true)->after('credit_note_expiry_days');
            $table->boolean('blind_close')->default(true)->after('require_shift_for_payments');
            $table->unsignedBigInteger('variance_approval_threshold')->default(50000)->after('blind_close'); // cents (Rs. 500)
            $table->unsignedBigInteger('payout_approval_threshold')->default(500000)->after('variance_approval_threshold'); // cents (Rs. 5,000)
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('invoice_printed_at')->nullable()->after('invoice_number');
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'name']);
        });

        $now = now();
        foreach (DB::table('locations')->pluck('id') as $locationId) {
            DB::table('registers')->insert(['location_id' => $locationId, 'name' => 'Main Counter', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('cash_movement_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('applies_to', 10)->default('any'); // any | pay_in | pay_out | cash_drop
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('cash_movement_categories')->insert(array_map(fn (array $row) => $row + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now], [
            ['name' => 'Float top-up', 'applies_to' => 'pay_in', 'sort_order' => 1],
            ['name' => 'Delivery charges collected', 'applies_to' => 'pay_in', 'sort_order' => 2],
            ['name' => 'Supplier purchase', 'applies_to' => 'pay_out', 'sort_order' => 3],
            ['name' => 'Transport / fuel', 'applies_to' => 'pay_out', 'sort_order' => 4],
            ['name' => 'Staff advance', 'applies_to' => 'pay_out', 'sort_order' => 5],
            ['name' => 'Petty cash', 'applies_to' => 'pay_out', 'sort_order' => 6],
            ['name' => 'Safe drop', 'applies_to' => 'cash_drop', 'sort_order' => 7],
            ['name' => 'Bank deposit', 'applies_to' => 'cash_drop', 'sort_order' => 8],
        ]));

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 12)->default('open'); // App\Enums\ShiftStatus: open | closed | approved
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Only one open shift per register and per cashier: set while open, NULL once closed.
            $table->unsignedBigInteger('open_register_id')->nullable()->unique();
            $table->unsignedBigInteger('open_user_id')->nullable()->unique();

            $table->unsignedBigInteger('opening_float')->default(0);
            $table->json('opening_counts')->nullable(); // {"500000": 2, …} denomination (cents) => count
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable(); // counted − expected (negative = short)
            $table->json('closing_counts')->nullable();
            $table->json('terminal_totals')->nullable(); // {payment_method_id: cents from the card terminal's settlement slip}
            $table->string('closing_note', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('force_close_reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note', 500)->nullable();
            $table->json('z_report')->nullable(); // frozen at close; reprints read this
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
            $table->index(['location_id', 'status']);
        });

        Schema::create('bank_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('source', 10); // safe | shift
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bank_account', 120);
            $table->string('slip_reference', 60);
            $table->date('deposited_on');
            $table->unsignedBigInteger('cash_amount')->default(0);
            $table->unsignedBigInteger('cheque_amount')->default(0);
            $table->unsignedInteger('cheque_count')->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->timestamps();

            $table->index(['location_id', 'business_date']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // App\Enums\CashMovementType: pay_in | pay_out | cash_drop
            $table->unsignedBigInteger('amount');
            $table->foreignId('category_id')->nullable()->constrained('cash_movement_categories')->nullOnDelete();
            $table->string('category_name', 60)->nullable(); // snapshot
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bank_deposit_id')->nullable()->constrained()->nullOnDelete(); // a drop straight to the bank
            $table->date('business_date');
            $table->timestamp('created_at');

            $table->index(['location_id', 'business_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('bank_deposit_id')->nullable()->after('cheque_status_at')->constrained()->nullOnDelete();
        });

        Schema::create('business_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 10)->default('open'); // open | closed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->json('warnings')->nullable(); // checklist warnings the manager accepted
            $table->json('report')->nullable(); // end-of-day report, frozen at close
            $table->timestamps();

            $table->unique(['location_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_deposit_id');
        });

        foreach (['business_days', 'cash_movements', 'bank_deposits', 'shifts', 'cash_movement_categories', 'registers'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('invoice_printed_at');
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['require_shift_for_payments', 'blind_close', 'variance_approval_threshold', 'payout_approval_threshold']);
        });
    }
};
