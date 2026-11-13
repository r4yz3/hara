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
 * been created on this surface with the current password, and the user must
 * still be entitled to that surface. Otherwise the session ends with 401.
 */
class EnsureSurfaceSession
{
    public function __construct(private readonly SurfaceAccess $surfaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $surface = ResolveSurface::of($request);
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($surface === null || $user === null || ! $request->hasSession()) {
            throw new AuthenticationException;
        }

        $passwordHash = $request->session()->get('password_hash_web');
        if ($request->session()->get('surface') !== $surface
            || ! is_string($passwordHash)
            || ! hash_equals($guard->hashPasswordForCookie($user->getAuthPassword()), $passwordHash)
            || ! $this->surfaces->allows((string) $user->getAuthIdentifier(), $surface)) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
