<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->longText('installment_agreement_terms')->nullable();
            $table->string('installment_tax_treatment', 24)->nullable();
            $table->string('installment_early_settlement_method', 24)->nullable();
            $table->unsignedInteger('installment_charge_rebate_bp')->nullable();
            $table->boolean('installment_ownership_until_paid')->nullable();
            $table->json('installment_reminder_stages')->nullable();
        });

        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->json('terms')->nullable();
            $table->unsignedInteger('minimum_down_payment_bp')->nullable();
            $table->string('charge_method', 16)->default('flat_bp');
            $table->unsignedInteger('flat_bp')->default(1000);
            $table->unsignedBigInteger('document_fee_cents')->nullable();
            $table->string('late_fee_method', 16)->nullable();
            $table->unsignedBigInteger('late_fee_cents')->nullable();
            $table->unsignedInteger('late_fee_bp')->nullable();
            $table->unsignedSmallInteger('grace_days')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'name'], 'inst_plans_active_name_idx');
        });

        Schema::create('installment_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('installment_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('financed_amount');
            $table->unsignedBigInteger('down_payment');
            $table->unsignedBigInteger('charges');
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedInteger('tax_rate_bp_snapshot')->default(0);
            $table->unsignedBigInteger('total_payable');
            $table->unsignedBigInteger('early_settlement_rebate')->default(0);
            $table->string('early_settlement_method_snapshot', 24);
            $table->unsignedInteger('charge_rebate_bp_snapshot')->default(0);
            $table->boolean('ownership_until_paid_snapshot');
            $table->string('late_fee_method_snapshot', 16);
            $table->unsignedBigInteger('late_fee_cents_snapshot')->nullable();
            $table->unsignedInteger('late_fee_bp_snapshot')->nullable();
            $table->unsignedSmallInteger('grace_days_snapshot');
            $table->unsignedSmallInteger('term_months');
            $table->date('start_date');
            $table->string('status', 20)->default('active');
            $table->string('signed_agreement_path')->nullable();
            $table->text('agreement_terms_snapshot');
            $table->string('tax_treatment_snapshot', 24);
            $table->date('completed_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status'], 'inst_agreement_location_status_idx');
            $table->index(['customer_id', 'status'], 'inst_agreement_customer_status_idx');
        });

        Schema::create('installment_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_agreement_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('nic', 20);
            $table->string('phone', 20);
            $table->text('address');
            $table->timestamps();
        });

        Schema::create('installment_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_agreement_id')->constrained()->cascadeOnDelete();
            $table->date('due_date');
            $table->unsignedBigInteger('principal');
            $table->unsignedBigInteger('charge');
            $table->unsignedBigInteger('amount_due');
            $table->unsignedBigInteger('principal_paid')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('late_fee_charged')->default(0);
            $table->unsignedBigInteger('late_fee_paid')->default(0);
            $table->string('status', 20)->default('due');
            $table->timestamps();
            $table->unique(['installment_agreement_id', 'due_date'], 'inst_sched_agreement_due_uq');
            $table->index(['status', 'due_date'], 'inst_sched_status_due_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('installment_agreement_id')->nullable()->after('sales_order_id')
                ->constrained()->nullOnDelete();
        });

        Schema::create('installment_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('installment_schedule_id')->constrained('installment_schedule')->restrictOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount');
            $table->timestamps();
            $table->index(['installment_schedule_id', 'kind'], 'inst_alloc_schedule_kind_idx');
        });

        Schema::create('installment_late_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_schedule_id')->constrained('installment_schedule')->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->unsignedBigInteger('amount');
            $table->date('charged_on');
            $table->timestamps();
            $table->unique(['installment_schedule_id', 'period_key'], 'inst_late_sched_period_uq');
        });

        Schema::create('installment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_schedule_id')->constrained('installment_schedule')->cascadeOnDelete();
            $table->string('stage', 24);
            $table->foreignId('sms_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['installment_schedule_id', 'stage'], 'inst_rem_sched_stage_uq');
        });

        Schema::create('installment_contact_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_schedule_id')->nullable()->constrained('installment_schedule')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes');
            $table->date('promise_to_pay_on')->nullable();
            $table->timestamps();
            $table->index(['installment_agreement_id', 'created_at'], 'inst_contact_agreement_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_contact_logs');
        Schema::dropIfExists('installment_reminders');
        Schema::dropIfExists('installment_late_fees');
        Schema::dropIfExists('installment_payment_allocations');
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('installment_agreement_id'));
        Schema::dropIfExists('installment_schedule');
        Schema::dropIfExists('installment_guarantors');
        Schema::dropIfExists('installment_agreements');
        Schema::dropIfExists('installment_plans');
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'installment_agreement_terms',
                'installment_tax_treatment',
                'installment_early_settlement_method',
                'installment_charge_rebate_bp',
                'installment_ownership_until_paid',
                'installment_reminder_stages',
            ]);
        });
    }
};
