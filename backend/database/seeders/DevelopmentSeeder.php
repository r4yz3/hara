<?php

namespace Database\Seeders;

use App\Application\Commands\AuditLogger;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo data for local development and automated tests ONLY.
 *
 * Everything it creates is labelled synthetic. The zone is a 0.02-degree
 * square at an arbitrary position; it is not a real or proposed service area.
 */
class DevelopmentSeeder extends Seeder
{
    public const CITY_SLUG = 'demo-city';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DevelopmentSeeder refuses to run outside the local and testing environments.');
        }

        $this->call(RbacSeeder::class);
        $this->seedDemoAccounts();

        DB::transaction(function (): void {
            if (DB::table('cities')->where('slug', self::CITY_SLUG)->exists()) {
                return;
            }

            $cityId = strtolower((string) Str::ulid());

            DB::table('cities')->insert([
                'id' => $cityId,
                'slug' => self::CITY_SLUG,
                'name' => 'Demo City (synthetic, not a real place)',
                'country_code' => 'ZZ',
                'currency_code' => 'PHP',
                'timezone' => 'UTC',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::insert(
                <<<'SQL'
                    INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage)
                    VALUES (?, ?, 'Demo Zone (synthetic)', 'demo-zone', 'active',
                        ST_Multi(ST_GeomFromGeoJSON(?)))
                SQL,
                [
                    strtolower((string) Str::ulid()),
                    $cityId,
                    json_encode([
                        'type' => 'Polygon',
                        'coordinates' => [[[100.0, 10.0], [100.02, 10.0], [100.02, 10.02], [100.0, 10.02], [100.0, 10.0]]],
                    ]),
                ],
            );
        });
    }

    /**
     * Two sign-in accounts for trying the apps locally. They are created only
     * when DEMO_PASSWORD is set in the local .env, so no password lives in
     * the repository. The owner grant is written directly because
     * platform_owner can never be granted through the application.
     */
    private function seedDemoAccounts(): void
    {
        $password = config('demo.password');

        if (! is_string($password) || $password === '' || DB::table('users')->where('email', 'demo-owner@example.test')->exists()) {
            return;
        }

        DB::transaction(function () use ($password): void {
            $accounts = [
                'demo-owner@example.test' => ['Demo Owner (synthetic)', 'platform_owner'],
                'demo-customer@example.test' => ['Demo Customer (synthetic)', 'customer'],
            ];

            foreach ($accounts as $email => [$name, $role]) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);

                DB::table('user_role_scopes')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'user_id' => $user->id,
                    'role_id' => DB::table('roles')->where('slug', $role)->value('id'),
                    'scope_type' => 'global',
                    'scope_id' => null,
                ]);
            }

            app(AuditLogger::class)->record(null, 'demo.accounts_seeded', 'system', after: ['accounts' => 2, 'environment' => app()->environment()]);
        });
    }
}
