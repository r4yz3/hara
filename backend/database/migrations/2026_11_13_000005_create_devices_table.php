<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Spec 5.2, seq 005. `personal_access_tokens` is not created here: it ships
| with Laravel Sanctum and arrives with that package in Sprint 4.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            // Encrypted by the application before it reaches this column.
            $table->text('push_token_ciphertext')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_platform_check CHECK (platform IN ('android','ios','web'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
