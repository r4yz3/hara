<?php

namespace App\Application\Commands;

use App\Domain\Identity\BootstrapOwnerRefused;
use App\Domain\Identity\InvitationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The first platform owner (ADR-018). Console only: nothing in the HTTP layer
 * reaches this class.
 *
 * It reuses the ADR-016 invitation record, token and acceptance flow, but
 * without an inviter, because no owner exists yet and `platform_owner` can
 * never be granted through RoleGrantService. Two rules keep it safe:
 *
 * - it works only while no active platform owner exists, checked when the
 *   link is issued and again when it is accepted;
 * - both checks run under one advisory lock, so two runs, or a run and an
 *   acceptance, cannot between them leave two owners made by this path.
 */
class BootstrapOwner
{
    public const ROLE = 'platform_owner';

    public const ACTOR = 'system/console';

    private const LOCK = 'bootstrap-owner';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return string the acceptance link. The token is in the fragment, so it
     *                never reaches a server log or a referrer header.
     *
     * @throws BootstrapOwnerRefused
     */
    public function issue(string $email, string $name, string $adminOrigin, bool $replacePending = false): string
    {
        $email = strtolower(trim($email));
        $token = InvitationToken::generate();

        DB::transaction(function () use ($email, $name, $token, $replacePending): void {
            $this->lock();

            if ($this->ownerExists()) {
                throw BootstrapOwnerRefused::ownerExists();
            }

            $pending = DB::table('staff_invitations')
                ->where('bootstrap', true)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());

            if ($pending->exists()) {
                if (! $replacePending) {
                    throw BootstrapOwnerRefused::pendingExists();
                }
                $pending->update(['revoked_at' => now(), 'updated_at' => now()]);
            }

            // An expired link is history; free the one-live-row-per-address index.
            DB::table('staff_invitations')
                ->where('bootstrap', true)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $id = strtolower((string) Str::ulid());

            DB::table('staff_invitations')->insert([
                'id' => $id,
                'email' => $email,
                'name' => $name,
                'role_id' => DB::table('roles')->where('slug', self::ROLE)->value('id')
                    ?? throw BootstrapOwnerRefused::roleCatalogueMissing(),
                'scope_type' => 'global',
                'scope_id' => null,
                'invited_by' => null,
                'bootstrap' => true,
                'token_hash' => InvitationToken::hash($token),
                'expires_at' => now()->addHours(StaffInvitations::HOURS_VALID),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Neither the address nor the token is recorded.
            $this->audit->record(
                actorUserId: null,
                action: 'staff_bootstrap_owner.issued',
                subjectType: 'staff_invitation',
                subjectId: $id,
                after: [
                    'actor' => self::ACTOR,
                    'role' => self::ROLE,
                    'scope_type' => 'global',
                    'expires_in_hours' => StaffInvitations::HOURS_VALID,
                    'replaced_pending' => $replacePending,
                ],
            );
        });

        return rtrim($adminOrigin, '/').'/invite#token='.$token;
    }

    /**
     * Called from StaffInvitations::accept, inside its transaction, for an
     * invitation with no inviter. Takes the same lock as `issue`.
     *
     * @return bool false when the owner may not be created now
     */
    public function grantOnAcceptance(User $user, object $invite): bool
    {
        $this->lock();

        if ($invite->slug !== self::ROLE || $this->ownerExists()) {
            return false;
        }

        if (DB::table('users')->where('id', $user->getKey())->value('status') !== 'active') {
            return false;
        }

        $grantId = strtolower((string) Str::ulid());

        DB::table('user_role_scopes')->insert([
            'id' => $grantId,
            'user_id' => $user->getKey(),
            'role_id' => $invite->role_id,
            'scope_type' => 'global',
            'scope_id' => null,
        ]);

        $this->audit->record(
            actorUserId: null,
            action: 'role.granted',
            subjectType: 'user',
            subjectId: (string) $user->getKey(),
            after: ['actor' => self::ACTOR, 'via' => 'bootstrap', 'grant_id' => $grantId, 'role' => self::ROLE, 'scope_type' => 'global', 'scope_id' => null],
        );

        return true;
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
    }

    private function ownerExists(): bool
    {
        return DB::table('user_role_scopes as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('r.slug', self::ROLE)
            ->whereNull('g.revoked_at')
            ->where('u.status', 'active')
            ->exists();
    }
}
