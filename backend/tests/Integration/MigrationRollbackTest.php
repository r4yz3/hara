<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves every migration can be applied, fully rolled back and applied again
 * (spec 1.3). It drives the migrator directly, so it does not use
 * RefreshDatabase and always leaves the schema migrated.
 */
class MigrationRollbackTest extends TestCase
{
    public function test_fresh_rollback_and_remigrate(): void
    {
        $this->assertSame(0, Artisan::call('migrate:fresh'));
        $this->assertTrue(Schema::hasTable('delivery_zones'));
        $this->assertTrue(Schema::hasTable('audit_logs'));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1000]));

        $remaining = array_column(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"),
            'table_name',
        );
        // PostGIS's own table and the migrator's ledger are the only survivors.
        $this->assertEqualsCanonicalizing(['migrations', 'spatial_ref_sys'], $remaining);
        $this->assertSame(
            0,
            DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'audit_logs_reject_change'")->n,
        );

        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertTrue(Schema::hasTable('delivery_zones'));
        $this->assertTrue(Schema::hasTable('users'));
    }
}
