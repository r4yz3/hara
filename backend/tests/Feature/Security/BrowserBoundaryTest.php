<?php

namespace Tests\Feature\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Backlog E-02: the browser-facing boundary of the API. Security headers,
 * cross-origin negatives and session cookie attributes. CSRF is disabled by
 * the test framework, so it is checked against the running stack instead
 * (docs/qa/evidence/sprint7-browser-boundary-check.txt).
 */
class BrowserBoundaryTest extends AuthTestCase
{
    private const CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /** @param  TestResponse<JsonResponse>|TestResponse<Response>  $response */
    private function assertHardened(TestResponse $response, string $what): void
    {
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $what);
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $what);
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $what);
        $this->assertSame(self::CSP, $response->headers->get('Content-Security-Policy'), $what);
        $this->assertFalse($response->headers->has('X-Powered-By'), $what);
    }

    /** @return TestResponse<Response> */
    private function preflight(string $origin, string $path = '/api/v1/auth/login', string $headers = 'content-type,x-csrf-token'): TestResponse
    {
        return $this->withoutHeader('Origin')->call('OPTIONS', $path, server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => $headers,
        ]);
    }

    /** @param  TestResponse<JsonResponse>|TestResponse<Response>  $response */
    private function cookie(TestResponse $response, string $name): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail("No {$name} cookie was set.");
    }

    public function test_every_kind_of_response_carries_the_security_headers(): void
    {
        $this->user();

        $this->assertHardened($this->getJson('/health/live')->assertOk(), 'health');
        $this->assertHardened($this->getJson('/api/v1/public/config')->assertOk(), 'public json');
        $this->assertHardened($this->via('admin')->getJson('/api/v1/me')->assertStatus(401), '401');
        $this->assertHardened($this->via('admin')->getJson('/api/v1/admin/cities/'.str_repeat('0', 26))->assertStatus(401), 'admin 401');
        $this->assertHardened($this->withoutHeader('Origin')->getJson('/api/v1/nothing-here')->assertStatus(404), '404');
        $this->assertHardened($this->via('customer')->postJson('/api/v1/auth/login', [])->assertStatus(422), '422');
        $this->assertHardened($this->preflight(self::ORIGINS['admin']), 'preflight');
    }

    public function test_api_responses_are_never_stored_by_a_cache(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        foreach (['/api/v1/me', '/api/v1/public/config', '/api/v1/public/cities'] as $path) {
            $cache = (string) $this->via('customer')->getJson($path)->assertOk()->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache, $path);
        }
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->assertFalse($this->getJson('/health/live')->headers->has('Strict-Transport-Security'));
        $this->assertSame('max-age=31536000', $this->getJson('https://localhost/health/live')->headers->get('Strict-Transport-Security'));
    }

    public function test_each_listed_origin_is_allowed_with_credentials(): void
    {
        foreach (self::ORIGINS as $surface => $origin) {
            $response = $this->preflight($origin);

            $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'), $surface);
            $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'), $surface);
            $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'), $surface);
        }
    }

    public function test_the_apps_can_read_the_request_id_and_the_retry_wait(): void
    {
        // A 429 tells the sign-in screens how long to wait; across origins the
        // browser hides that header unless it is exposed.
        $exposed = strtolower((string) $this->via('admin')->getJson('/api/v1/public/config')
            ->headers->get('Access-Control-Expose-Headers'));

        $this->assertStringContainsString('x-request-id', $exposed);
        $this->assertStringContainsString('retry-after', $exposed);
    }

    public function test_lookalike_and_unlisted_origins_get_no_cross_origin_access(): void
    {
        $refused = [
            'https://evil.example.test',
            'null',
            'http://localhost:3002.evil.example.test',
            'http://evil.localhost:3002',
            'https://localhost:3002',           // scheme differs
            'http://localhost:3003',            // port differs
            'http://127.0.0.1:3002',            // same machine, different origin
            'http://localhost:3002/',           // not an origin
            'HTTP://LOCALHOST:3002',            // origins are compared exactly
        ];

        foreach ($refused as $origin) {
            $preflight = $this->preflight($origin);
            $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'), "preflight from {$origin}");
            $this->assertFalse($preflight->headers->has('Access-Control-Allow-Credentials'), "preflight from {$origin}");

            $get = $this->withHeader('Origin', $origin)->getJson('/api/v1/public/config');
            $this->assertFalse($get->headers->has('Access-Control-Allow-Origin'), "GET from {$origin}");
        }
    }

    public function test_a_wildcard_is_never_returned(): void
    {
        foreach (['https://evil.example.test', self::ORIGINS['customer']] as $origin) {
            $this->assertNotSame('*', $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'));
            $this->assertNotSame('*', $this->withHeader('Origin', $origin)->getJson('/api/v1/public/config')->headers->get('Access-Control-Allow-Origin'));
        }
    }

    public function test_preflight_does_not_allow_unlisted_request_headers(): void
    {
        $allowed = strtolower((string) $this->preflight(self::ORIGINS['admin'], headers: 'x-evil,authorization')->headers->get('Access-Control-Allow-Headers'));

        $this->assertStringNotContainsString('x-evil', $allowed);
        // Browser apps use the session cookie; a bearer header from a browser origin is not invited.
        $this->assertStringNotContainsString('authorization', $allowed);
        $this->assertStringNotContainsString('*', $allowed);
    }

    /** @return array<string, array{string}> */
    public static function surfaces(): array
    {
        // Merchant is left out: no merchant account can sign in yet.
        return ['customer' => ['customer'], 'admin' => ['admin']];
    }

    #[DataProvider('surfaces')]
    public function test_session_cookie_is_http_only_lax_and_named_per_app(string $surface): void
    {
        $user = $this->user();
        if ($surface === 'admin') {
            $this->grantRole($user, 'support');
        }

        $cookie = $this->cookie($this->login($surface, 'person@example.com')->assertOk(), "fd_{$surface}_session");

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain(), 'A domain would share the cookie with sibling hosts.');
    }

    public function test_cookies_are_secure_when_https_is_configured(): void
    {
        config(['session.secure' => true]);
        $this->user();

        $response = $this->login('customer', 'person@example.com')->assertOk();

        $this->assertTrue($this->cookie($response, 'fd_customer_session')->isSecure());
        $xsrf = $this->cookie($response, 'XSRF-TOKEN');
        $this->assertTrue($xsrf->isSecure());
        // Readable by the app's script by design (it is echoed back as a header); it carries no session.
        $this->assertFalse($xsrf->isHttpOnly());
        $this->assertSame('lax', $xsrf->getSameSite());
    }

    public function test_sign_out_expires_the_session_cookie(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $cookie = $this->cookie($this->via('customer')->postJson('/api/v1/auth/logout')->assertNoContent(), 'fd_customer_session');

        $this->via('customer')->getJson('/api/v1/me')->assertStatus(401);
        $this->assertNotSame('', $cookie->getValue());
    }
}
