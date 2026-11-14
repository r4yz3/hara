<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

/**
 * Backlog B-02: PATCH /api/v1/me changes the display name and nothing else.
 */
class ProfileUpdateTest extends AuthTestCase
{
    /** @return Collection<int, stdClass> */
    private function audits(): Collection
    {
        return DB::table('audit_logs')->where('action', 'user.profile_updated')->get();
    }

    public function test_a_customer_can_change_their_name(): void
    {
        $user = $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $response = $this->via('customer')->patchJson('/api/v1/me', ['name' => 'Maria Santos'])->assertOk();

        $response->assertJsonPath('data.name', 'Maria Santos')->assertJsonPath('data.surface', 'customer');
        $this->assertSame(
            ['id', 'name', 'email', 'email_verified', 'mfa_enabled', 'surface', 'grants'],
            array_keys($response->json('data')),
        );
        $this->assertSame('Maria Santos', DB::table('users')->where('id', $user->id)->value('name'));
        // A real follow-up request loads the user afresh.
        $this->app['auth']->forgetGuards();
        $this->via('customer')->getJson('/api/v1/me')->assertJsonPath('data.name', 'Maria Santos');
    }

    public function test_an_admin_can_change_their_name_and_keeps_their_grants(): void
    {
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'support');
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->via('admin')->patchJson('/api/v1/me', ['name' => 'Ana Reyes'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana Reyes')
            ->assertJsonPath('data.surface', 'admin')
            ->assertJsonPath('data.grants.0.role', 'support');
    }

    public function test_the_name_is_trimmed(): void
    {
        $user = $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', ['name' => "  Juan dela Cruz \t "])
            ->assertOk()->assertJsonPath('data.name', 'Juan dela Cruz');
        $this->assertSame('Juan dela Cruz', DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_a_name_of_160_characters_is_accepted_and_161_is_not(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', ['name' => str_repeat('a', 160)])->assertOk();
        $this->via('customer')->patchJson('/api/v1/me', ['name' => str_repeat('a', 161)])->assertStatus(422);
        // Trimming comes first: padding does not count against the limit.
        $this->via('customer')->patchJson('/api/v1/me', ['name' => '  '.str_repeat('b', 160).'  '])->assertOk();
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([null])]
    #[TestWith([['array']])]
    #[TestWith([12])]
    public function test_a_missing_or_blank_name_is_refused(mixed $name): void
    {
        $user = $this->user();
        $before = DB::table('users')->where('id', $user->id)->value('name');
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', ['name' => $name])->assertStatus(422);
        $this->via('customer')->patchJson('/api/v1/me', [])->assertStatus(422);
        $this->assertSame($before, DB::table('users')->where('id', $user->id)->value('name'));
        $this->assertCount(0, $this->audits());
    }

    #[TestWith(["Ana\nReyes"])]
    #[TestWith(["Ana\tReyes"])]
    #[TestWith(["Ana\x00Reyes"])]
    #[TestWith(["Ana\x1bReyes"])]
    #[TestWith(["Ana\x7fReyes"])]
    // Bidirectional overrides and isolates: they make a name display as
    // something other than what it contains.
    #[TestWith(["Ana\u{202E}seyeR"])]
    #[TestWith(["Ana\u{2066}Reyes"])]
    // Invisible characters that let two names look identical.
    #[TestWith(["An\u{200B}a Reyes"])]
    #[TestWith(["Ana\u{FEFF}Reyes"])]
    public function test_control_characters_are_refused(string $name): void
    {
        $user = $this->user();
        $before = DB::table('users')->where('id', $user->id)->value('name');
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', ['name' => $name])->assertStatus(422);
        $this->assertSame($before, DB::table('users')->where('id', $user->id)->value('name'));
    }

    public function test_accented_names_and_emoji_with_joiners_are_accepted(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        foreach (['José Niño', 'Ma. Clara dela Cruz-Santos', "Ana \u{1F469}\u{200D}\u{1F373}"] as $name) {
            $this->via('customer')->patchJson('/api/v1/me', ['name' => $name])->assertOk()->assertJsonPath('data.name', $name);
        }
    }

    public function test_other_fields_in_the_body_are_ignored(): void
    {
        $user = $this->user();
        $this->grantRole($user, 'support');
        $this->login('customer', 'person@example.com')->assertOk();
        $before = (array) DB::table('users')->where('id', $user->id)->first();
        $grants = DB::table('user_role_scopes')->where('user_id', $user->id)->count();

        $this->via('customer')->patchJson('/api/v1/me', [
            'name' => 'New Name',
            'email' => 'attacker@example.com',
            'phone_e164' => '+639171234567',
            'status' => 'suspended',
            'roles' => ['platform_owner'],
            'password' => 'a-brand-new-password',
            'id' => strtolower((string) Str::ulid()),
        ])->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.email', 'person@example.com');

        $after = (array) DB::table('users')->where('id', $user->id)->first();
        $this->assertSame('New Name', $after['name']);
        unset($before['name'], $before['updated_at'], $after['name'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($grants, DB::table('user_role_scopes')->where('user_id', $user->id)->count());

        // The old password still works and the new one does not.
        $this->login('customer', 'person@example.com')->assertOk();
        $this->login('customer', 'person@example.com', 'a-brand-new-password')->assertStatus(401);
    }

    public function test_a_body_without_a_name_cannot_change_other_fields(): void
    {
        $user = $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', ['email' => 'attacker@example.com', 'status' => 'suspended'])->assertStatus(422);
        $this->assertSame('person@example.com', DB::table('users')->where('id', $user->id)->value('email'));
        $this->assertSame('active', DB::table('users')->where('id', $user->id)->value('status'));
    }

    public function test_it_needs_a_session(): void
    {
        $this->via('customer')->patchJson('/api/v1/me', ['name' => 'Nobody'])
            ->assertStatus(401)->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_a_rider_bearer_token_is_not_accepted(): void
    {
        $user = $this->user('rider@example.com');
        DB::table('riders')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $this->withoutHeader('Origin');
        $token = $this->postJson('/api/v1/auth/mobile/token', [
            'email' => 'rider@example.com', 'password' => self::PASSWORD, 'device_name' => 'Test phone', 'platform' => 'android',
        ])->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'Via Token'])->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', self::ORIGINS['customer'])->withToken($token)
            ->patchJson('/api/v1/me', ['name' => 'Via Token'])->assertStatus(401);
        $this->assertNotSame('Via Token', DB::table('users')->where('id', $user->id)->value('name'));
    }

    #[TestWith(['production'])]
    #[TestWith(['staging'])]
    public function test_an_enrolment_only_admin_session_is_refused(string $environment): void
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, 'platform_owner');
        $before = DB::table('users')->where('id', $user->id)->value('name');
        $this->app['env'] = $environment;
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->via('admin')->patchJson('/api/v1/me', ['name' => 'Sneaky'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Set up two-step sign-in before using the admin console.');
        $this->assertSame($before, DB::table('users')->where('id', $user->id)->value('name'));
        $this->assertCount(0, $this->audits());
    }

    public function test_a_change_writes_one_audit_entry_with_only_the_name(): void
    {
        $user = $this->user();
        $old = (string) DB::table('users')->where('id', $user->id)->value('name');
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->patchJson('/api/v1/me', [
            'name' => 'Audited Name', 'email' => 'x@example.com', 'password' => 'a-brand-new-password',
        ])->assertOk();

        $rows = $this->audits();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($user->id, $row->actor_user_id);
        $this->assertSame('user', $row->subject_type);
        $this->assertSame($user->id, $row->subject_id);
        $this->assertNull($row->scope_city_id);
        $this->assertSame(['name' => $old], json_decode($row->before_json, true));
        $this->assertSame(['name' => 'Audited Name'], json_decode($row->after_json, true));
        $this->assertNotNull($row->ip_hash);
    }

    public function test_an_unchanged_name_writes_no_audit_entry(): void
    {
        $user = $this->user();
        $this->login('customer', 'person@example.com')->assertOk();
        $current = (string) DB::table('users')->where('id', $user->id)->value('name');

        $this->via('customer')->patchJson('/api/v1/me', ['name' => " {$current} "])->assertOk()->assertJsonPath('data.name', $current);

        $this->assertCount(0, $this->audits());
    }
}
