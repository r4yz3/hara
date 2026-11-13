<?php

namespace App\Providers;

use App\Support\ProductionGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
