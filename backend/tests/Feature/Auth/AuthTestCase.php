<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'correct-horse-battery';

    protected const ORIGINS = [
        'customer' => 'http://localhost:3000',
        'merchant' => 'http://localhost:3001',
        'admin' => 'http://localhost:3002',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'surfaces.origins' => self::ORIGINS,
            'sanctum.stateful' => ['localhost:3000', 'localhost:3001', 'localhost:3002'],
        ]);

        $this->seed(RbacSeeder::class);
    }

    protected function via(string $surface): static
    {
        return $this->withHeader('Origin', self::ORIGINS[$surface]);
    }

    protected function user(string $email = 'person@example.com'): User
    {
        return User::factory()->create(['email' => $email, 'password' => self::PASSWORD]);
    }

    protected function grantRole(User $user, string $role, string $scopeType = 'global', ?string $scopeId = null): void
    {
        DB::table('user_role_scopes')->insert([
            'id' => strtolower((string) Str::ulid()),
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('slug', $role)->value('id'),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);
    }

    /** @return TestResponse<JsonResponse> */
    protected function login(string $surface, string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->via($surface)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }
}
