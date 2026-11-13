<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Spec 5.2, seq 002 (identity base), merged with the framework's default
| users / password reset / sessions tables rather than duplicating them.
| The spec's `password_hash` is the framework's `password` column (ADR-000 #5).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone_e164', 16)->nullable()->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_phone_e164_check CHECK (phone_e164 IS NULL OR phone_e164 ~ '^\\+[1-9][0-9]{6,14}$')");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email = lower(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
