<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the rider app's routes (spec 6.2). Every request must carry a
 * bearer token that is unexpired, has the rider ability, belongs to a device
 * that has not been revoked, and to an active user who is an approved rider.
 * All of that is read from storage on each request, so suspending a rider or
 * revoking a device takes effect immediately.
 *
 * A browser session never satisfies this guard, and a rider token never
 * satisfies the browser guards.
 */
class EnsureRiderToken
{
    public const ABILITY = 'rider:profile';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->can(self::ABILITY)) {
            throw new AuthenticationException;
        }

        $rider = DB::table('riders')
            ->join('users', 'users.id', '=', 'riders.user_id')
            ->where('riders.user_id', $user->id)
            ->where('riders.status', 'approved')
            ->where('users.status', 'active')
            ->first(['riders.id', 'riders.status', 'riders.approved_at']);

        $deviceLive = DB::table('devices')
            ->where('id', $token->name)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($rider === null || ! $deviceLive) {
            throw new AuthenticationException;
        }

        $request->attributes->set('rider', $rider);
        $request->attributes->set('rider_token', $token);

        return $next($request);
    }
}
