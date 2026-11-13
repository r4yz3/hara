<?php

namespace Tests\Unit\Support;

use App\Support\ProductionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Backlog E-06: settings that are acceptable on a laptop and dangerous in
 * production are refused before the application serves a request.
 */
class ProductionGuardTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function safe(): array
    {
        return [
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://api.example.test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cors.allowed_origins' => ['https://app.example.test', 'https://merchant.example.test', 'https://admin.example.test'],
            'cors.allowed_origins_patterns' => [],
            'surfaces.origins' => [
                'customer' => 'https://app.example.test',
                'merchant' => 'https://merchant.example.test',
                'admin' => 'https://admin.example.test',
            ],
            'hashing.driver' => 'argon2id',
            'demo.password' => null,
            'database.connections.pgsql.password' => 'a-long-generated-secret-value',
        ];
    }

    public function test_a_safe_configuration_has_no_violations(): void
    {
        $this->assertSame([], ProductionGuard::violations(self::safe()));
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('unsafeSettings')]
    public function test_each_unsafe_setting_is_reported(array $override, string $expected): void
    {
        $violations = ProductionGuard::violations(array_merge(self::safe(), $override));

        $this->assertCount(1, $violations, implode(' | ', $violations));
        $this->assertStringContainsString($expected, $violations[0]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unsafeSettings(): array
    {
        $origins = self::safe()['surfaces.origins'];

        return [
            'debug on' => [['app.debug' => true], 'APP_DEBUG'],
            'no app key' => [['app.key' => ''], 'APP_KEY'],
            'api over http' => [['app.url' => 'http://api.example.test'], 'APP_URL'],
            'cookie not secure' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'cookie secure unset' => [['session.secure' => null], 'SESSION_SECURE_COOKIE'],
            'cookie readable by script' => [['session.http_only' => false], 'HttpOnly'],
            'cross-site cookie' => [['session.same_site' => 'none'], 'SameSite'],
            'wildcard cors' => [['cors.allowed_origins' => ['*']], 'CORS'],
            'cors pattern' => [['cors.allowed_origins_patterns' => ['#.*#']], 'CORS'],
            'cors over http' => [['cors.allowed_origins' => ['http://app.example.test']], 'CORS'],
            'app origin over http' => [['surfaces.origins' => ['customer' => 'http://app.example.test'] + $origins], 'app origin'],
            'app origin on localhost' => [['surfaces.origins' => ['admin' => 'https://localhost:3002'] + $origins], 'app origin'],
            'two apps on one origin' => [['surfaces.origins' => ['merchant' => $origins['customer']] + $origins], 'distinct'],
            'weak hashing' => [['hashing.driver' => 'bcrypt'], 'HASH_DRIVER'],
            'demo accounts enabled' => [['demo.password' => 'something'], 'DEMO_PASSWORD'],
            'placeholder database password' => [['database.connections.pgsql.password' => 'change_me_local_only'], 'DB_PASSWORD'],
            'empty database password' => [['database.connections.pgsql.password' => ''], 'DB_PASSWORD'],
        ];
    }

    public function test_every_problem_is_listed_at_once(): void
    {
        $violations = ProductionGuard::violations(array_merge(self::safe(), ['app.debug' => true, 'session.secure' => false]));

        $this->assertCount(2, $violations);
    }

    public function test_messages_never_contain_secret_values(): void
    {
        $violations = ProductionGuard::violations(array_merge(self::safe(), [
            'demo.password' => 'super-secret-demo-value',
            'database.connections.pgsql.password' => 'change_me_local_only',
            'app.debug' => true,
        ]));

        $text = implode(' ', $violations);
        $this->assertStringNotContainsString('super-secret-demo-value', $text);
        $this->assertStringNotContainsString('change_me_local_only', $text);
    }
}
