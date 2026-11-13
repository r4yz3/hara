<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureRiderToken;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in for the rider app (spec 7.3, ADR-011): a bearer token limited to
 * the rider ability, valid for one hour, tied to one registered device.
 */
class RiderAuthController extends Controller
{
    private const MINUTES_VALID = 60;

    /**
     * Absolute limit on one sign-in, counted from when the device signed in
     * (devices.created_at). Refreshing cannot go past it, so a stolen token
     * dies within this time even if it is refreshed every hour. Agreed with
     * the product owner on 2026-11-14.
     */
    private const DAYS_PER_SIGN_IN = 30;

    private static ?string $decoyHash = null;

    public function issue(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'min:1', 'max:120'],
            // Android first (master plan); iOS arrives in a later phase.
            'platform' => ['required', 'string', Rule::in(['android'])],
        ]);

        $email = strtolower(trim($input['email']));
        $throttleKey = 'mobile-login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('surfaces.login.max_attempts'))) {
            throw ApiError::rateLimited(RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()->where('email', $email)->first();
        $passwordOk = Hash::check(
            $input['password'],
            $user !== null ? $user->password : (self::$decoyHash ??= Hash::make(bin2hex(random_bytes(16)))),
        );

        $approved = $user !== null && $user->status === 'active'
            && DB::table('riders')->where('user_id', $user->id)->where('status', 'approved')->exists();

        // One answer for a wrong password, an unknown address, a customer, a
        // rider who is not approved, and a suspended account.
        if ($user === null || ! $passwordOk || ! $approved) {
            RateLimiter::hit($throttleKey, (int) config('surfaces.login.decay_seconds'));
            Log::info('auth.mobile.failed');

            throw ApiError::invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        $data = DB::transaction(function () use ($user, $input): array {
            $deviceId = strtolower((string) Str::ulid());
            DB::table('devices')->insert([
                'id' => $deviceId,
                'user_id' => $user->id,
                'platform' => $input['platform'],
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->mint($user, $deviceId);
        });

        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        Log::info('auth.mobile.login', ['user_id' => $user->id]);

        return ApiResponse::data($request, $data, 201);
    }

    /**
     * Swaps a still-valid token for a new one on the same device. The old
     * token is deleted in the same transaction, so it cannot be used again.
     * An expired token cannot be refreshed; the rider signs in again.
     */
    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('sanctum')->user();
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('rider_token');

        $data = DB::transaction(function () use ($user, $token): ?array {
            $deviceId = (string) $token->name;
            $token->delete();

            $signedInAt = DB::table('devices')->where('id', $deviceId)->lockForUpdate()->value('created_at');
            $endsAt = $signedInAt === null ? null : Carbon::parse((string) $signedInAt)->addDays(self::DAYS_PER_SIGN_IN);
            if ($endsAt === null || ! now()->lessThan($endsAt)) {
                // Sign-in is over: end the device too, so nothing can continue it.
                DB::table('devices')->where('id', $deviceId)->update(['revoked_at' => now(), 'updated_at' => now()]);

                return null;
            }

            DB::table('devices')->where('id', $deviceId)->update(['last_seen_at' => now(), 'updated_at' => now()]);

            return $this->mint($user, $deviceId, $endsAt);
        });

        if ($data === null) {
            throw new ApiError(401, 'AUTH_REQUIRED', 'Your sign-in has ended. Sign in again.');
        }

        return ApiResponse::data($request, $data, 201);
    }

    public function revoke(Request $request): Response
    {
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('rider_token');

        DB::transaction(function () use ($token): void {
            DB::table('devices')->where('id', $token->name)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $token->delete();
        });

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('sanctum')->user();
        /** @var object{id: string, status: string, approved_at: string|null} $rider */
        $rider = $request->attributes->get('rider');

        return ApiResponse::data($request, [
            'id' => $rider->id,
            'user_id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'status' => $rider->status,
            'approved_at' => $rider->approved_at === null ? null : now()->parse($rider->approved_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @return array{token: string, token_type: string, abilities: list<string>, expires_at: string}
     */
    private function mint(User $user, string $deviceId, ?Carbon $endsAt = null): array
    {
        $expires = now()->addMinutes(self::MINUTES_VALID);
        if ($endsAt !== null && $endsAt->lessThan($expires)) {
            $expires = $endsAt->copy();
        }
        $token = $user->createToken($deviceId, [EnsureRiderToken::ABILITY], $expires);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => [EnsureRiderToken::ABILITY],
            'expires_at' => $expires->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
