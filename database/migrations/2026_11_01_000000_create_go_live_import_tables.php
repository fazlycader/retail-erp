<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 9: go-live data imports. import_keys remembers every imported row (kind + natural key, e.g.
 * a customer's phone or a legacy order number) so an import can be run again without duplicates.
 * payments.imported flags advances taken in the old system (no shift, no cash in any drawer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_keys', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('key', 191);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_file', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['kind', 'key']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('imported')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('imported');
        });

        Schema::dropIfExists('import_keys');
    }
};
