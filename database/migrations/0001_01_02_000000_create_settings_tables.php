<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row: the business itself (CompanySetting::current()).
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 30)->nullable();
            $table->unsignedInteger('vat_rate_bp')->default(1800);
            $table->boolean('prices_include_vat')->default(true);
            $table->json('document_prefixes')->nullable(); // {"invoice": "INV", …}, see App\Enums\DocumentType
            $table->boolean('allow_negative_stock')->default(false);
            $table->timestamps();
        });

        // One row per location (LocationSetting::for($location)).
        Schema::create('location_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->string('business_day_starts_at', 5)->default('00:00');
            $table->unsignedSmallInteger('auto_lock_minutes')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_settings');
        Schema::dropIfExists('company_settings');
    }
};
