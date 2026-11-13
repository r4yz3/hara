<?php

namespace Tests\Integration;

use App\Models\City;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs against PostGIS. Shapes are synthetic squares near longitude 100,
 * latitude 10.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private const SQUARE = '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}';

    // Crosses itself: a "bow tie".
    private const BOW_TIE = '{"type":"Polygon","coordinates":[[[100,10],[100.02,10.02],[100.02,10],[100,10.02],[100,10]]]}';

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    private function insertZone(string $cityId, string $geoJson, string $slug = 'zone-a', string $status = 'draft'): string
    {
        $id = $this->ulid();

        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$id, $cityId, 'Zone', $slug, $status, $geoJson],
        );

        return $id;
    }

    /** @param \Closure(): mixed $write */
    private function assertRejected(\Closure $write, string $constraint): void
    {
        try {
            // A savepoint keeps the surrounding test transaction usable.
            DB::transaction($write);
            $this->fail("Expected the database to reject the write ({$constraint}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    public function test_postgis_is_available(): void
    {
        $this->assertNotEmpty(DB::selectOne('SELECT PostGIS_Version() AS v')->v);
    }

    public function test_expected_tables_exist(): void
    {
        $expected = [
            'users', 'roles', 'permissions', 'role_permissions', 'user_role_scopes', 'devices',
            'cities', 'city_settings', 'delivery_zones', 'coverage_exclusions',
            'merchants', 'branches', 'merchant_staff', 'merchant_branch_access',
            'riders', 'rider_city_access', 'rider_vehicles', 'customer_profiles', 'saved_addresses',
            'privacy_consents', 'audit_logs', 'outbox_events', 'feature_flags',
        ];

        $actual = array_column(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"),
            'table_name',
        );

        $this->assertSame([], array_values(array_diff($expected, $actual)));
    }

    public function test_no_later_phase_tables_exist(): void
    {
        $forbidden = ['orders', 'carts', 'payment_intents', 'ledger_entries', 'delivery_assignments', 'rider_location_events', 'products'];

        $actual = array_column(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"),
            'table_name',
        );

        $this->assertSame([], array_values(array_intersect($forbidden, $actual)));
    }

    public function test_geometry_columns_have_gist_indexes(): void
    {
        $indexes = array_column(
            DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND indexdef ILIKE '%USING gist%'"),
            'indexname',
        );

        foreach (['delivery_zones_coverage_gix', 'coverage_exclusions_geometry_gix', 'branches_location_gix', 'saved_addresses_point_gix'] as $index) {
            $this->assertContains($index, $indexes);
        }
    }

    public function test_ulids_are_stored_in_one_case(): void
    {
        $id = User::factory()->create()->id;

        // ADR-003: Laravel's HasUlids emits lower case; this pins it.
        $this->assertMatchesRegularExpression('/\A[0-9a-z]{26}\z/', $id);
        $this->assertNull(User::find(strtoupper($id)), 'IDs are case-sensitive in storage; the API boundary must normalize.');
    }

    public function test_city_starts_as_draft_and_rejects_unknown_status(): void
    {
        $city = City::factory()->create()->refresh();
        $this->assertSame('draft', $city->status);

        $this->assertRejected(
            fn () => DB::table('cities')->where('id', $city->id)->update(['status' => 'open']),
            'cities_status_check',
        );
    }

    public function test_city_slug_is_unique(): void
    {
        $city = City::factory()->create();

        $this->assertRejected(
            fn () => City::factory()->create(['slug' => $city->slug]),
            'cities_slug_unique',
        );
    }

    public function test_zone_round_trips_as_a_multipolygon_in_lon_lat_order(): void
    {
        $city = City::factory()->create();
        $id = $this->insertZone($city->id, self::SQUARE);

        $row = DB::selectOne('SELECT ST_AsGeoJSON(coverage) AS geojson, ST_SRID(coverage) AS srid FROM delivery_zones WHERE id = ?', [$id]);
        $stored = json_decode($row->geojson, true);

        $this->assertSame(4326, $row->srid);
        $this->assertSame('MultiPolygon', $stored['type']);
        $this->assertEquals(
            [[[[100, 10], [100.02, 10], [100.02, 10.02], [100, 10.02], [100, 10]]]],
            $stored['coordinates'],
        );
    }

    public function test_boundary_point_is_covered_and_outside_point_is_not(): void
    {
        $city = City::factory()->create();
        $id = $this->insertZone($city->id, self::SQUARE);

        $covers = fn (float $lon, float $lat): bool => DB::selectOne(
            'SELECT ST_Covers(coverage, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS inside FROM delivery_zones WHERE id = ?',
            [$lon, $lat, $id],
        )->inside;

        $this->assertTrue($covers(100.01, 10.01), 'interior');
        $this->assertTrue($covers(100.0, 10.01), 'on the edge');
        $this->assertTrue($covers(100.0, 10.0), 'on a corner');
        $this->assertFalse($covers(100.03, 10.01), 'outside');
        // Swapped axes must not match: (10.01, 100.01) is not a valid position near this zone.
        $this->assertFalse($covers(10.01, 80.0), 'swapped-style point');
    }

    public function test_self_intersecting_zone_is_rejected_by_the_database(): void
    {
        $city = City::factory()->create();

        $this->assertRejected(
            fn () => $this->insertZone($city->id, self::BOW_TIE),
            'delivery_zones_coverage_valid_check',
        );
    }

    public function test_zone_slug_is_unique_within_a_city_only(): void
    {
        $first = City::factory()->create();
        $second = City::factory()->create();

        $this->insertZone($first->id, self::SQUARE, 'core');
        $this->insertZone($second->id, self::SQUARE, 'core');

        $this->assertRejected(
            fn () => $this->insertZone($first->id, self::SQUARE, 'core'),
            'delivery_zones_city_slug_unique',
        );
    }

    public function test_city_with_zones_cannot_be_deleted(): void
    {
        $city = City::factory()->create();
        $this->insertZone($city->id, self::SQUARE);

        $this->assertRejected(fn () => $city->delete(), 'delivery_zones_city_id_fkey');
    }

    public function test_exclusion_window_must_end_after_it_starts(): void
    {
        $city = City::factory()->create();
        $zoneId = $this->insertZone($city->id, self::SQUARE);

        $this->assertRejected(
            fn () => DB::insert(
                "INSERT INTO coverage_exclusions (id, delivery_zone_id, reason, geometry, active_from, active_until)
                 VALUES (?, ?, 'test', ST_Multi(ST_GeomFromGeoJSON(?)), '2030-01-02T00:00:00Z', '2030-01-01T00:00:00Z')",
                [$this->ulid(), $zoneId, self::SQUARE],
            ),
            'coverage_exclusions_window_check',
        );
    }

    public function test_global_grant_has_no_scope_id_and_scoped_grant_needs_one(): void
    {
        $user = User::factory()->create();
        $roleId = $this->ulid();
        DB::table('roles')->insert(['id' => $roleId, 'slug' => 'test_role', 'label' => 'Test']);

        $grant = fn (string $type, ?string $scopeId) => fn () => DB::table('user_role_scopes')->insert([
            'id' => $this->ulid(), 'user_id' => $user->id, 'role_id' => $roleId, 'scope_type' => $type, 'scope_id' => $scopeId,
        ]);

        $this->assertRejected($grant('global', $this->ulid()), 'user_role_scopes_scope_id_check');
        $this->assertRejected($grant('city', null), 'user_role_scopes_scope_id_check');
        $this->assertRejected($grant('planet', $this->ulid()), 'user_role_scopes_scope_type_check');
    }

    public function test_a_live_grant_cannot_be_duplicated_but_can_be_regranted_after_revocation(): void
    {
        $user = User::factory()->create();
        $roleId = $this->ulid();
        DB::table('roles')->insert(['id' => $roleId, 'slug' => 'test_role', 'label' => 'Test']);

        $row = fn () => ['id' => $this->ulid(), 'user_id' => $user->id, 'role_id' => $roleId, 'scope_type' => 'global', 'scope_id' => null];

        DB::table('user_role_scopes')->insert($row());
        $this->assertRejected(fn () => DB::table('user_role_scopes')->insert($row()), 'user_role_scopes_active_unique');

        DB::table('user_role_scopes')->update(['revoked_at' => now()]);
        DB::table('user_role_scopes')->insert($row());

        $this->assertSame(2, DB::table('user_role_scopes')->count());
    }

    public function test_audit_log_is_append_only(): void
    {
        $id = $this->ulid();
        DB::table('audit_logs')->insert(['id' => $id, 'action' => 'test.created', 'subject_type' => 'test']);

        $this->assertRejected(
            fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']),
            'audit_logs is append-only',
        );
        $this->assertRejected(
            fn () => DB::table('audit_logs')->where('id', $id)->delete(),
            'audit_logs is append-only',
        );
        $this->assertRejected(
            fn () => DB::statement('TRUNCATE audit_logs'),
            'audit_logs is append-only',
        );

        $this->assertSame('test.created', DB::table('audit_logs')->where('id', $id)->value('action'));
    }

    public function test_user_email_must_be_lower_case_and_status_known(): void
    {
        $this->assertRejected(
            fn () => User::factory()->create(['email' => 'Mixed.Case@example.com']),
            'users_email_lowercase_check',
        );

        $user = User::factory()->create();
        $this->assertRejected(
            fn () => DB::table('users')->where('id', $user->id)->update(['status' => 'admin']),
            'users_status_check',
        );
    }

    public function test_status_and_roles_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'status' => 'suspended', 'role' => 'platform_owner']);

        $this->assertArrayNotHasKey('status', $user->getAttributes());
        $this->assertArrayNotHasKey('role', $user->getAttributes());
    }

    public function test_only_one_default_address_per_user(): void
    {
        $user = User::factory()->create();

        $insert = fn () => DB::insert(
            "INSERT INTO saved_addresses (id, user_id, address_line, landmark, is_default, point)
             VALUES (?, ?, 'Line', 'Landmark', true, ST_SetSRID(ST_MakePoint(100.01, 10.01), 4326))",
            [$this->ulid(), $user->id],
        );

        $insert();
        $this->assertRejected($insert, 'saved_addresses_one_default');
    }

    public function test_development_seeder_creates_labelled_demo_data_once(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $city = DB::table('cities')->where('slug', DevelopmentSeeder::CITY_SLUG)->first();

        $this->assertSame(1, DB::table('cities')->count());
        $this->assertStringContainsString('synthetic', $city->name);
        $this->assertSame(1, DB::table('delivery_zones')->where('city_id', $city->id)->count());
    }

    public function test_development_seeder_creates_demo_accounts_only_when_a_password_is_configured(): void
    {
        config(['demo.password' => null]);
        $this->seed(DevelopmentSeeder::class);
        $this->assertSame(0, DB::table('users')->count());

        config(['demo.password' => 'a-local-demo-passphrase']);
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['demo-owner@example.test', 'demo-customer@example.test'],
            DB::table('users')->pluck('email')->all(),
        );
        $roles = DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->join('users', 'users.id', '=', 'user_role_scopes.user_id')
            ->orderBy('users.email')->pluck('roles.slug', 'users.email')->all();
        $this->assertSame(['demo-customer@example.test' => 'customer', 'demo-owner@example.test' => 'platform_owner'], $roles);
        $this->assertStringNotContainsString('a-local-demo-passphrase', (string) DB::table('users')->value('password'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'demo.accounts_seeded')->count());
    }

    public function test_development_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            (new DevelopmentSeeder)->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refuses to run', $e->getMessage());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(0, DB::table('cities')->count());
    }
}
