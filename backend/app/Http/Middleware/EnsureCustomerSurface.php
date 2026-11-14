<?php

namespace App\Http\Middleware;

use App\Support\Errors\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-only routes exist only for the customer app. From the merchant or
 * admin app they answer 404, as if they were not there. Runs after
 * EnsureSurfaceSession.
 */
class EnsureCustomerSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ResolveSurface::of($request) !== 'customer') {
            throw ApiError::notFound();
        }

        return $next($request);
    }
}
