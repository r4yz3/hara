<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Backlog U-05: staff invitations. Expand-only: one new table, nothing
| existing is touched.
|
| `token_hash` is the SHA-256 of a 256-bit random token; the token itself is
| only ever in the email. `email` is stored lowercase (as users.email is).
| There is no foreign key on scope_id: it is polymorphic, like
| user_role_scopes (ADR-005); the grant service checks it on acceptance.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email');
            $table->string('name');
            $table->foreignUlid('role_id')->constrained()->restrictOnDelete();
            $table->string('scope_type', 20);
            $table->char('scope_id', 26)->nullable();
            $table->foreignUlid('invited_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index('email');
            $table->index(['scope_type', 'scope_id']);
        });

        DB::statement("ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_scope_type_check CHECK (scope_type IN ('global','city'))");
        DB::statement("ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_scope_id_check CHECK ((scope_type = 'global') = (scope_id IS NULL))");
        DB::statement('ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_email_lowercase_check CHECK (email = lower(email))');
        // One live invitation per address, role and scope. Accepted and revoked rows are history.
        DB::statement("CREATE UNIQUE INDEX staff_invitations_live_unique ON staff_invitations (email, role_id, scope_type, COALESCE(scope_id, '')) WHERE accepted_at IS NULL AND revoked_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
