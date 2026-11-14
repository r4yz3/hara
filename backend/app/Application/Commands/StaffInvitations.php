<?php

namespace App\Application\Commands;

use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\InvitationConflict;
use App\Domain\Identity\InvitationNotAccepted;
use App\Domain\Identity\InvitationToken;
use App\Domain\Identity\Scope;
use App\Mail\StaffInvitation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Staff invitations (backlog U-05, ADR-016 draft).
 *
 * - Whether the inviter may hand out the role at that scope is decided by
 *   RoleGrantService, at invitation time and again at acceptance, so an
 *   inviter who lost their rights in between cannot be the source of a grant.
 * - Inviting never looks up whether the address has an account: one path,
 *   one row, one email. The difference only appears at acceptance, for the
 *   person holding the emailed token (see `accept`).
 * - The token is 256 random bits, stored only as a SHA-256, valid 72 hours,
 *   usable once.
 */
class StaffInvitations
{
    public const HOURS_VALID = 72;

    public function __construct(
        private readonly RoleGrantService $grants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the invitation id
     *
     * @throws AccessDenied
     * @throws InvitationConflict
     */
    public function invite(User $inviter, string $name, string $email, string $roleSlug, Scope $scope, string $adminOrigin): string
    {
        $email = strtolower(trim($email));
        $name = trim($name);

        if (DB::table('users')->where('id', $inviter->getKey())->value('email') === $email) {
            throw AccessDenied::because(AccessDenied::SELF_GRANT);
        }

        $token = InvitationToken::generate();

        [$id, $roleLabel, $scopeLabel] = DB::transaction(function () use ($inviter, $name, $email, $roleSlug, $scope, $token): array {
            // Serializes two invitations of one address.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["invite:{$email}"]);

            $this->grants->assertMayInvite($inviter, $roleSlug, $scope);

            $roleId = (string) DB::table('roles')->where('slug', $roleSlug)->value('id');

            // An expired, unanswered invitation does not block a new one.
            DB::table('staff_invitations')
                ->where('email', $email)->where('role_id', $roleId)->where('scope_type', $scope->type)
                ->where(fn ($q) => $scope->id === null ? $q->whereNull('scope_id') : $q->where('scope_id', $scope->id))
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $id = strtolower((string) Str::ulid());

            try {
                // Savepoint: a duplicate must not poison the transaction.
                DB::transaction(fn () => DB::table('staff_invitations')->insert([
                    'id' => $id,
                    'email' => $email,
                    'name' => $name,
                    'role_id' => $roleId,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                    'invited_by' => $inviter->getKey(),
                    'token_hash' => InvitationToken::hash($token),
                    'expires_at' => now()->addHours(self::HOURS_VALID),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new InvitationConflict;
            }

            // The address is deliberately not in the audit row.
            $this->audit->record(
                actorUserId: (string) $inviter->getKey(),
                action: 'staff_invitation.created',
                subjectType: 'staff_invitation',
                subjectId: $id,
                scopeCityId: $scope->type === 'city' ? $scope->id : null,
                after: ['role' => $roleSlug, 'scope_type' => $scope->type, 'scope_id' => $scope->id, 'expires_in_hours' => self::HOURS_VALID],
            );

            return [$id, $this->roleLabel($roleSlug), $this->scopeLabel($scope->type, $scope->id)];
        });

        // In the fragment: browsers do not send it to servers, so the token
        // stays out of access logs and referrer headers.
        $url = rtrim($adminOrigin, '/').'/invite#token='.$token;

        Mail::to($email)->send(new StaffInvitation($url, $token, $name, $roleLabel, $scopeLabel, self::HOURS_VALID));

        return $id;
    }

    /**
     * @throws AccessDenied
     */
    public function revoke(User $actor, string $invitationId): void
    {
        DB::transaction(function () use ($actor, $invitationId): void {
            $row = DB::table('staff_invitations as i')
                ->join('roles as r', 'r.id', '=', 'i.role_id')
                ->where('i.id', $invitationId)
                ->whereNull('i.accepted_at')->whereNull('i.revoked_at')->where('i.expires_at', '>', now())
                ->lockForUpdate()
                ->first(['i.id', 'i.scope_type', 'i.scope_id', 'r.slug']);

            if ($row === null) {
                throw AccessDenied::because(AccessDenied::GRANT_NOT_FOUND);
            }

            $scope = Scope::of($row->scope_type, $row->scope_id);

            // Whoever could not have granted the role may not withdraw the invitation either.
            if (! $this->grants->mayRevoke($actor, '', $row->slug, $scope)) {
                throw AccessDenied::because(AccessDenied::NOT_PERMITTED);
            }

            DB::table('staff_invitations')->where('id', $invitationId)->update(['revoked_at' => now(), 'updated_at' => now()]);

            $this->audit->record(
                actorUserId: (string) $actor->getKey(),
                action: 'staff_invitation.revoked',
                subjectType: 'staff_invitation',
                subjectId: $invitationId,
                scopeCityId: $scope->type === 'city' ? $scope->id : null,
                before: ['role' => $row->slug, 'scope_type' => $scope->type, 'scope_id' => $scope->id],
            );
        });
    }

    /**
     * What the invitee may see before accepting. Null for an unknown, used,
     * revoked or expired token; the caller must not say which.
     *
     * @return array{name: string, role_label: string, scope_label: string, expires_at: string}|null
     */
    public function inspect(string $token): ?array
    {
        $row = $this->pending($token)->first(['i.name', 'i.scope_type', 'i.scope_id', 'i.expires_at', 'r.slug']);

        if ($row === null) {
            return null;
        }

        return [
            'name' => $row->name,
            'role_label' => $this->roleLabel($row->slug),
            'scope_label' => $this->scopeLabel($row->scope_type, $row->scope_id),
            'expires_at' => Carbon::parse((string) $row->expires_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Accepts an invitation in one transaction: the account (new, or the
     * existing one proven by its current password), the grant through
     * RoleGrantService as the inviter, and the audit rows.
     *
     * Someone who already has an account with the address is not given a
     * second one and never has their password changed: the password in the
     * request must be that account's, and only then is the role attached.
     * Anything that goes wrong answers the same way, so a stolen token cannot
     * be used to learn whether the address has an account.
     *
     * @return bool false when nothing was accepted; the caller must not say why
     */
    public function accept(string $token, string $password, ?string $ip = null): bool
    {
        try {
            return DB::transaction(function () use ($token, $password, $ip): bool {
                $invite = $this->pending($token)->lockForUpdate()->first(['i.*', 'r.slug']);
                if ($invite === null) {
                    return false;
                }

                // Serializes with registration and other work on this address.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["register:{$invite->email}"]);

                $inviter = User::query()->find($invite->invited_by);
                if ($inviter === null) {
                    return false;
                }

                $existing = User::query()->where('email', $invite->email)->lockForUpdate()->first();

                if ($existing !== null) {
                    if (! Hash::check($password, $existing->password)) {
                        Log::info('staff_invitation.accept.refused', ['invitation_id' => $invite->id, 'reason' => 'credentials']);

                        return false;
                    }
                    $user = $existing;
                } else {
                    $user = User::create(['name' => $invite->name, 'email' => $invite->email, 'password' => $password]);
                    // They proved they control the address by opening the link.
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                try {
                    $this->grants->grant($inviter, $user, $invite->slug, Scope::of($invite->scope_type, $invite->scope_id));
                } catch (AccessDenied $denied) {
                    // The inviter lost the right, the role or place went away, or the account is suspended.
                    Log::info('staff_invitation.accept.refused', ['invitation_id' => $invite->id, 'reason' => $denied->reason]);

                    // Undoes the account created above too.
                    throw new InvitationNotAccepted;
                }

                DB::table('staff_invitations')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);

                $this->audit->record(
                    actorUserId: (string) $user->getKey(),
                    action: 'staff_invitation.accepted',
                    subjectType: 'staff_invitation',
                    subjectId: $invite->id,
                    scopeCityId: $invite->scope_type === 'city' ? $invite->scope_id : null,
                    after: ['user_id' => $user->getKey(), 'account_created' => $existing === null, 'role' => $invite->slug, 'scope_type' => $invite->scope_type, 'scope_id' => $invite->scope_id],
                    ip: $ip,
                );

                return true;
            });
        } catch (InvitationNotAccepted|UniqueConstraintViolationException) {
            return false;
        }
    }

    private function pending(string $token): Builder
    {
        return DB::table('staff_invitations as i')
            ->join('roles as r', 'r.id', '=', 'i.role_id')
            ->where('i.token_hash', InvitationToken::hash($token))
            ->whereNull('i.accepted_at')->whereNull('i.revoked_at')
            ->where('i.expires_at', '>', now());
    }

    private function roleLabel(string $slug): string
    {
        return (string) config("rbac.roles.{$slug}.label", $slug);
    }

    private function scopeLabel(string $type, ?string $id): string
    {
        if ($type === 'global') {
            return 'All cities';
        }

        return (string) (DB::table('cities')->where('id', $id)->value('name') ?? 'One city');
    }
}
