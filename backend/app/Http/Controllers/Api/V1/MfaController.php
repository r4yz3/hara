<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\StaffMfa;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveSurface;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in enrolment for staff (ADR-011, backlog B-09). Behind
 * EnsureSurfaceSession; admin surface only. Disabling a factor is not offered.
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
            $request->session()->put('mfa_verified', true);
        }

        return ApiResponse::data($request, ['recovery_codes' => $codes]);
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
