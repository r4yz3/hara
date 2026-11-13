<?php

namespace App\Application\Commands;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Password recovery (spec 7.3, backlog B-03).
 *
 * - Requesting never reveals whether an account exists: the caller's answer
 *   is the same either way, and this class returns nothing to tell them apart.
 * - The token is 256 random bits. Only its SHA-256 is stored, so a leaked
 *   table cannot be used to reset anyone's password. A fast hash is right
 *   here because the token is not guessable, unlike a password.
 * - One live link per account, valid for a limited time, usable once.
 */
class PasswordRecovery
{
    public const MINUTES_VALID = 60;

    private const MAILS_PER_WINDOW = 3;

    private const WINDOW_SECONDS = 900;

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(string $email, string $appOrigin): void
    {
        $email = strtolower(trim($email));

        // Counted for every address, existing or not, so the limit itself
        // says nothing about which accounts exist.
        $key = 'password-forgot:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, self::MAILS_PER_WINDOW)) {
            return;
        }
        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $user = User::query()->where('email', $email)->where('status', 'active')->first();
        if ($user === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => hash('sha256', $token), 'created_at' => now()],
        );

        // In the fragment: browsers do not send it to servers, so the token
        // stays out of access logs and referrer headers.
        $url = rtrim($appOrigin, '/').'/reset-password#token='.$token;

        Mail::to($user->email)->send(new PasswordResetLink($url, $token, self::MINUTES_VALID));
    }

    /**
     * @return bool false when the token is unknown, used, expired or belongs
     *              to an account that may not sign in; the caller must not say which
     */
    public function reset(string $token, string $newPassword): bool
    {
        return DB::transaction(function () use ($token, $newPassword): bool {
            $row = DB::table('password_reset_tokens')
                ->where('token', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($row === null || now()->subMinutes(self::MINUTES_VALID)->greaterThan($row->created_at)) {
                return false;
            }

            $user = User::query()->where('email', $row->email)->where('status', 'active')->lockForUpdate()->first();
            if ($user === null) {
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($newPassword),
                // Invalidates "remember me" cookies issued before the reset.
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('password_reset_tokens')->where('email', $row->email)->delete();

            $this->audit->record($user->id, 'auth.password_reset', 'user', $user->id);

            return true;
        });
    }
}
