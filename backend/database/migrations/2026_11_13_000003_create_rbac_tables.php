<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Spec 5.2, seq 003 (RBAC) and 004 (membership and scope). See ADR-005.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('label', 120);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role_scopes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained()->restrictOnDelete();
            $table->string('scope_type', 20);
            // Polymorphic: no foreign key is possible. Validity is checked by
            // the grant service (ADR-003, ADR-005).
            $table->char('scope_id', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['scope_type', 'scope_id']);
        });

        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT user_role_scopes_scope_type_check CHECK (scope_type IN ('global','city','merchant','branch'))");
        DB::statement("ALTER TABLE user_role_scopes ADD CONSTRAINT user_role_scopes_scope_id_check CHECK ((scope_type = 'global') = (scope_id IS NULL))");
        // One live grant per user, role and scope. Revoked rows are history.
        DB::statement("CREATE UNIQUE INDEX user_role_scopes_active_unique ON user_role_scopes (user_id, role_id, scope_type, COALESCE(scope_id, '')) WHERE revoked_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_scopes');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
