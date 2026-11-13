<?php

namespace Tests\Feature;

use App\Support\ProductionGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * Backlog E-06. The unit test covers each rule; this one proves the guard is
 * wired to the application's real configuration and never carries a secret.
 */
class ProductionConfigTest extends TestCase
{
    private function makeSafe(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
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
    }

    public function test_a_fully_safe_configuration_is_accepted(): void
    {
        $this->makeSafe();

        $this->assertSame([], ProductionGuard::violations(ProductionGuard::read(config())));
        ProductionGuard::assertSafe(config());
    }

    public function test_the_facts_never_contain_a_secret_value(): void
    {
        $this->makeSafe();
        config(['demo.password' => 'super-secret-demo-value']);

        $facts = json_encode(ProductionGuard::read(config()));

        $this->assertIsString($facts);
        $this->assertStringNotContainsString('a-long-generated-secret-value', $facts);
        $this->assertStringNotContainsString('super-secret-demo-value', $facts);
        $this->assertStringNotContainsString((string) config('app.key'), $facts);
    }

    public function test_real_settings_drive_each_secret_fact(): void
    {
        $this->makeSafe();

        config(['database.connections.pgsql.password' => 'change_me_local_only']);
        $this->assertFalse(ProductionGuard::read(config())['database.password_is_real']);

        config(['demo.password' => 'x']);
        $this->assertTrue(ProductionGuard::read(config())['demo.password_is_set']);

        config(['app.key' => '']);
        $this->assertFalse(ProductionGuard::read(config())['app.key_is_set']);
    }

    public function test_unsafe_settings_are_refused_without_leaking_secrets_or_leaving_debug_on(): void
    {
        $this->makeSafe();
        config(['app.debug' => true, 'session.secure' => false, 'demo.password' => 'super-secret-demo-value', 'database.connections.pgsql.password' => 'change_me_local_only']);

        try {
            ProductionGuard::assertSafe(config());
            $this->fail('Unsafe settings were accepted.');
        } catch (RuntimeException $e) {
            $everything = $e->getMessage().json_encode($e->getTrace());

            $this->assertStringContainsString('Refusing to start', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-demo-value', $everything);
            $this->assertStringNotContainsString('change_me_local_only', $everything);
        }

        $this->assertFalse(config('app.debug'), 'debug must be off before the failure is rendered');
    }

    public function test_the_guard_does_not_apply_to_the_test_suite_itself(): void
    {
        $this->assertFalse(ProductionGuard::applies($this->app->environment(), $this->app->runningInConsole(), ['artisan', 'test']));
    }
}
