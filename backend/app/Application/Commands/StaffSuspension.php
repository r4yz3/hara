<?php

namespace App\Application\Commands;

use App\Application\Queries\AccessChecker;
use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\Scope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspends and reactivates a staff account. Product owner decision
 * (2026-11-14): only a global administration role may do it. The rules are
 * checked against stored data inside one transaction with the audit row.
 *
 * - The actor must hold `users.suspend` through a GLOBAL grant; a city grant
 *   never covers a global target, so city-scoped holders are refused.
 * - Nobody suspends themselves, and a platform owner is never suspended
 *   through the application (the bootstrap path remains the way to lock one
 *   out).
 * - Suspending ends the person's stored web sessions and deletes their
 *   rider bearer tokens. Sessions held elsewhere stop on their next request
 *   because SurfaceAccess and AccessChecker read `users.status` every time.
 *   Reactivating restores neither.
 */
class StaffSuspension
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether the actor holds the permission through a global grant, without logging a denial.
     */
    public function mayAct(User $actor, bool $log = false): bool
    {
        return $this->access->allows($actor, 'users.suspend', Scope::global(), $log);
    }

    /**
     * @throws AccessDenied
     */
    public function suspend(User $actor, string $targetUserId, string $reason, ?string $ip = null, ?string $correlationId = null): void
    {
        DB::transaction(function () use ($actor, $targetUserId, $reason, $ip, $correlationId): void {
            $status = $this->lockAndCheck($actor, $targetUserId);

            if ($status === 'suspended') {
                throw AccessDenied::because(AccessDenied::ALREADY_SUSPENDED);
            }

            if ($this->isPlatformOwner($targetUserId)) {
                throw AccessDenied::because(AccessDenied::PROTECTED_TARGET);
            }

            DB::table('users')->where('id', $targetUserId)->update(['status' => 'suspended', 'updated_at' => now()]);

            // Sessions held in the database go at once. Any other store is
            // covered by EnsureSurfaceSession, which asks SurfaceAccess on every request.
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $targetUserId)->delete();
            }

            // A suspended account is refused by EnsureRiderToken already; deleting the tokens
            // also stops them coming back to life on reactivation.
            DB::table('personal_access_tokens')->where('tokenable_id', $targetUserId)->delete();

            $this->audit->record(
                $actor->getKey(),
                'user.suspended',
                'user',
                $targetUserId,
                before: ['status' => 'active'],
                after: ['status' => 'suspended', 'reason' => $reason],
                ip: $ip,
                correlationId: $correlationId,
            );
        });
    }

    /**
     * @throws AccessDenied
     */
    public function reactivate(User $actor, string $targetUserId, ?string $reason, ?string $ip = null, ?string $correlationId = null): void
    {
        DB::transaction(function () use ($actor, $targetUserId, $reason, $ip, $correlationId): void {
            if ($this->lockAndCheck($actor, $targetUserId) !== 'suspended') {
                throw AccessDenied::because(AccessDenied::NOT_SUSPENDED);
            }

            DB::table('users')->where('id', $targetUserId)->update(['status' => 'active', 'updated_at' => now()]);

            $this->audit->record(
                $actor->getKey(),
                'user.reactivated',
                'user',
                $targetUserId,
                before: ['status' => 'suspended'],
                after: ['status' => 'active', 'reason' => $reason],
                ip: $ip,
                correlationId: $correlationId,
            );
        });
    }

    /**
     * @return string the target's current status, row locked
     *
     * @throws AccessDenied
     */
    private function lockAndCheck(User $actor, string $targetUserId): string
    {
        if (! $this->mayAct($actor, log: true)) {
            throw AccessDenied::because(AccessDenied::NOT_PERMITTED);
        }

        if ($actor->getKey() === $targetUserId) {
            throw AccessDenied::because(AccessDenied::SELF_SUSPEND);
        }

        $status = DB::table('users')->where('id', $targetUserId)->lockForUpdate()->value('status');
        if ($status === null) {
            throw AccessDenied::because(AccessDenied::SCOPE_TARGET_MISSING);
        }

        return (string) $status;
    }

    private function isPlatformOwner(string $userId): bool
    {
        return DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->whereNull('user_role_scopes.revoked_at')
            ->where('roles.slug', 'platform_owner')
            ->exists();
    }
}
