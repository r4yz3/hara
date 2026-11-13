<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Spec 5.2, seq 009–012: identity shells only. No catalogue, order, shift or
| location tables (spec 5.5). Status lists are deliberately short; they widen
| when onboarding and approval workflows are designed in Phase 2.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('legal_name');
            $table->string('display_name', 160);
            $table->string('slug', 160)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_status_check CHECK (status IN ('pending','active','suspended'))");

        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('city_id')->constrained()->restrictOnDelete();
            $table->string('display_name', 160);
            $table->string('status', 20)->default('draft');
            $table->text('address_text');
            $table->timestampsTz();
            $table->index(['city_id', 'status']);
        });
        DB::statement('ALTER TABLE branches ADD COLUMN location geometry(Point,4326) NOT NULL');
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('draft','active','paused','archived'))");
        DB::statement('CREATE INDEX branches_location_gix ON branches USING GIST (location)');

        Schema::create('merchant_staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->unique(['merchant_id', 'user_id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE merchant_staff ADD CONSTRAINT merchant_staff_role_check CHECK (role IN ('owner','manager','staff'))");
        DB::statement("ALTER TABLE merchant_staff ADD CONSTRAINT merchant_staff_status_check CHECK (status IN ('active','revoked'))");

        Schema::create('merchant_branch_access', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('merchant_staff_id')->constrained('merchant_staff')->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['merchant_staff_id', 'branch_id']);
        });

        Schema::create('riders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE riders ADD CONSTRAINT riders_status_check CHECK (status IN ('pending','approved','suspended'))");
        DB::statement("ALTER TABLE riders ADD CONSTRAINT riders_approved_at_check CHECK (status <> 'approved' OR approved_at IS NOT NULL)");

        Schema::create('rider_city_access', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rider_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('city_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->unique(['rider_id', 'city_id']);
        });
        DB::statement("ALTER TABLE rider_city_access ADD CONSTRAINT rider_city_access_status_check CHECK (status IN ('active','revoked'))");

        Schema::create('rider_vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('rider_id')->constrained()->cascadeOnDelete();
            $table->string('vehicle_type', 20);
            // Encrypted by the application before it reaches this column.
            $table->text('plate_number_ciphertext')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
        });
        // Motorcycles only for the MVP (master plan, operating assumptions).
        DB::statement("ALTER TABLE rider_vehicles ADD CONSTRAINT rider_vehicles_type_check CHECK (vehicle_type IN ('motorcycle'))");
        DB::statement("ALTER TABLE rider_vehicles ADD CONSTRAINT rider_vehicles_status_check CHECK (status IN ('active','retired'))");

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampsTz();
        });

        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tag', 40)->nullable();
            $table->text('address_line');
            // Mandatory: the master plan requires a landmark with every address.
            $table->string('landmark');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE saved_addresses ADD COLUMN point geometry(Point,4326) NOT NULL');
        DB::statement('CREATE INDEX saved_addresses_point_gix ON saved_addresses USING GIST (point)');
        DB::statement('CREATE UNIQUE INDEX saved_addresses_one_default ON saved_addresses (user_id) WHERE is_default');
    }

    public function down(): void
    {
        foreach ([
            'saved_addresses', 'customer_profiles', 'rider_vehicles', 'rider_city_access', 'riders',
            'merchant_branch_access', 'merchant_staff', 'branches', 'merchants',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
