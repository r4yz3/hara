<?php

namespace App\Application\Commands;

use App\Domain\Identity\Totp;
use App\Models\User;
use App\Models\UserMfaFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            $codes = [];
            $rows = [];
            for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
                $codes[] = $this->newRecoveryCode();
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'user_id' => $user->id,
                    'code_hash' => $this->hashRecoveryCode($codes[$i]),
                ];
            }
            DB::table('user_mfa_recovery_codes')->insert($rows);

            $this->audit->record($user->id, 'auth.mfa_enrolled', 'user', $user->id, after: ['method' => 'totp'], ip: $ip, correlationId: $correlationId);

            return $codes;
        });
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
