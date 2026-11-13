<?php

namespace App\Http\Middleware;

use App\Application\Commands\StaffMfa;
use App\Support\Auth\SurfaceAccess;
use App\Support\Errors\ApiError;
use App\Support\ProductionGuard;
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
    /** The only routes a deployed staff session without a second factor may use. */
    private const ENROLMENT_ROUTES = ['me.mfa.totp.start', 'me.mfa.totp.confirm', 'auth.logout'];

    public function __construct(
        private readonly SurfaceAccess $surfaces,
        private readonly StaffMfa $mfa,
    ) {}

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

        if ($surface === 'admin') {
            $this->requireSecondStep($request, (string) $user->getAuthIdentifier());
        }

        return $next($request);
    }

    /**
     * ADR-011. A staff member with a confirmed second factor needs a session
     * that passed it. Without one, local and testing keep password-only
     * sessions; anywhere else the session may only enrol a factor. A session
     * that passed a second step for a factor that is gone or replaced ends.
     */
    private function requireSecondStep(Request $request, string $userId): void
    {
        $session = $request->session();

        $factorId = $this->mfa->confirmedFactorId($userId);

        if ($factorId !== null) {
            // The session must have passed the second step for this very
            // factor: a reset followed by a new enrolment does not revive it.
            if ($session->get('mfa_verified') !== true || $session->get('mfa_factor') !== $factorId) {
                $this->endSession($request);
            }

            return;
        }

        // No factor (never set up, or reset by an operator): a session that
        // claims a second step is stale in every environment.
        if ($session->get('mfa_verified') === true) {
            $this->endSession($request);
        }

        if (app()->environment(ProductionGuard::DEVELOPMENT_ENVIRONMENTS)) {
            return;
        }

        if ($session->get('mfa_enrolment_only') !== true) {
            $this->endSession($request);
        }

        if (! in_array($request->route()?->getName(), self::ENROLMENT_ROUTES, true)) {
            throw ApiError::forbidden('Set up two-step sign-in before using the admin console.');
        }
    }

    private function endSession(Request $request): never
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();

        throw new AuthenticationException;
    }
}
