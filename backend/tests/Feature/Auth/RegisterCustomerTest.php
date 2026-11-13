<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;

class RegisterCustomerTest extends AuthTestCase
{
    private const ACCEPTED = ['data' => ['status' => 'registration_received']];

    /** @return array<string, string> */
    private function payload(string $email = 'new@example.com'): array
    {
        return ['name' => 'New Person', 'email' => $email, 'password' => self::PASSWORD];
    }

    public function test_registration_is_closed_unless_the_feature_flag_is_on(): void
    {
        config(['features.public_registration' => false]);

        $this->via('customer')->postJson('/api/v1/auth/register', $this->payload())->assertStatus(404);

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_registration_creates_a_customer_and_nothing_more(): void
    {
        config(['features.public_registration' => true]);

        $this->via('customer')->postJson('/api/v1/auth/register', $this->payload('New@Example.com'))
            ->assertStatus(202)->assertJson(self::ACCEPTED);

        $user = DB::table('users')->sole();
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(1, DB::table('customer_profiles')->where('user_id', $user->id)->count());

        $grants = DB::table('user_role_scopes')->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_id', $user->id)->pluck('roles.slug')->all();
        $this->assertSame(['customer'], $grants);
        $this->assertGuest();
    }

    public function test_role_scope_and_status_in_the_request_are_ignored(): void
    {
        config(['features.public_registration' => true]);

        $this->via('customer')->postJson('/api/v1/auth/register', $this->payload() + [
            'role' => 'platform_owner',
            'roles' => ['platform_owner'],
            'scope_type' => 'global',
            'status' => 'suspended',
            'email_verified_at' => '2020-01-01T00:00:00Z',
            'id' => '01jexample0000000000000000',
        ])->assertStatus(202);

        $user = DB::table('users')->sole();
        $this->assertNotSame('01jexample0000000000000000', $user->id);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(['customer'], DB::table('user_role_scopes')->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')->pluck('roles.slug')->all());
    }

    public function test_an_existing_email_gets_the_same_answer_and_changes_nothing(): void
    {
        config(['features.public_registration' => true]);
        $existing = $this->user('taken@example.com');

        $taken = $this->via('customer')->postJson('/api/v1/auth/register', $this->payload('taken@example.com'));
        $fresh = $this->via('customer')->postJson('/api/v1/auth/register', $this->payload('fresh@example.com'));

        // Same status and same body shape and content, apart from the request id.
        $taken->assertStatus(202);
        $fresh->assertStatus(202);
        $this->assertSame($fresh->json('data'), $taken->json('data'));
        $this->assertSame(array_keys($fresh->json()), array_keys($taken->json()));

        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame($existing->name, DB::table('users')->where('id', $existing->id)->value('name'));
        // The existing account's password is untouched: it still signs in.
        $this->login('customer', 'taken@example.com')->assertOk();
    }

    public function test_weak_or_malformed_input_is_a_validation_error(): void
    {
        config(['features.public_registration' => true]);

        $this->via('customer')->postJson('/api/v1/auth/register', ['name' => '', 'email' => 'not-an-email', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'email', 'password']]]);

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_registration_is_only_offered_on_the_customer_surface(): void
    {
        config(['features.public_registration' => true]);

        $this->via('admin')->postJson('/api/v1/auth/register', $this->payload())->assertStatus(404);
        $this->withoutHeader('Origin')->postJson('/api/v1/auth/register', $this->payload())->assertStatus(403);

        $this->assertSame(0, DB::table('users')->count());
    }
}
