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
        // In production the application refuses to serve requests with unsafe
        // settings (debug on, insecure cookies, wildcard CORS, placeholder
        // secrets, ...). Console commands are exempt so an operator can still
        // run key:generate or migrate while fixing the environment.
        if ($this->app->environment('production') && ! $this->app->runningInConsole()) {
            ProductionGuard::assertSafe(ProductionGuard::read(config()));
        }
    }
}
