<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to run in production with settings that are fine on a laptop and
 * dangerous in front of customers (backlog E-06, spec 4.3 and 11.2).
 *
 * It checks configuration only. It cannot see the proxy, TLS termination,
 * firewall or backups, and it is not a substitute for the deployment review.
 */
final class ProductionGuard
{
    private const PLACEHOLDER_SECRETS = ['', 'change_me_local_only', 'password', 'secret', 'postgres'];

    /**
     * @param  array<string, mixed>  $config  flat "dotted.key" => value
     * @return list<string> one sentence per problem; never includes a secret value
     */
    public static function violations(array $config): array
    {
        $problems = [];

        if (($config['app.debug'] ?? false) !== false) {
            $problems[] = 'APP_DEBUG must be false.';
        }

        if (! is_string($config['app.key'] ?? null) || strlen($config['app.key']) < 32) {
            $problems[] = 'APP_KEY must be set.';
        }

        if (! self::isHttps($config['app.url'] ?? null)) {
            $problems[] = 'APP_URL must be an https URL.';
        }

        if (($config['session.secure'] ?? null) !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true so session cookies travel over HTTPS only.';
        }

        if (($config['session.http_only'] ?? null) !== true) {
            $problems[] = 'Session cookies must be HttpOnly.';
        }

        if (! in_array($config['session.same_site'] ?? null, ['lax', 'strict'], true)) {
            $problems[] = 'Session cookie SameSite must be lax or strict.';
        }

        $corsOrigins = (array) ($config['cors.allowed_origins'] ?? []);
        $corsBad = $corsOrigins === []
            || ($config['cors.allowed_origins_patterns'] ?? []) !== []
            || array_filter($corsOrigins, fn (mixed $origin): bool => $origin === '*' || ! self::isHttps($origin)) !== [];
        if ($corsBad) {
            $problems[] = 'CORS must list explicit https origins only: no wildcard, no patterns.';
        }

        /** @var array<string, mixed> $surfaces */
        $surfaces = (array) ($config['surfaces.origins'] ?? []);
        $badSurface = count($surfaces) !== 3 || array_filter(
            $surfaces,
            fn (mixed $origin): bool => ! self::isHttps($origin) || in_array(parse_url((string) $origin, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true),
        ) !== [];
        if ($badSurface) {
            $problems[] = 'Each app origin (customer, merchant, admin) must be a public https origin.';
        } elseif (count(array_unique($surfaces)) !== count($surfaces)) {
            $problems[] = 'The three app origins must be distinct, or their sessions cannot be kept apart.';
        }

        if (! in_array($config['hashing.driver'] ?? null, ['argon2id', 'argon'], true)) {
            $problems[] = 'HASH_DRIVER must be argon2id.';
        }

        if (($config['demo.password'] ?? null) !== null && $config['demo.password'] !== '') {
            $problems[] = 'DEMO_PASSWORD must be unset: demo accounts do not belong in production.';
        }

        $dbPassword = $config['database.connections.pgsql.password'] ?? '';
        if (! is_string($dbPassword) || in_array(strtolower($dbPassword), self::PLACEHOLDER_SECRETS, true)) {
            $problems[] = 'DB_PASSWORD must be a real secret, not empty or a placeholder.';
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function assertSafe(array $config): void
    {
        $problems = self::violations($config);

        if ($problems !== []) {
            throw new RuntimeException("Refusing to start in production with unsafe settings:\n- ".implode("\n- ", $problems));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(Repository $config): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $values[$key] = $config->get($key);
        }

        return $values;
    }

    /** The settings this guard reads. */
    public const KEYS = [
        'app.debug', 'app.key', 'app.url',
        'session.secure', 'session.http_only', 'session.same_site',
        'cors.allowed_origins', 'cors.allowed_origins_patterns',
        'surfaces.origins', 'hashing.driver', 'demo.password',
        'database.connections.pgsql.password',
    ];

    private static function isHttps(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://') && is_string(parse_url($url, PHP_URL_HOST));
    }
}
