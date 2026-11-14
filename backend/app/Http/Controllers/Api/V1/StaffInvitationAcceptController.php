<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\StaffInvitations;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSurface;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The invitee side of a staff invitation (backlog U-05). Public routes: the
 * emailed token is the credential. It travels in the request body, never in
 * a URL, and every failure answers the same way.
 */
class StaffInvitationAcceptController extends Controller
{
    /** Failed acceptances per token per window. */
    private const MAX_FAILURES = 5;

    private const WINDOW_SECONDS = 900;

    public function inspect(Request $request, StaffInvitations $invitations): JsonResponse
    {
        $this->adminSurfaceOrFail($request);
        $input = Validator::make($request->post(), ['token' => ['required', 'string', 'max:200']])->validate();

        $invite = $invitations->inspect($input['token']);
        if ($invite === null) {
            Log::info('staff_invitation.inspect.failed');

            // One answer for unknown, used, revoked and expired.
            throw ApiError::notFound();
        }

        return ApiResponse::data($request, $invite);
    }

    public function accept(Request $request, StaffInvitations $invitations): JsonResponse
    {
        $this->adminSurfaceOrFail($request);
        $input = Validator::make($request->post(), [
            'token' => ['required', 'string', 'max:200'],
            // The same rule for everyone, so a refusal says nothing about the address.
            'password' => ['required', 'string', Password::min(10), 'max:1024'],
        ])->validate();

        $key = 'staff-invite-accept:'.hash('sha256', $input['token']);
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw ApiError::rateLimited(RateLimiter::availableIn($key));
        }

        if (! $invitations->accept($input['token'], $input['password'], $request->ip())) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            Log::info('staff_invitation.accept.failed');

            // Unknown, used, revoked, expired, wrong password for an existing
            // account, or an inviter who no longer has the right: one answer.
            throw ApiError::notFound();
        }

        RateLimiter::clear($key);

        // Nobody is signed in: they sign in as usual, and the admin console
        // then requires two-step enrolment before anything else (ADR-011).
        return ApiResponse::data($request, ['status' => 'invitation_accepted']);
    }

    private function adminSurfaceOrFail(Request $request): void
    {
        if (ResolveSurface::of($request) !== 'admin' || ! $request->hasSession()) {
            throw ApiError::forbidden();
        }
    }
}
