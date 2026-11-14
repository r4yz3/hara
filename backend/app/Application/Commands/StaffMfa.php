<?php

namespace App\Application\Commands;

use App\Domain\Identity\Totp;
use App\Mail\RecoveryCodeUsed;
use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * TOTP second factor for staff (backlog B-09, ADR-011): enrolment, the
 * sign-in challenge and one-time recovery codes. Secrets, codes and recovery
 * codes never reach a log or an audit row; audit rows hold ids and the method.
 */
class StaffMfa
{
    public const RECOVERY_CODE_COUNT = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function isConfirmed(string $userId): bool
    {
        return DB::table('user_mfa_factors')->where('user_id', $userId)->whereNotNull('confirmed_at')->exists();
    }

    /** The id of the user's confirmed factor. A session proves a second step for that factor only. */
    public function confirmedFactorId(string $userId): ?string
    {
        $id = DB::table('user_mfa_factors')->where('user_id', $userId)->whereNotNull('confirmed_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Starts or restarts enrolment. Null when a factor is already confirmed.
     *
     * @return array{secret: string, otpauth_uri: string}|null
     */
    public function begin(User $user): ?array
    {
        $secret = Totp::generateSecret();

        $started = DB::transaction(function () use ($user, $secret): bool {
            $factor = UserMfaFactor::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($factor?->confirmed_at !== null) {
                return false;
            }

            $factor ??= new UserMfaFactor;
            $factor->forceFill(['user_id' => $user->id, 'secret' => $secret, 'last_used_step' => null])->save();

            return true;
        });

        return $started ? [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, (string) config('app.name'), (string) $user->email),
        ] : null;
    }

    /**
     * Confirms enrolment with a code from the app. Returns the recovery codes
     * (shown once), or null when the code is wrong or there is nothing to confirm.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code, ?string $ip, ?string $correlationId = null): ?array
    {
        return DB::transaction(function () use ($user, $code, $ip, $correlationId): ?array {
            $factor = UserMfaFactor::query()->where('user_id', $user->id)->whereNull('confirmed_at')->lockForUpdate()->first();
            $step = $factor === null ? null : Totp::match($factor->secret, $code, now()->getTimestamp(), null);

            if ($factor === null || $step === null) {
                return null;
            }

            $factor->forceFill(['confirmed_at' => now(), 'last_used_step' => $step])->save();

            $codes = $this->issueRecoveryCodes($user->id);

            $this->audit->record($user->id, 'auth.mfa_enrolled', 'user', $user->id, after: ['method' => 'totp'], ip: $ip, correlationId: $correlationId);

            return $codes;
        });
    }

    /**
     * Replaces every recovery code with ten new ones, shown once. Needs a
     * current code from the app (a step that was not already used), so a
     * stolen session alone cannot do it. Null when the code is wrong or no
     * factor is confirmed.
     *
     * @return list<string>|null
     */
    public function regenerateRecoveryCodes(User $user, string $code, ?string $ip, ?string $correlationId = null): ?array
    {
        return DB::transaction(function () use ($user, $code, $ip, $correlationId): ?array {
            $factor = UserMfaFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            $step = $factor === null ? null : Totp::match($factor->secret, $code, now()->getTimestamp(), $factor->last_used_step);

            if ($factor === null || $step === null) {
                return null;
            }

            $factor->forceFill(['last_used_step' => $step])->save();
            DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $codes = $this->issueRecoveryCodes($user->id);

            $this->audit->record($user->id, 'auth.mfa_recovery_codes_regenerated', 'user', $user->id, after: ['count' => count($codes)], ip: $ip, correlationId: $correlationId);

            return $codes;
        });
    }

    /**
     * Operator reset for a staff member who lost their device and recovery
     * codes (ADR-011). Authorisation is the caller's job; this removes the
     * factor and all recovery codes, ends the person's stored sessions and
     * writes the audit row, in one transaction. The reason is free text for
     * the audit trail and must not hold secrets.
     */
    public function reset(User $actor, string $targetUserId, string $reason, ?string $ip, ?string $correlationId = null): void
    {
        DB::transaction(function () use ($actor, $targetUserId, $reason, $ip, $correlationId): void {
            DB::table('user_mfa_recovery_codes')->where('user_id', $targetUserId)->delete();
            DB::table('user_mfa_factors')->where('user_id', $targetUserId)->delete();

            // Sessions held in the database go at once. Any other store is
            // covered by EnsureSurfaceSession, which ends a session that
            // claims a second step the user no longer has.
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $targetUserId)->delete();
            }

            $this->audit->record(
                $actor->id,
                'auth.mfa_reset',
                'user',
                $targetUserId,
                after: ['reason' => $reason, 'target_user_id' => $targetUserId],
                ip: $ip,
                correlationId: $correlationId,
            );
        });
    }

    /**
     * Inserts ten fresh codes and returns them in the clear, once.
     *
     * @return list<string>
     */
    private function issueRecoveryCodes(string $userId): array
    {
        $codes = [];
        $rows = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->newRecoveryCode();
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $userId,
                'code_hash' => $this->hashRecoveryCode($codes[$i]),
            ];
        }
        DB::table('user_mfa_recovery_codes')->insert($rows);

        return $codes;
    }

    /**
     * Checks a sign-in code or recovery code. Returns the method that
     * succeeded ('totp' or 'recovery_code'), or null. A TOTP step and a
     * recovery code each work once.
     */
    public function challenge(string $userId, ?string $code, ?string $recoveryCode): ?string
    {
        if ($code !== null && $code !== '') {
            return $this->acceptTotp($userId, $code) ? 'totp' : null;
        }

        if ($recoveryCode !== null && $recoveryCode !== '') {
            return $this->consumeRecoveryCode($userId, $recoveryCode) ? 'recovery_code' : null;
        }

        return null;
    }

    public function recordVerified(string $userId, string $method, ?string $ip, ?string $correlationId = null): void
    {
        $this->audit->record($userId, 'auth.mfa_verified', 'user', $userId, after: ['method' => $method], ip: $ip, correlationId: $correlationId);
    }

    /**
     * Tells the account holder, by email, that a recovery code just signed in
     * (ADR-011 review). The mail carries the time and the number of codes
     * left, never a code or an address. A mail failure is logged and must not
     * block a sign-in that already succeeded; the audit row stays the record.
     */
    public function alertRecoveryCodeUsed(User $user): void
    {
        $remaining = DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count();

        try {
            Mail::to($user->email)->send(new RecoveryCodeUsed(now()->utc()->format('Y-m-d H:i').' UTC', $remaining));
        } catch (Throwable $e) {
            Log::warning('auth.mfa.recovery_alert_failed', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    public function recordFailed(string $userId, string $stage, ?string $ip, ?string $correlationId = null): void
    {
        $this->audit->record($userId, 'auth.mfa_failed', 'user', $userId, after: ['stage' => $stage], ip: $ip, correlationId: $correlationId);
    }

    private function acceptTotp(string $userId, string $code): bool
    {
        return DB::transaction(function () use ($userId, $code): bool {
            $factor = UserMfaFactor::query()->where('user_id', $userId)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            $step = $factor === null ? null : Totp::match($factor->secret, $code, now()->getTimestamp(), $factor->last_used_step);

            if ($factor === null || $step === null) {
                return false;
            }

            $factor->forceFill(['last_used_step' => $step])->save();

            return true;
        });
    }

    private function consumeRecoveryCode(string $userId, string $submitted): bool
    {
        // One statement: two concurrent requests cannot both consume the code.
        return DB::table('user_mfa_recovery_codes')
            ->where('user_id', $userId)
            ->where('code_hash', $this->hashRecoveryCode($submitted))
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    /** 64 random bits in four groups of four hex digits. */
    private function newRecoveryCode(): string
    {
        return implode('-', str_split(bin2hex(random_bytes(8)), 4));
    }

    private function hashRecoveryCode(string $code): string
    {
        $normalised = strtolower((string) preg_replace('/[\s-]+/', '', $code));

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }
}
