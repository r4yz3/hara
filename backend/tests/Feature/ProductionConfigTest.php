<?php

namespace Tests\Feature;

use App\Support\ProductionGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * Backlog E-06. The unit test covers each rule; this one proves the guard is
 * wired to the application's real configuration keys.
 */
class ProductionConfigTest extends TestCase
{
    public function test_the_guard_reads_real_configuration_keys(): void
    {
        $values = ProductionGuard::read(config());

        $this->assertSame(ProductionGuard::KEYS, array_keys($values));
        // Every key must exist in the real config; a typo would read null everywhere.
        foreach (['app.debug', 'session.http_only', 'session.same_site', 'cors.allowed_origins', 'surfaces.origins', 'hashing.driver'] as $key) {
            $this->assertNotNull($values[$key], "{$key} is not defined in the application's configuration.");
        }
    }

    public function test_the_local_development_settings_would_be_refused_in_production(): void
    {
        // Development defaults: http origins on localhost, cookies not marked secure.
        config(['surfaces.origins' => ['customer' => 'http://localhost:3000', 'merchant' => 'http://localhost:3001', 'admin' => 'http://localhost:3002']]);
        config(['session.secure' => false, 'app.url' => 'http://localhost:8000']);

        $violations = ProductionGuard::violations(ProductionGuard::read(config()));

        $this->assertNotEmpty($violations);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to start in production');
        ProductionGuard::assertSafe(ProductionGuard::read(config()));
    }

    public function test_a_fully_safe_configuration_is_accepted(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://api.example.test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cors.allowed_origins' => ['https://app.example.test', 'https://merchant.example.test', 'https://admin.example.test'],
            'cors.allowed_origins_patterns' => [],
            'surfaces.origins' => ['customer' => 'https://app.example.test', 'merchant' => 'https://merchant.example.test', 'admin' => 'https://admin.example.test'],
            'hashing.driver' => 'argon2id',
            'demo.password' => null,
            'database.connections.pgsql.password' => 'a-long-generated-secret-value',
        ]);

        $this->assertSame([], ProductionGuard::violations(ProductionGuard::read(config())));
    }
}
