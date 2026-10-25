<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 11: custom orders & workshop. Production jobs are kept generic (a job makes `quantity` of a
 * variant at a location, with stages, materials and labour) so Step 17 can add bills of materials,
 * work centres and capacity planning on top instead of replacing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw materials: products in a category flagged is_material (or below one). Their stock may
        // move in fractions (2.5 m of fabric = 2500 milli-units); everything else moves in whole units.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_material')->default(false)->after('is_active');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit', 12)->default('pcs')->after('type'); // pcs, m, kg, l, sqft, sheet, roll…
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->foreignId('workshop_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedInteger('workshop_overhead_bp')->default(0); // on materials + labour
            $table->unsignedSmallInteger('workshop_lead_days')->default(21);
            $table->boolean('sms_enabled')->default(false);
            $table->json('sms_templates')->nullable();
        });

        Schema::create('custom_specs', function (Blueprint $table) {
            $table->id();
            // The quotation or sales order line it describes; null while a new line is still being drafted.
            $table->nullableMorphs('specable');
            $table->foreignId('base_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('material', 120)->nullable();
            $table->string('fabric_code', 60)->nullable();
            $table->string('colour_code', 60)->nullable();
            $table->string('finish', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable(); // [{path, thumb, name}] on the private disk
            $table->string('approved_by_name', 120)->nullable(); // the customer who approved
            $table->date('approved_on')->nullable();
            $table->string('signed_quote_path')->nullable();
            $table->foreignId('approval_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->foreignId('custom_spec_id')->nullable()->constrained('custom_specs')->nullOnDelete();
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreignId('custom_spec_id')->nullable()->constrained('custom_specs')->nullOnDelete();
        });

        Schema::create('production_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete(); // the workshop
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'name']);
        });

        Schema::create('production_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // workshop, or where the supplier delivers
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('custom_spec_id')->nullable()->constrained('custom_specs')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete(); // what is made
            $table->string('title');
            $table->unsignedBigInteger('quantity'); // milli-units (whole pieces)
            $table->string('source', 16)->default('workshop'); // App\Enums\ProductionSource
            $table->string('status', 20)->default('pending'); // App\Enums\ProductionJobStatus
            $table->foreignId('production_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('priority')->default(1); // App\Enums\JobPriority
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Outsourced: the back-to-back PO line and where the supplier is.
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_status', 20)->nullable(); // App\Enums\SupplierProgress
            $table->timestamp('supplier_status_at')->nullable();
            // Cost (cents), recalculated after every material, labour or setting change.
            $table->unsignedBigInteger('materials_cost')->default(0);
            $table->unsignedBigInteger('labour_cost')->default(0);
            $table->unsignedInteger('overhead_bp')->default(0);
            $table->unsignedBigInteger('overhead_cost')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();

            $table->index(['location_id', 'status', 'due_date']);
            $table->index(['location_id', 'sync_version']);
        });

        Schema::create('job_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('production_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('production_stages')->nullOnDelete();
            $table->string('event', 20)->default('moved'); // moved, note, photo, completed, outsourced, supplier, cancelled
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('photos')->nullable(); // [{path, thumb}] on the private disk
            $table->timestamp('created_at')->nullable();

            $table->index(['production_job_id', 'id']);
        });

        Schema::create('job_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // issue | return
            $table->unsignedBigInteger('quantity'); // milli-units, always positive
            $table->unsignedBigInteger('unit_cost'); // cents per unit
            $table->unsignedBigInteger('cost'); // cents = quantity × unit cost
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['production_job_id', 'product_variant_id']);
        });

        Schema::create('job_labour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the worker, when on staff
            $table->string('worker_name', 120);
            $table->date('work_date');
            $table->unsignedInteger('minutes')->nullable(); // with rate: hours × rate
            $table->unsignedBigInteger('hourly_rate')->nullable(); // cents
            $table->unsignedBigInteger('amount'); // cents (calculated, or a fixed amount)
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A finished piece sent to a showroom stays reserved for its order: dispatch releases the
        // reservation, receipt reserves it again at the destination.
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_phone', 20);
            $table->string('template', 40)->nullable();
            $table->text('body');
            $table->string('status', 12)->default('queued'); // queued, sent, failed, skipped
            $table->string('error')->nullable();
            $table->string('provider_ref')->nullable();
            $table->nullableMorphs('related');
            $table->string('dedupe_key', 120)->nullable()->unique(); // e.g. job:5:ready, never sent twice
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::table('transfer_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_order_line_id'));
        Schema::dropIfExists('job_labour');
        Schema::dropIfExists('job_materials');
        Schema::dropIfExists('job_stage_logs');
        Schema::dropIfExists('production_jobs');
        Schema::dropIfExists('production_stages');
        Schema::table('sales_order_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_spec_id'));
        Schema::table('quotation_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_spec_id'));
        Schema::dropIfExists('custom_specs');
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workshop_location_id');
            $table->dropColumn(['workshop_overhead_bp', 'workshop_lead_days', 'sms_enabled', 'sms_templates']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('unit'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('is_material'));
    }
};
