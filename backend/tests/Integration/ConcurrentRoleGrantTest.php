<?php

namespace Tests\Integration;

use App\Application\Commands\RoleGrantService;
use App\Domain\Identity\Scope;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConcurrentRoleGrantTest extends PostgresConcurrencyTestCase
{
    public function test_a_replacement_grant_waits_for_revocation_and_leaves_one_active_grant(): void
    {
        $this->seed(RbacSeeder::class);
        $owner = User::factory()->create();
        $target = User::factory()->create();
        DB::table('user_role_scopes')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $owner->id,
            'role_id' => DB::table('roles')->where('slug', 'platform_owner')->value('id'),
            'scope_type' => 'global',
        ]);

        $roles = $this->app->make(RoleGrantService::class);
        $original = $roles->grant($owner, $target, 'support', Scope::global());

        DB::beginTransaction();
        try {
            $roles->revoke($owner, $original);
            $replacement = $this->startContender(<<<'PHP'
                echo $app->make(App\Application\Commands\RoleGrantService::class)->grant(
                    App\Models\User::query()->findOrFail($argv[2]),
                    App\Models\User::query()->findOrFail($argv[3]),
                    'support',
                    App\Domain\Identity\Scope::global(),
                );
                PHP, [(string) $owner->id, (string) $target->id]);
        } finally {
            DB::rollBack();
        }

        $this->assertNotSame($original, $replacement);
        $this->assertDatabaseHas('user_role_scopes', ['id' => $original, 'user_id' => $target->id]);
        $activeGrant = DB::table('user_role_scopes')->where('user_id', $target->id)->whereNull('revoked_at')->sole();
        $this->assertSame($replacement, $activeGrant->id);
        $this->assertSame(2, DB::table('audit_logs')->where('subject_id', $target->id)->where('action', 'role.granted')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('subject_id', $target->id)->where('action', 'role.revoked')->count());
    }
}
