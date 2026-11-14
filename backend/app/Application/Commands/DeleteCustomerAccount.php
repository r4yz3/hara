<?php

namespace App\Application\Commands;

use App\Models\User;
use App\Support\Errors\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A customer closes their own account (threat model T31, ADR-017).
 *
 * Only people who are purely customers may use it. Work accounts (staff,
 * merchant staff, riders, anyone with a second factor) answer 409 and keep
 * every row: closing those is an administrator's decision.
 *
 * In one transaction, after locking the user row, it removes everything that
 * identifies the person: saved addresses (home positions), profile, reset
 * rows, API tokens, devices, consent rows, browser sessions. The users row is
 * then deleted. Four foreign keys restrict deleting a user (role grants,
 * merchant staff, riders, staff invitations); the customer role grant is the
 * customer's own and goes first. If history of past work remains in those
 * tables, the row is anonymised instead, so that history keeps a valid owner.
 *
 * The audit row holds the user id and nothing else. Existing audit rows stay:
 * they hold ids, never addresses, and have no foreign key to users.
 */
class DeleteCustomerAccount
{
    public const CONFLICT_MESSAGE = 'This account is also used for work. Ask an administrator to close it.';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return bool false when the password is wrong, with nothing changed
     *
     * @throws ApiError 409 when the account is also used for work
     * @throws AuthenticationException when the account is already gone
     */
    public function handle(User $user, string $password, ?string $correlationId): bool
    {
        return DB::transaction(function () use ($user, $password, $correlationId): bool {
            /** @var User|null $locked */
            $locked = User::query()->lockForUpdate()->find($user->id);
            if ($locked === null) {
                // A parallel request deleted it first.
                throw new AuthenticationException;
            }

            if (! Hash::check($password, (string) $locked->password)) {
                return false;
            }

            $id = (string) $locked->id;

            if ($this->usedForWork($id)) {
                throw new ApiError(409, 'CONFLICT', self::CONFLICT_MESSAGE);
            }

            DB::table('saved_addresses')->where('user_id', $id)->delete();
            DB::table('customer_profiles')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $locked->getMorphClass())
                ->where('tokenable_id', $id)
                ->delete();
            DB::table('devices')->where('user_id', $id)->delete();
            DB::table('privacy_consents')->where('user_id', $id)->delete();
            // Unconfirmed enrolments only: a confirmed factor refused above.
            DB::table('user_mfa_recovery_codes')->where('user_id', $id)->delete();
            DB::table('user_mfa_factors')->where('user_id', $id)->delete();
            // The customer role is the person's own entitlement, live or revoked.
            DB::table('user_role_scopes')
                ->where('user_id', $id)
                ->whereIn('role_id', DB::table('roles')->where('slug', 'customer')->select('id'))
                ->delete();
            // Database-backed browser sessions; the other drivers die on the missing user.
            DB::table('sessions')->where('user_id', $id)->delete();

            if ($this->historyRemains($id)) {
                $locked->forceFill([
                    'name' => 'Deleted account',
                    'email' => "deleted-{$id}@invalid.example",
                    'email_verified_at' => null,
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                    'phone_e164' => null,
                    'phone_verified_at' => null,
                    'status' => 'suspended',
                    'last_login_at' => null,
                    'remember_token' => Str::random(60),
                ])->save();
            } else {
                $locked->delete();
            }

            $this->audit->record($id, 'account.deleted', 'user', $id, correlationId: $correlationId);

            return true;
        });
    }

    private function usedForWork(string $userId): bool
    {
        return DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->whereNull('user_role_scopes.revoked_at')
            ->where('roles.slug', '<>', 'customer')
            ->exists()
            || DB::table('merchant_staff')->where('user_id', $userId)->where('status', 'active')->exists()
            || DB::table('riders')->where('user_id', $userId)->exists()
            || DB::table('user_mfa_factors')->where('user_id', $userId)->whereNotNull('confirmed_at')->exists();
    }

    /** Rows whose foreign key restricts deleting the user (past work, already ended). */
    private function historyRemains(string $userId): bool
    {
        return DB::table('user_role_scopes')->where('user_id', $userId)->exists()
            || DB::table('merchant_staff')->where('user_id', $userId)->exists()
            || DB::table('riders')->where('user_id', $userId)->exists()
            || DB::table('staff_invitations')->where('invited_by', $userId)->exists();
    }
}
