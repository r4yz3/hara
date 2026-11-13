<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\StaffMfa;
use App\Application\Queries\AccessChecker;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveSurface;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in for staff (ADR-011, backlog B-09): enrolment, new recovery
 * codes, and the operator reset. Behind EnsureSurfaceSession; admin surface
 * only. A person cannot switch their own factor off; only an operator reset
 * removes one.
 */
class MfaController extends Controller
{
    public function start(Request $request, StaffMfa $mfa): JsonResponse
    {
        $enrolment = $mfa->begin($this->staffUser($request));

        if ($enrolment === null) {
            throw new ApiError(409, 'CONFLICT', 'Two-step sign-in is already set up.');
        }

        // The only time the secret leaves the server.
        return ApiResponse::data($request, ['secret' => $enrolment['secret'], 'otpauth_uri' => $enrolment['otpauth_uri']], 201);
    }

    public function confirm(Request $request, StaffMfa $mfa): JsonResponse
    {
        $user = $this->staffUser($request);
        $input = $request->validate(['code' => ['required', 'string', 'max:64']]);

        if ($mfa->isConfirmed($user->id)) {
            throw new ApiError(409, 'CONFLICT', 'Two-step sign-in is already set up.');
        }

        $throttleKey = 'mfa-confirm:'.hash('sha256', $user->id.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('surfaces.login.max_attempts'))) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        $codes = $mfa->confirm($user, $input['code'], $request->ip(), AssignRequestId::for($request));

        if ($codes === null) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            $mfa->recordFailed($user->id, 'enrolment', $request->ip(), AssignRequestId::for($request));

            throw ValidationException::withMessages(['code' => ['That code is not right, or setup was not started.']]);
        }

        RateLimiter::clear($throttleKey);

        if ($request->session()->get('mfa_enrolment_only') === true) {
            // Deployed environments: sign in again with the second step.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            // The code just proved possession, so this local session carries on.
            $request->session()->put(['mfa_verified' => true, 'mfa_factor' => $mfa->confirmedFactorId($user->id)]);
        }

        return ApiResponse::data($request, ['recovery_codes' => $codes]);
    }

    /** New recovery codes in place of all the old ones; needs a current code from the app. */
    public function regenerateRecoveryCodes(Request $request, StaffMfa $mfa): JsonResponse
    {
        $user = $this->staffUser($request);
        $input = $request->validate(['code' => ['required', 'string', 'max:64']]);

        if (! $mfa->isConfirmed($user->id)) {
            throw new ApiError(409, 'CONFLICT', 'Two-step sign-in is not set up.');
        }

        $throttleKey = 'mfa-regenerate:'.hash('sha256', $user->id.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('surfaces.login.max_attempts'))) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        $codes = $mfa->regenerateRecoveryCodes($user, $input['code'], $request->ip(), AssignRequestId::for($request));

        if ($codes === null) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            $mfa->recordFailed($user->id, 'recovery_codes', $request->ip(), AssignRequestId::for($request));

            throw ValidationException::withMessages(['code' => ['That code is not right.']]);
        }

        RateLimiter::clear($throttleKey);

        return ApiResponse::data($request, ['recovery_codes' => $codes]);
    }

    /**
     * Operator reset of another staff member's second factor (lost device and
     * lost codes). Deployment-wide permission, checked from storage; never for
     * oneself; a platform owner can be reset only by a platform owner.
     */
    public function reset(Request $request, string $user, StaffMfa $mfa, AccessChecker $access): Response
    {
        $actor = $this->staffUser($request);

        if (! $access->allows($actor, 'users.mfa.reset', Scope::global())) {
            throw ApiError::forbidden();
        }

        $input = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:255']]);
        $reason = trim($input['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['The reason is required.']]);
        }

        $targetId = strtolower($user);
        if (preg_match('/\A[0-9a-z]{26}\z/', $targetId) !== 1 || ! $this->isStaff($targetId)) {
            throw ApiError::notFound();
        }

        if ($targetId === $actor->id) {
            throw ApiError::forbidden('Use one of your recovery codes to regain access to your own account.');
        }

        if ($this->isPlatformOwner($targetId) && ! in_array('platform_owner', $access->rolesCovering($actor, Scope::global()), true)) {
            throw ApiError::forbidden();
        }

        $mfa->reset($actor, $targetId, $reason, $request->ip(), AssignRequestId::for($request));

        return response()->noContent();
    }

    private function isStaff(string $userId): bool
    {
        return DB::table('users')->where('id', $userId)->exists()
            && $this->holdsLiveRole($userId, (array) config('surfaces.staff_roles'));
    }

    private function isPlatformOwner(string $userId): bool
    {
        return $this->holdsLiveRole($userId, ['platform_owner']);
    }

    /** @param  list<string>  $slugs */
    private function holdsLiveRole(string $userId, array $slugs): bool
    {
        return DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->whereNull('user_role_scopes.revoked_at')
            ->whereIn('roles.slug', $slugs)
            ->exists();
    }

    private function staffUser(Request $request): User
    {
        if (ResolveSurface::of($request) !== 'admin') {
            throw ApiError::notFound();
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
