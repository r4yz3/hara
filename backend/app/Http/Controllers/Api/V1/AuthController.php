<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\DeleteCustomerAccount;
use App\Application\Commands\PasswordRecovery;
use App\Application\Commands\ProfileService;
use App\Application\Commands\RegisterCustomer;
use App\Application\Commands\StaffMfa;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ResolveSurface;
use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use App\Support\Auth\SurfaceAccess;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\ProductionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * A syntactically valid hash of a random value, checked when the account
     * does not exist so that case costs about the same time as a wrong password.
     */
    private static ?string $decoyHash = null;

    public function csrf(Request $request): JsonResponse
    {
        $this->surfaceOrFail($request);

        return ApiResponse::data($request, ['csrf_token' => $request->session()->token()]);
    }

    public function register(RegisterRequest $request, RegisterCustomer $register): JsonResponse
    {
        $surface = $this->surfaceOrFail($request);

        if ($surface !== 'customer' || ! config('features.public_registration')) {
            throw ApiError::notFound();
        }

        $register->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        // Same answer whether or not the address was already registered.
        return ApiResponse::data($request, ['status' => 'registration_received'], 202);
    }

    public function login(LoginRequest $request, SurfaceAccess $surfaces, StaffMfa $mfa): JsonResponse
    {
        $surface = $this->surfaceOrFail($request);

        $email = $request->email();
        $throttleKey = 'login:'.hash('sha256', $email.'|'.$request->ip());
        $maxAttempts = (int) config('surfaces.login.max_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()->where('email', $email)->first();
        $passwordOk = Hash::check(
            $request->string('password')->toString(),
            $user !== null ? $user->password : (self::$decoyHash ??= Hash::make(bin2hex(random_bytes(16)))),
        );

        if ($user === null || ! $passwordOk || ! $surfaces->allows($user->id, $surface)) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            Log::info('auth.login.failed', ['surface' => $surface]);

            throw ApiError::invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        if ($surface === 'admin' && $mfa->isConfirmed($user->id)) {
            // Password only: nobody is signed in until the second step.
            $request->session()->regenerate();
            $request->session()->put('mfa_pending', [
                'user_id' => $user->id,
                'expires_at' => now()->getTimestamp() + (int) config('surfaces.mfa.pending_seconds'),
            ]);

            return ApiResponse::data($request, ['mfa_required' => true]);
        }

        // Deployed staff without a second factor may only enrol one.
        $this->startSession($request, $user, $surface, [
            'mfa_enrolment_only' => $surface === 'admin' && ! app()->environment(ProductionGuard::DEVELOPMENT_ENVIRONMENTS),
        ]);

        return ApiResponse::data($request, (new CurrentUserResource($user, $surface))->toArray());
    }

    public function verifyMfa(Request $request, SurfaceAccess $surfaces, StaffMfa $mfa): JsonResponse
    {
        $surface = $this->surfaceOrFail($request);
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:64', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64', 'required_without:code'],
        ]);

        $pending = $request->session()->get('mfa_pending');
        $userId = is_array($pending) && is_string($pending['user_id'] ?? null) && is_int($pending['expires_at'] ?? null)
            && $pending['expires_at'] > now()->getTimestamp() ? $pending['user_id'] : null;

        // No marker, an expired one, another surface: the same answer as a wrong code.
        if ($surface !== 'admin' || $userId === null) {
            $request->session()->forget('mfa_pending');

            throw ApiError::invalidCredentials();
        }

        $throttleKey = 'mfa:'.hash('sha256', $userId.'|'.$request->ip());
        $maxAttempts = (int) config('surfaces.login.max_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()->find($userId);
        $method = $user !== null && $surfaces->allows($user->id, $surface) && $mfa->isConfirmed($user->id)
            ? $mfa->challenge($user->id, $input['code'] ?? null, $input['recovery_code'] ?? null)
            : null;

        if ($user === null || $method === null) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            Log::info('auth.mfa.failed', ['surface' => $surface]);

            if ($user !== null) {
                $mfa->recordFailed($user->id, 'sign_in', $request->ip(), AssignRequestId::for($request));
            }

            throw ApiError::invalidCredentials();
        }

        RateLimiter::clear($throttleKey);
        $mfa->recordVerified($user->id, $method, $request->ip(), AssignRequestId::for($request));

        $this->startSession($request, $user, $surface, ['mfa_verified' => true, 'mfa_factor' => $mfa->confirmedFactorId($user->id)]);

        return ApiResponse::data($request, (new CurrentUserResource($user, $surface))->toArray());
    }

    public function forgotPassword(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $surface = $this->surfaceOrFail($request);
        $input = $request->validate(['email' => ['required', 'string', 'max:255']]);

        $recovery->request($input['email'], (string) config("surfaces.origins.{$surface}"));

        // Same answer whether or not the address belongs to an account.
        return ApiResponse::data($request, ['status' => 'reset_requested'], 202);
    }

    public function resetPassword(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $this->surfaceOrFail($request);
        $input = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', Password::min(10), 'max:1024'],
        ]);

        if (! $recovery->reset($input['token'], $input['password'])) {
            // One message for unknown, used and expired links.
            throw ValidationException::withMessages(['token' => ['This reset link is not valid or has expired. Request a new one.']]);
        }

        return ApiResponse::data($request, ['status' => 'password_reset']);
    }

    public function logout(Request $request): Response
    {
        Log::info('auth.logout', ['user_id' => Auth::guard('web')->id(), 'surface' => ResolveSurface::of($request)]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return ApiResponse::data($request, (new CurrentUserResource($user, (string) ResolveSurface::of($request)))->toArray());
    }

    public function updateMe(UpdateProfileRequest $request, ProfileService $profile): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        $updated = $profile->rename($user, $request->validated('name'), $request->ip(), AssignRequestId::for($request));

        return ApiResponse::data($request, (new CurrentUserResource($updated, (string) ResolveSurface::of($request)))->toArray());
    }

    /**
     * A customer closes their own account (ADR-017). The password is
     * re-checked here, and wrong guesses count against the account (not the
     * network), like sign-in, so a hijacked session cannot guess its way in.
     */
    public function deleteMe(DeleteAccountRequest $request, DeleteCustomerAccount $deletion): Response
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        $throttleKey = 'account-delete:'.hash('sha256', (string) $user->id);
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('surfaces.login.max_attempts'))) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        if (! $deletion->handle($user, $request->string('password')->toString(), AssignRequestId::for($request))) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            Log::info('account.delete.refused', ['surface' => ResolveSurface::of($request)]);

            throw ValidationException::withMessages(['password' => ['The password is not correct.']]);
        }

        RateLimiter::clear($throttleKey);

        // This browser session ends now; every other one ends on its next
        // request, because the user row is gone or its password hash changed.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * @param  array<string, bool|string|null>  $flags  session facts about how this sign-in was earned
     */
    private function startSession(Request $request, User $user, string $surface, array $flags): void
    {
        $guard = Auth::guard('web');
        $guard->login($user);
        $request->session()->regenerate();
        $request->session()->forget('mfa_pending');
        $request->session()->put([
            'surface' => $surface,
            'password_hash_web' => $guard->hashPasswordForCookie($user->getAuthPassword()),
        ] + $flags);

        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        Log::info('auth.login', ['user_id' => $user->id, 'surface' => $surface]);
    }

    private function surfaceOrFail(Request $request): string
    {
        $surface = ResolveSurface::of($request);

        if ($surface === null || ! $request->hasSession()) {
            throw ApiError::forbidden();
        }

        return $surface;
    }
}
