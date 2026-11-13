<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Spec 5.2, seq 006 (cities), 007 (coverage geometry), 008 (exclusions).
| Geometry rules are in ADR-002. City status values are the proposal in
| ADR-000 item 8 and are still awaiting approval (backlog D-07).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 160)->unique();
            $table->string('name', 160);
            $table->char('country_code', 2);
            $table->char('currency_code', 3);
            // No default: an administrator chooses it (ADR-004).
            $table->string('timezone', 64);
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_status_check CHECK (status IN ('draft','active','paused','archived'))");
        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_country_code_check CHECK (country_code ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_currency_code_check CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE cities ADD CONSTRAINT cities_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('city_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('city_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->jsonb('value_json');
            $table->timestampsTz();
            $table->unique(['city_id', 'key']);
        });

        DB::statement(<<<'SQL'
            CREATE TABLE delivery_zones (
                id CHAR(26) PRIMARY KEY,
                city_id CHAR(26) NOT NULL REFERENCES cities(id) ON DELETE RESTRICT,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft'
                    CONSTRAINT delivery_zones_status_check CHECK (status IN ('draft','active','paused','archived')),
                coverage geometry(MultiPolygon,4326) NOT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1 CONSTRAINT delivery_zones_version_check CHECK (version >= 1),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT delivery_zones_city_slug_unique UNIQUE (city_id, slug),
                CONSTRAINT delivery_zones_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT delivery_zones_coverage_valid_check CHECK (ST_IsValid(coverage)),
                CONSTRAINT delivery_zones_coverage_not_empty_check CHECK (NOT ST_IsEmpty(coverage))
            )
        SQL);
        DB::statement('CREATE INDEX delivery_zones_coverage_gix ON delivery_zones USING GIST (coverage)');
        DB::statement('CREATE INDEX delivery_zones_city_status_idx ON delivery_zones (city_id, status)');

        DB::statement(<<<'SQL'
            CREATE TABLE coverage_exclusions (
                id CHAR(26) PRIMARY KEY,
                delivery_zone_id CHAR(26) NOT NULL REFERENCES delivery_zones(id) ON DELETE CASCADE,
                reason VARCHAR(255) NOT NULL,
                geometry geometry(MultiPolygon,4326) NOT NULL,
                active_from TIMESTAMPTZ NULL,
                active_until TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT coverage_exclusions_geometry_valid_check CHECK (ST_IsValid(geometry)),
                CONSTRAINT coverage_exclusions_geometry_not_empty_check CHECK (NOT ST_IsEmpty(geometry)),
                CONSTRAINT coverage_exclusions_window_check
                    CHECK (active_from IS NULL OR active_until IS NULL OR active_until > active_from)
            )
        SQL);
        DB::statement('CREATE INDEX coverage_exclusions_geometry_gix ON coverage_exclusions USING GIST (geometry)');
        DB::statement('CREATE INDEX coverage_exclusions_zone_idx ON coverage_exclusions (delivery_zone_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_exclusions');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('city_settings');
        Schema::dropIfExists('cities');
    }
};
