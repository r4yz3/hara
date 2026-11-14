<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // An inline throttle:N,M gives every signed-out route the same bucket per address (the key is domain and
        // IP only), so csrf and sign-in calls used up the invitation budget and a valid emailed link answered 429.
        // Each invitation route gets its own bucket, same limit as before.
        RateLimiter::for('invitation-inspect', fn (Request $request): Limit => Limit::perMinute(10)->by('invitation-inspect|'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)->by('invitation-accept|'.$request->ip()));

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
