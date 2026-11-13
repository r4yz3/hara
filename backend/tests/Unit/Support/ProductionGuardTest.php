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
            'app.key_is_set' => true,
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
            'demo.password_is_set' => false,
            'database.password_is_real' => true,
            'trustedproxy.proxies' => [],
        ];
    }

    public function test_a_safe_configuration_has_no_violations(): void
    {
        $this->assertSame([], ProductionGuard::violations(self::safe()));
    }

    public function test_specific_proxy_addresses_and_ranges_are_accepted(): void
    {
        $facts = array_merge(self::safe(), ['trustedproxy.proxies' => ['203.0.113.10', '10.0.0.0/8', '2001:db8::/32', 'fd00::1']]);

        $this->assertSame([], ProductionGuard::violations($facts));
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
            'every proxy trusted' => [['trustedproxy.proxies' => ['*']], 'TRUSTED_PROXIES'],
            'every proxy trusted, alternate form' => [['trustedproxy.proxies' => ['**']], 'TRUSTED_PROXIES'],
            'proxy list missing' => [['trustedproxy.proxies' => null], 'TRUSTED_PROXIES'],
            'proxy is a host name' => [['trustedproxy.proxies' => ['proxy.example.test']], 'TRUSTED_PROXIES'],
            'proxy range too wide' => [['trustedproxy.proxies' => ['0.0.0.0/0']], 'TRUSTED_PROXIES'],
            'proxy range too wide, ipv6' => [['trustedproxy.proxies' => ['::/0']], 'TRUSTED_PROXIES'],
            'proxy mask invalid' => [['trustedproxy.proxies' => ['10.0.0.0/33']], 'TRUSTED_PROXIES'],
            'debug unset' => [['app.debug' => null], 'APP_DEBUG'],
            'debug as a string' => [['app.debug' => 'false'], 'APP_DEBUG'],
            'no app key' => [['app.key_is_set' => false], 'APP_KEY'],
            'api over http' => [['app.url' => 'http://api.example.test'], 'APP_URL'],
            'cookie not secure' => [['session.secure' => false], 'SESSION_SECURE_COOKIE'],
            'cookie secure unset' => [['session.secure' => null], 'SESSION_SECURE_COOKIE'],
            'cookie readable by script' => [['session.http_only' => false], 'HttpOnly'],
            'cross-site cookie' => [['session.same_site' => 'none'], 'SameSite'],
            'wildcard cors' => [['cors.allowed_origins' => ['*']], 'CORS'],
            'cors pattern' => [['cors.allowed_origins_patterns' => ['#.*#']], 'CORS'],
            'cors patterns unset' => [['cors.allowed_origins_patterns' => null], 'CORS'],
            'cors over http' => [['cors.allowed_origins' => ['http://app.example.test']], 'CORS'],
            'cors unset' => [['cors.allowed_origins' => null], 'CORS'],
            'app origin over http' => [['surfaces.origins' => ['customer' => 'http://app.example.test'] + $origins], 'app origin'],
            'app origin on localhost' => [['surfaces.origins' => ['admin' => 'https://localhost:3002'] + $origins], 'app origin'],
            'app origins unset' => [['surfaces.origins' => null], 'app origin'],
            'two apps on one origin' => [['surfaces.origins' => ['merchant' => $origins['customer']] + $origins], 'distinct'],
            'weak hashing' => [['hashing.driver' => 'bcrypt'], 'HASH_DRIVER'],
            'demo accounts enabled' => [['demo.password_is_set' => true], 'DEMO_PASSWORD'],
            'placeholder database password' => [['database.password_is_real' => false], 'DB_PASSWORD'],
        ];
    }

    public function test_an_empty_set_of_facts_fails_every_rule(): void
    {
        // Nothing is assumed safe by being absent.
        $this->assertCount(12, ProductionGuard::violations([]));
    }

    public function test_every_problem_is_listed_at_once(): void
    {
        $violations = ProductionGuard::violations(array_merge(self::safe(), ['app.debug' => true, 'session.secure' => false]));

        $this->assertCount(2, $violations);
    }

    /** @param list<string> $argv */
    #[DataProvider('processes')]
    public function test_the_guard_applies_everywhere_except_development_and_repair_commands(string $environment, bool $console, array $argv, bool $expected): void
    {
        $this->assertSame($expected, ProductionGuard::applies($environment, $console, $argv));
    }

    /** @return array<string, array{string, bool, list<string>, bool}> */
    public static function processes(): array
    {
        return [
            'local web' => ['local', false, [], false],
            'test suite' => ['testing', true, ['artisan', 'test'], false],
            'production web' => ['production', false, [], true],
            'staging web' => ['staging', false, [], true],
            'mistyped environment' => ['prodution', false, [], true],
            'production queue worker' => ['production', true, ['artisan', 'queue:work'], true],
            'production scheduler' => ['production', true, ['artisan', 'schedule:run'], true],
            'production tinker' => ['production', true, ['artisan', 'tinker'], true],
            'production seeder' => ['production', true, ['artisan', 'db:seed'], true],
            'production key generation' => ['production', true, ['artisan', 'key:generate'], false],
            'production migrate' => ['production', true, ['artisan', 'migrate'], false],
            'image build discovery' => ['production', true, ['artisan', 'package:discover'], false],
            'bare artisan' => ['production', true, ['artisan'], false],
        ];
    }
}
