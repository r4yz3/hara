<?php

namespace Tests\Feature\Security;

use App\Support\ProductionGuard;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Behind the host's TLS proxy, Laravel learns the scheme and the client's
 * address only from X-Forwarded-* headers, and must believe them only from
 * the proxy itself (ADR-014). TRUSTED_PROXIES lists those addresses; it is
 * empty by default, so nothing forwarded is believed.
 */
class TrustedProxyTest extends TestCase
{
    private const PROXY = '203.0.113.10';

    private const CLIENT = '198.51.100.7';

    /**
     * Runs the framework's own middleware on a request from $from.
     *
     * @param  array<string, string>  $forwarded
     */
    private function through(string $from, array $forwarded): Request
    {
        $server = ['REMOTE_ADDR' => $from, 'HTTP_HOST' => 'api.example.test'];
        foreach ($forwarded as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('http://api.example.test/health/live', 'GET', server: $server);

        return app(TrustProxies::class)->handle($request, fn (Request $r) => $r);
    }

    private const FORWARDED = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => self::CLIENT,
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Host' => 'evil.example.test',
    ];

    public function test_nothing_forwarded_is_believed_by_default(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'));

        $request = $this->through(self::PROXY, self::FORWARDED);

        $this->assertFalse($request->isSecure());
        $this->assertSame(self::PROXY, $request->ip());
    }

    public function test_the_configured_proxy_is_believed_for_scheme_port_and_client(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);

        $request = $this->through(self::PROXY, self::FORWARDED);

        $this->assertTrue($request->isSecure());
        $this->assertSame(443, $request->getPort());
        $this->assertSame(self::CLIENT, $request->ip());
    }

    public function test_the_forwarded_host_is_never_believed(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);

        $this->assertSame('api.example.test', $this->through(self::PROXY, self::FORWARDED)->getHost());
    }

    public function test_a_client_that_is_not_the_proxy_cannot_fake_its_address_or_https(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);

        $request = $this->through(self::CLIENT, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '192.0.2.99']);

        $this->assertFalse($request->isSecure());
        $this->assertSame(self::CLIENT, $request->ip());
    }

    public function test_a_range_can_be_trusted(): void
    {
        config(['trustedproxy.proxies' => ['203.0.113.0/24']]);

        $this->assertTrue($this->through(self::PROXY, self::FORWARDED)->isSecure());
    }

    public function test_hsts_is_sent_behind_the_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->getJson('/health/live')
            ->assertOk();
        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_not_sent_when_someone_else_claims_https(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);

        $other = $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('/health/live');
        $this->assertFalse($other->headers->has('Strict-Transport-Security'));
    }

    public function test_the_setting_is_read_from_the_environment_as_a_list(): void
    {
        $parse = require base_path('config/trustedproxy.php');

        $this->assertSame([], $parse['proxies']);
        $this->assertContains('trustedproxy.proxies', array_keys(ProductionGuard::read(config())));
    }
}
