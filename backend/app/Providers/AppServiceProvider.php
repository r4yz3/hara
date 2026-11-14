<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Limiter name => requests per minute per address, for the signed-out routes. */
    public const PUBLIC_LIMITS = [
        'public-config' => 120,
        'public-cities' => 120,
        'locations-serviceability' => 60,
        'auth-csrf' => 60,
        'auth-register' => 10,
        'auth-login' => 30,
        'auth-mfa-verify' => 30,
        'auth-password-forgot' => 10,
        'auth-password-reset' => 10,
        'invitation-inspect' => 10,
        'invitation-accept' => 10,
        'auth-mobile-token' => 30,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // An inline throttle:N,M gives every signed-out route the same bucket per address (the key is domain and
        // IP only), so csrf and sign-in calls used up the invitation budget and a valid emailed link answered 429.
        // Every throttled route therefore has its own named limiter (same limits as the old inline ones), keyed by
        // its own name and the address. RateLimitBucketsTest fails if a route goes back to throttle:N,M.
        foreach (self::PUBLIC_LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }

        // Signed in: one bucket per account, as the inline throttle did for authenticated requests.
        RateLimiter::for('admin-invitations-store', fn (Request $request): Limit => Limit::perMinute(20)->by('admin-invitations-store|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Deny by default: anything that is not local development or the
        // test suite must pass the guard, whatever the environment is called
        // (production, staging, a typo). Web requests, queue workers and
        // every console command are covered, except the short list of build
        // and repair commands in ProductionGuard::REPAIR_COMMANDS.
        /** @var list<string> $argv */
        $argv = array_values(array_map('strval', (array) ($_SERVER['argv'] ?? [])));

        if (ProductionGuard::applies($this->app->environment(), $this->app->runningInConsole(), $argv)) {
            ProductionGuard::assertSafe(config());
        }
    }
}
