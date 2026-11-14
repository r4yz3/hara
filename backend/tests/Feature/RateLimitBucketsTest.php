<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An inline throttle:N,M keys a signed-out request by domain and address only, so every such route shared one
 * bucket and csrf or sign-in calls used up the budget of unrelated routes (a valid emailed invitation link
 * answered 429). Each throttled route must have its own named limiter with its own bucket.
 *
 * Requests here are deliberately invalid (empty bodies), so they stop at validation and never reach the
 * per-account limiters inside the controllers; only the route-level bucket is measured.
 */
class RateLimitBucketsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Limiter name => [method, uri]. Limits come from AppServiceProvider::PUBLIC_LIMITS.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function requests(): array
    {
        return [
            'public-config' => ['GET', '/api/v1/public/config'],
            'public-cities' => ['GET', '/api/v1/public/cities'],
            'locations-serviceability' => ['POST', '/api/v1/locations/serviceability'],
            'auth-csrf' => ['GET', '/api/v1/auth/csrf'],
            'auth-register' => ['POST', '/api/v1/auth/register'],
            'auth-login' => ['POST', '/api/v1/auth/login'],
            'auth-mfa-verify' => ['POST', '/api/v1/auth/mfa/verify'],
            'auth-password-forgot' => ['POST', '/api/v1/auth/password/forgot'],
            'auth-password-reset' => ['POST', '/api/v1/auth/password/reset'],
            'invitation-inspect' => ['POST', '/api/v1/auth/invitations/inspect'],
            'invitation-accept' => ['POST', '/api/v1/auth/invitations/accept'],
            'auth-mobile-token' => ['POST', '/api/v1/auth/mobile/token'],
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $request
     * @return TestResponse<JsonResponse>
     */
    private function send(array $request): TestResponse
    {
        return $this->withHeader('Origin', 'http://localhost:3002')->json($request[0], $request[1], []);
    }

    /** @return list<string> */
    private function throttleMiddleware(): array
    {
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $found[] = substr($middleware, strlen('throttle:'));
                }
            }
        }

        return $found;
    }

    public function test_every_public_limiter_is_listed_and_wired_to_exactly_one_route(): void
    {
        $this->assertSame(array_keys(AppServiceProvider::PUBLIC_LIMITS), array_keys($this->requests()));

        $used = $this->throttleMiddleware();
        foreach (array_keys(AppServiceProvider::PUBLIC_LIMITS) as $name) {
            $this->assertCount(1, array_keys($used, $name, true), "{$name} must be used by exactly one route.");
        }
    }

    public function test_each_route_has_its_own_bucket_and_answers_429_at_its_own_limit(): void
    {
        foreach ($this->requests() as $name => $request) {
            $limit = AppServiceProvider::PUBLIC_LIMITS[$name];

            // Every route before this one has already been exhausted from this address. This one must
            // still have its full budget.
            for ($i = 0; $i < $limit; $i++) {
                $this->assertNotSame(429, $this->send($request)->status(), "{$name}: request ".($i + 1)." of {$limit} was refused; a bucket is shared.");
            }

            $refused = $this->send($request)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
            $refused->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
            $this->assertNotNull($refused->headers->get('Retry-After'), "{$name}: 429 without Retry-After.");
        }
    }

    public function test_an_exhausted_route_leaves_the_others_open(): void
    {
        $requests = $this->requests();
        for ($i = 0; $i < AppServiceProvider::PUBLIC_LIMITS['auth-register']; $i++) {
            $this->send($requests['auth-register']);
        }
        $this->send($requests['auth-register'])->assertStatus(429);

        foreach ($requests as $name => $request) {
            if ($name !== 'auth-register') {
                $this->assertNotSame(429, $this->send($request)->status(), "{$name} was refused after auth-register was exhausted.");
            }
        }
    }

    public function test_no_route_uses_an_inline_numeric_throttle(): void
    {
        foreach ($this->throttleMiddleware() as $argument) {
            $this->assertSame(0, preg_match('/^\d/', $argument), "throttle:{$argument} is inline and shares one bucket per address across routes. Use a named limiter in AppServiceProvider.");
        }
    }
}
