<?php

namespace App\Http\Middleware;

use App\Support\Errors\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin routes exist only for the admin app. From anywhere else they answer
 * 404, as if they were not there. Runs after EnsureSurfaceSession.
 */
class EnsureAdminSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ResolveSurface::of($request) !== 'admin') {
            throw ApiError::notFound();
        }

        return $next($request);
    }
}
