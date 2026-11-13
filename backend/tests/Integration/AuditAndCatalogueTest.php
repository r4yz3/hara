<?php

namespace Tests\Integration;

use App\Application\Commands\AuditLogger;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Covers the spec's AuditRedactionTest (10.1) and keeps the seeded catalogue
 * in line with docs/security/permission-matrix.md.
 */
class AuditAndCatalogueTest extends RbacTestCase
{
    public function test_sensitive_values_are_redacted_at_any_depth(): void
    {
        $clean = app(AuditLogger::class)->redact([
            'name' => 'Example',
            'password' => 'hunter2',
            'remember_token' => 'abc',
            'rememberToken' => 'abc',
            'otp_code' => '123456',
            'push_token_ciphertext' => 'xyz',
            'plate_number_ciphertext' => 'xyz',
            'country_code' => 'ZZ',
            'pinned' => true,
            'platform' => 'android',
            'address' => ['line' => 'Synthetic street', 'latitude' => 10.01, 'longitude' => 100.01, 'point' => [100.01, 10.01]],
            'items' => [['api_secret' => 's', 'label' => 'kept']],
        ]);

        $this->assertSame([
            'name' => 'Example',
            'password' => '[redacted]',
            'remember_token' => '[redacted]',
            'rememberToken' => '[redacted]',
            'otp_code' => '[redacted]',
            'push_token_ciphertext' => '[redacted]',
            'plate_number_ciphertext' => '[redacted]',
            'country_code' => 'ZZ',
            'pinned' => true,
            'platform' => 'android',
            'address' => ['line' => 'Synthetic street', 'latitude' => '[redacted]', 'longitude' => '[redacted]', 'point' => '[redacted]'],
            'items' => [['api_secret' => '[redacted]', 'label' => 'kept']],
        ], $clean);
    }

    public function test_recorded_entries_are_redacted_and_never_hold_the_raw_ip(): void
    {
        $id = app(AuditLogger::class)->record(
            actorUserId: null,
            action: 'test.changed',
            subjectType: 'test',
            before: ['password' => 'old', 'name' => 'A'],
            after: ['password' => 'new', 'name' => 'B'],
            ip: '203.0.113.10',
            correlationId: 'req-1',
        );

        $row = DB::table('audit_logs')->where('id', $id)->sole();
        $stored = json_encode($row);

        $this->assertStringNotContainsString('old', (string) $row->before_json);
        $this->assertStringNotContainsString('"new"', (string) $row->after_json);
        $this->assertSame('B', json_decode($row->after_json, true)['name']);
        $this->assertStringNotContainsString('203.0.113.10', (string) $stored);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->ip_hash);
        $this->assertSame('req-1', $row->correlation_id);
    }

    public function test_catalogue_is_seeded_exactly_and_reseeding_changes_nothing(): void
    {
        $snapshot = fn (): array => DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->orderBy('roles.slug')->orderBy('permissions.slug')
            ->get(['roles.slug as role', 'permissions.slug as permission'])
            ->map(fn (object $row): string => "{$row->role}:{$row->permission}")
            ->all();

        $before = $snapshot();
        $this->seed(RbacSeeder::class);

        $this->assertSame($before, $snapshot());
        $this->assertSame(count(config('rbac.permissions')), DB::table('permissions')->count());
        $this->assertSame(count(config('rbac.roles')), DB::table('roles')->count());
    }

    public function test_catalogue_matches_the_accepted_matrix(): void
    {
        $has = fn (string $role, string $permission): bool => DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.slug', $role)->where('permissions.slug', $permission)->exists();

        $holders = fn (string $permission): array => DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.slug', $permission)->orderBy('roles.slug')->pluck('roles.slug')->all();

        // Each row is one column of the matrix that would be dangerous to get wrong.
        $this->assertSame(['platform_owner'], $holders('flags.write'));
        $this->assertSame(['platform_owner'], $holders('users.mfa.reset'));
        $this->assertSame(['platform_admin', 'platform_owner'], $holders('cities.write'));
        $this->assertSame(['platform_admin', 'platform_owner'], $holders('audit.read'));
        $this->assertSame(['platform_admin', 'platform_owner'], $holders('system.status.read'));
        $this->assertSame(['platform_admin', 'platform_owner'], $holders('merchants.approve'));
        $this->assertSame(['city_manager', 'platform_admin', 'platform_owner'], $holders('zones.write'));
        $this->assertSame(['city_manager', 'platform_admin', 'platform_owner'], $holders('users.roles.assign'));
        $this->assertSame(['city_manager', 'dispatcher', 'platform_admin', 'platform_owner', 'support'], $holders('zones.read'));
        $this->assertSame(['city_manager', 'platform_admin', 'platform_owner', 'support'], $holders('users.read'));

        foreach (['customer', 'rider', 'merchant_owner', 'merchant_manager', 'merchant_staff'] as $role) {
            foreach (config('rbac.permissions') as $permission) {
                $this->assertFalse($has($role, $permission), "{$role} must carry no permission");
            }
        }

        $this->assertSame(['cities.read', 'merchants.read'], DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.slug', 'finance')->orderBy('permissions.slug')->pluck('permissions.slug')->all());
    }

    public function test_reseeding_removes_a_permission_that_left_the_catalogue(): void
    {
        $roleId = DB::table('roles')->where('slug', 'customer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'cities.write')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $this->seed(RbacSeeder::class);

        $this->assertFalse(DB::table('role_permissions')->where('role_id', $roleId)->exists());
    }
}
