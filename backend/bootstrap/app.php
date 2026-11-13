<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveSurface;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Errors\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every response, error pages and CORS preflights included (E-02).
        $middleware->prepend(SecurityHeaders::class);

        // Order matters: the surface picks the session cookie name, so it
        // must run before Sanctum starts the session (ADR-011).
        $middleware->api(prepend: [
            AssignRequestId::class,
            ResolveSurface::class,
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiErrorRenderer::handles($request) ? ApiErrorRenderer::render($e, $request) : null;
        });
    })->create();
