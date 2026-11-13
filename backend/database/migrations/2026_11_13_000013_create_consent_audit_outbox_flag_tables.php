<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Spec 5.2, seq 013 (consent), 014 (audit), 015 (outbox), 016 (flags).
|
| Seq 017 (login protection) adds no table: throttling uses the framework's
| rate limiter on Redis, and `mfa_factors` waits for the reviewed design
| required by ADR-011.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('consent_type', 60);
            $table->string('notice_version', 40);
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('source', 40);
            $table->index(['user_id', 'consent_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // No foreign key: the record must survive whatever happens to the
            // actor or the subject.
            $table->char('actor_user_id', 26)->nullable()->index();
            $table->string('action', 120);
            $table->string('subject_type', 80);
            $table->char('subject_id', 26)->nullable();
            $table->char('scope_city_id', 26)->nullable()->index();
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        // Append-only at the database level: no UPDATE, DELETE or TRUNCATE
        // through any code path, including a compromised application.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only' USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_change();

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_reject_change();
        SQL);

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event_type', 120);
            $table->string('aggregate_type', 80);
            $table->char('aggregate_id', 26);
            $table->jsonb('payload_json');
            $table->timestampTz('occurred_at');
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
        DB::statement('CREATE INDEX outbox_events_unpublished_idx ON outbox_events (available_at) WHERE published_at IS NULL');

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 120);
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('scope_type', 20)->default('global');
            $table->char('scope_id', 26)->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement("ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_scope_type_check CHECK (scope_type IN ('global','city'))");
        DB::statement("ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_scope_id_check CHECK ((scope_type = 'global') = (scope_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX feature_flags_key_scope_unique ON feature_flags (key, scope_type, COALESCE(scope_id, ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('outbox_events');
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs;
        SQL);
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_reject_change()');
        Schema::dropIfExists('privacy_consents');
    }
};
