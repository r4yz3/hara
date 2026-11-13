<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\PasswordRecovery;
use App\Application\Commands\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSurface;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use App\Support\Auth\SurfaceAccess;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
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

    public function login(LoginRequest $request, SurfaceAccess $surfaces): JsonResponse
    {
        $surface = $this->surfaceOrFail($request);

        // ADR-011: until two-step verification is built and reviewed, the
        // admin surface does not open in production at all.
        if ($surface === 'admin' && app()->environment('production')) {
            throw ApiError::forbidden('Staff sign-in is unavailable until two-step verification is enabled.');
        }

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

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('surface', $surface);

        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        Log::info('auth.login', ['user_id' => $user->id, 'surface' => $surface]);

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

    private function surfaceOrFail(Request $request): string
    {
        $surface = ResolveSurface::of($request);

        if ($surface === null || ! $request->hasSession()) {
            throw ApiError::forbidden();
        }

        return $surface;
    }
}
