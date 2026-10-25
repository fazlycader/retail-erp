<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->json('areas')->nullable();
            $table->string('fee_rule', 24)->default('flat'); // flat | order_value | free_above
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->unsignedBigInteger('free_above_cents')->nullable();
            $table->unsignedSmallInteger('lead_days')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->after('fulfilment_type')->constrained('delivery_zones')->nullOnDelete();
            $table->unsignedBigInteger('delivery_fee_cents')->default(0)->after('delivery_charge');
        });

        Schema::create('delivery_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('registration', 24)->unique();
            $table->unsignedSmallInteger('capacity_items')->nullable();
            $table->unsignedBigInteger('capacity_cubic_mm')->nullable();
            $table->unsignedBigInteger('odometer')->default(0); // km
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('loading_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('delivery_vehicles')->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->string('helper_names', 255)->nullable();
            $table->date('run_date');
            $table->unsignedBigInteger('start_odometer')->nullable();
            $table->unsignedBigInteger('end_odometer')->nullable();
            $table->string('status', 24)->default('planned'); // planned | loaded | out_for_delivery | closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'run_date', 'status']);
            $table->index(['driver_id', 'run_date']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 500);
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 24);
            $table->date('scheduled_date');
            $table->string('time_window', 80)->nullable();
            $table->string('status', 24)->default('scheduled');
            $table->boolean('requires_installation')->default(false);
            $table->unsignedBigInteger('balance_to_collect')->default(0);
            $table->string('receiver_name', 160)->nullable();
            $table->string('signature_path', 255)->nullable();
            $table->json('proof_photos')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();
            $table->index(['location_id', 'scheduled_date', 'status']);
            $table->index(['sales_order_id', 'status']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('quantity'); // milli-units
            $table->timestamps();
        });

        Schema::create('delivery_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->unique(['delivery_run_id', 'delivery_id']);
            $table->unique(['delivery_run_id', 'sort_order']);
        });

        Schema::create('installation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->date('scheduled_date');
            $table->json('checklist')->nullable();
            $table->json('completion_photos')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'scheduled_date', 'status']);
        });

        Schema::create('driver_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount'); // cents
            $table->string('method', 20); // cash | bank_transfer
            $table->string('reference', 100)->nullable();
            $table->string('status', 24)->default('held_by_driver');
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamps();
            $table->index(['driver_id', 'status']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('delivery_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('action_id')->unique();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 24);
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
            $table->string('collection_status', 24)->nullable()->after('collected_by'); // held_by_driver | handed_over
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn('delivery_fee_cents');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn('collection_status');
        });
        foreach (['delivery_actions', 'driver_collections', 'installation_jobs', 'delivery_stops', 'delivery_lines', 'deliveries', 'delivery_runs', 'delivery_vehicles', 'delivery_zones'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
