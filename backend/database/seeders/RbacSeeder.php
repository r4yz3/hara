<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes config/rbac.php into roles, permissions and role_permissions.
 * Safe in every environment and safe to re-run: it converges the tables on
 * the catalogue and never touches user grants.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<string> $permissions */
        $permissions = config('rbac.permissions');
        /** @var array<string, array{label: string, permissions: list<string>|'*'}> $roles */
        $roles = config('rbac.roles');

        DB::transaction(function () use ($permissions, $roles): void {
            foreach ($permissions as $slug) {
                if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                    DB::table('permissions')->insert(['id' => $this->ulid(), 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('permissions')->whereNotIn('slug', $permissions)->delete();

            /** @var array<string, string> $permissionIds */
            $permissionIds = DB::table('permissions')->pluck('id', 'slug')->all();

            foreach ($roles as $slug => $role) {
                $roleId = DB::table('roles')->where('slug', $slug)->value('id');

                if ($roleId === null) {
                    $roleId = $this->ulid();
                    DB::table('roles')->insert(['id' => $roleId, 'slug' => $slug, 'label' => $role['label'], 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('roles')->where('id', $roleId)->update(['label' => $role['label'], 'updated_at' => now()]);
                }

                $wanted = $role['permissions'] === '*' ? $permissions : $role['permissions'];
                $wantedIds = array_map(fn (string $permission): string => $permissionIds[$permission], $wanted);

                DB::table('role_permissions')->where('role_id', $roleId)->whereNotIn('permission_id', $wantedIds)->delete();

                foreach ($wantedIds as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        });
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }
}
