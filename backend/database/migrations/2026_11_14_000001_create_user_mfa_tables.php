<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Backlog B-09 / ADR-011: the TOTP second factor for staff. Expand-only: two
| new tables, nothing existing is touched.
|
| `secret` is Laravel-encrypted ciphertext (the model's `encrypted` cast).
| `last_used_step` is the newest 30-second step already accepted, so a code
| cannot be replayed. Recovery codes are stored only as keyed hashes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mfa_factors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestampTz('confirmed_at')->nullable();
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampsTz();
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mfa_recovery_codes');
        Schema::dropIfExists('user_mfa_factors');
    }
};
