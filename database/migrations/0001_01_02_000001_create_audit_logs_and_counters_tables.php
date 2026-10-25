<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->nullableMorphs('subject');
            $table->string('summary')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['location_id', 'created_at']);
            $table->index('action');
        });

        // Gap-free numbers (App\Support\Counter) and sync versions (App\Support\SyncVersion).
        // type = what is numbered ("invoice", "sync"), scope = per what ("location:1:2026", "").
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('scope', 80)->default('');
            $table->unsignedBigInteger('last_number')->default(0);

            $table->unique(['type', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
        Schema::dropIfExists('audit_logs');
    }
};
