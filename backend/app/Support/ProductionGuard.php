<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Refuses to run outside local development with settings that are fine on a
 * laptop and dangerous in front of customers (backlog E-06, spec 4.3, 11.2).
 *
 * Secrets never leave read(): it reduces each secret setting to a yes/no
 * fact, so no secret value is ever held in an argument, an exception message
 * or a stack frame.
 *
 * It checks configuration only. It cannot see the proxy, TLS termination,
 * firewall or backups, and it is not a substitute for the deployment review.
 */
final class ProductionGuard
{
    /** Environments where development settings are expected. Everything else is guarded. */
    public const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Console commands an operator needs in order to build the image or
     * repair a broken environment. Every other command (queue workers,
     * schedulers, tinker, seeders, ...) is guarded like a web request.
     */
    public const REPAIR_COMMANDS = [
        'key:generate', 'migrate', 'migrate:status', 'config:clear', 'config:cache', 'cache:clear',
        'route:clear', 'view:clear', 'optimize', 'optimize:clear', 'package:discover', 'vendor:publish',
        'storage:link', 'about', 'list', 'help', 'env',
    ];

    private const PLACEHOLDER_SECRETS = ['', 'change_me_local_only', 'password', 'secret', 'postgres'];

    /**
     * Whether the guard applies to this process.
     *
     * @param  list<string>  $argv
     */
    public static function applies(string $environment, bool $runningInConsole, array $argv): bool
    {
        if (in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            return false;
        }

        if (! $runningInConsole) {
            return true;
        }

        // `php artisan` with no command only lists commands.
        return ! in_array($argv[1] ?? 'list', self::REPAIR_COMMANDS, true);
    }

    /**
     * The facts the rules need. Secret settings become booleans here.
     *
     * @return array<string, mixed>
     */
    public static function read(Repository $config): array
    {
        $key = $config->get('app.key');
        $demo = $config->get('demo.password');
        $databasePassword = $config->get('database.connections.pgsql.password');

        return [
            'app.debug' => $config->get('app.debug'),
            'app.key_is_set' => is_string($key) && strlen($key) >= 32,
            'app.url' => $config->get('app.url'),
            'session.secure' => $config->get('session.secure'),
            'session.http_only' => $config->get('session.http_only'),
            'session.same_site' => $config->get('session.same_site'),
            'cors.allowed_origins' => $config->get('cors.allowed_origins'),
            'cors.allowed_origins_patterns' => $config->get('cors.allowed_origins_patterns'),
            'surfaces.origins' => $config->get('surfaces.origins'),
            'hashing.driver' => $config->get('hashing.driver'),
            'demo.password_is_set' => is_string($demo) && $demo !== '',
            'database.password_is_real' => is_string($databasePassword)
                && ! in_array(strtolower($databasePassword), self::PLACEHOLDER_SECRETS, true),
            'trustedproxy.proxies' => $config->get('trustedproxy.proxies'),
        ];
    }

    /**
     * Every rule fails closed: a missing or wrongly typed fact is a violation.
     *
     * @param  array<string, mixed>  $facts  the output of read()
     * @return list<string> one sentence per problem
     */
    public static function violations(array $facts): array
    {
        $problems = [];

        if (($facts['app.debug'] ?? null) !== false) {
            $problems[] = 'APP_DEBUG must be explicitly false.';
        }

        if (($facts['app.key_is_set'] ?? null) !== true) {
            $problems[] = 'APP_KEY must be set.';
        }

        if (! self::isHttps($facts['app.url'] ?? null)) {
            $problems[] = 'APP_URL must be an https URL.';
        }

        if (($facts['session.secure'] ?? null) !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true so session cookies travel over HTTPS only.';
        }

        if (($facts['session.http_only'] ?? null) !== true) {
            $problems[] = 'Session cookies must be HttpOnly.';
        }

        if (! in_array($facts['session.same_site'] ?? null, ['lax', 'strict'], true)) {
            $problems[] = 'Session cookie SameSite must be lax or strict.';
        }

        $corsOrigins = $facts['cors.allowed_origins'] ?? null;
        $corsBad = ! is_array($corsOrigins)
            || $corsOrigins === []
            || ($facts['cors.allowed_origins_patterns'] ?? null) !== []
            || array_filter($corsOrigins, fn (mixed $origin): bool => $origin === '*' || ! self::isHttps($origin)) !== [];
        if ($corsBad) {
            $problems[] = 'CORS must list explicit https origins only: no wildcard, no patterns.';
        }

        $surfaces = $facts['surfaces.origins'] ?? null;
        $badSurface = ! is_array($surfaces) || count($surfaces) !== 3 || array_filter(
            $surfaces,
            fn (mixed $origin): bool => ! self::isHttps($origin) || in_array(parse_url((string) $origin, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true),
        ) !== [];
        if ($badSurface) {
            $problems[] = 'Each app origin (customer, merchant, admin) must be a public https origin.';
        } elseif (count(array_unique($surfaces)) !== count($surfaces)) {
            $problems[] = 'The three app origins must be distinct, or their sessions cannot be kept apart.';
        }

        if (! in_array($facts['hashing.driver'] ?? null, ['argon2id', 'argon'], true)) {
            $problems[] = 'HASH_DRIVER must be argon2id.';
        }

        if (($facts['demo.password_is_set'] ?? null) !== false) {
            $problems[] = 'DEMO_PASSWORD must be unset: demo accounts do not belong in production.';
        }

        if (($facts['database.password_is_real'] ?? null) !== true) {
            $problems[] = 'DB_PASSWORD must be a real secret, not empty or a placeholder.';
        }

        $proxies = $facts['trustedproxy.proxies'] ?? null;
        if (! is_array($proxies) || array_filter($proxies, fn (mixed $proxy): bool => ! self::isProxyAddress($proxy)) !== []) {
            $problems[] = 'TRUSTED_PROXIES must list specific proxy IP addresses or ranges, never "*" or a range covering every address.';
        }

        return $problems;
    }

    /**
     * Reads the configuration itself, so no caller ever passes settings in.
     */
    public static function assertSafe(Repository $config): void
    {
        $problems = self::violations(self::read($config));

        if ($problems !== []) {
            // Whatever renders this failure must not show a debug page.
            $config->set('app.debug', false);

            throw new RuntimeException("Refusing to start with unsafe settings:\n- ".implode("\n- ", $problems));
        }
    }

    /**
     * An IP address, or a CIDR range narrower than "everyone" (/0).
     */
    private static function isProxyAddress(mixed $proxy): bool
    {
        if (! is_string($proxy)) {
            return false;
        }

        [$address, $mask] = array_pad(explode('/', $proxy, 2), 2, null);
        $version = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4
            : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : null);

        if ($version === null) {
            return false;
        }
        if ($mask === null) {
            return true;
        }

        $max = $version === 4 ? 32 : 128;

        return ctype_digit($mask) && (int) $mask >= 1 && (int) $mask <= $max;
    }

    private static function isHttps(mixed $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://') && is_string(parse_url($url, PHP_URL_HOST));
    }
}
