<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminSurface;
use App\Http\Middleware\EnsureInternalRequest;
use App\Http\Middleware\EnsureRiderToken;
use App\Http\Middleware\EnsureSurfaceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Backlog B-10 / spec 10.4 "missing auth route assertions": every registered
 * route is either on the short public list below or sits behind the session
 * guard, and every admin route also sits behind the admin-surface guard.
 *
 * Adding a public route means adding it here on purpose. Forgetting the
 * guard on anything else fails this test.
 *
 * What this does not prove: that each action checks the right permission and
 * scope. That is covered per endpoint and by CrossTenantIsolationTest.
 */
class RouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Reachable without signing in, by design. */
    private const PUBLIC_ROUTES = [
        'health.live',
        'health.ready',
        'public.config',
        'public.cities',
        'locations.serviceability',
        'auth.csrf',
        'auth.register',
        'auth.login',
        'auth.password.forgot',
        'auth.password.reset',
        'auth.mobile.token',
    ];

    /** @return list<RoutingRoute> */
    private function routes(): array
    {
        return Route::getRoutes()->getRoutes();
    }

    public function test_every_route_has_a_name(): void
    {
        foreach ($this->routes() as $route) {
            $this->assertNotNull($route->getName(), "Route {$route->uri()} has no name.");
        }
    }

    public function test_the_public_list_names_only_real_routes(): void
    {
        $names = array_map(fn (RoutingRoute $route): ?string => $route->getName(), $this->routes());

        $this->assertSame([], array_values(array_diff(self::PUBLIC_ROUTES, $names)));
    }

    public function test_every_route_is_public_on_purpose_or_behind_the_session_guard(): void
    {
        foreach ($this->routes() as $route) {
            // Exactly one guard: a browser session or a rider token, never both.
            // Parenthesised: `xor` binds more loosely than `=`.
            $guarded = (in_array(EnsureSurfaceSession::class, $route->gatherMiddleware(), true)
                xor in_array(EnsureRiderToken::class, $route->gatherMiddleware(), true));
            $public = in_array($route->getName(), self::PUBLIC_ROUTES, true);

            $this->assertTrue(
                ($guarded xor $public),
                "{$route->getName()} ({$route->uri()}) must be either on the public list or behind exactly one guard (session or rider token).",
            );
        }
    }

    public function test_every_admin_route_is_behind_the_admin_surface_guard_and_nothing_else_is(): void
    {
        foreach ($this->routes() as $route) {
            $isAdminPath = str_starts_with($route->uri(), 'api/v1/admin/');
            $hasGuard = in_array(EnsureAdminSurface::class, $route->gatherMiddleware(), true);

            $this->assertSame($isAdminPath, $hasGuard, "{$route->uri()}: admin path and admin guard must go together.");
        }
    }

    public function test_the_guards_run_in_the_right_order_on_admin_routes(): void
    {
        foreach ($this->routes() as $route) {
            $middleware = $route->gatherMiddleware();
            $session = array_search(EnsureSurfaceSession::class, $middleware, true);
            $admin = array_search(EnsureAdminSurface::class, $middleware, true);

            if ($admin !== false) {
                $this->assertNotFalse($session);
                $this->assertLessThan($admin, $session, "{$route->uri()}: the session guard must run before the admin guard.");
            }
        }
    }

    public function test_public_api_routes_are_rate_limited(): void
    {
        foreach ($this->routes() as $route) {
            if (! in_array($route->getName(), self::PUBLIC_ROUTES, true) || ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $throttled = array_filter($route->gatherMiddleware(), fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotEmpty($throttled, "{$route->getName()} is public and has no rate limit.");
        }
    }

    public function test_readiness_stays_internal(): void
    {
        $ready = Route::getRoutes()->getByName('health.ready');

        $this->assertNotNull($ready);
        $this->assertContains(EnsureInternalRequest::class, $ready->gatherMiddleware());
    }

    public function test_every_guarded_route_really_refuses_an_anonymous_request(): void
    {
        $placeholder = '01jexample0000000000000000';

        foreach ($this->routes() as $route) {
            if (in_array($route->getName(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', $placeholder, $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // With the admin app's origin, so only the missing session can be the reason.
                $response = $this->withHeader('Origin', (string) config('surfaces.origins.admin'))->json($method, $uri, []);

                $this->assertSame(401, $response->status(), "{$method} {$uri} answered {$response->status()} to an anonymous caller.");
                $this->assertSame('AUTH_REQUIRED', $response->json('error.code'));
            }
        }
    }
}
