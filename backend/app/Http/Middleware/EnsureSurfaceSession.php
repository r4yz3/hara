<?php

namespace App\Http\Middleware;

use App\Support\Auth\SurfaceAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every signed-in route: there must be a user, the session must have
 * been created on this same surface, and the user must still be entitled to
 * that surface. Otherwise the session is ended and the answer is 401.
 */
class EnsureSurfaceSession
{
    public function __construct(private readonly SurfaceAccess $surfaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $surface = ResolveSurface::of($request);
        $user = Auth::guard('web')->user();

        if ($surface === null || $user === null || ! $request->hasSession()) {
            throw new AuthenticationException;
        }

        if ($request->session()->get('surface') !== $surface
            || ! $this->surfaces->allows((string) $user->getAuthIdentifier(), $surface)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
