<?php

namespace App\Application\Commands;

use App\Application\Queries\AccessChecker;
use App\Domain\Identity\AccessDenied;
use App\Domain\Identity\Scope;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way a role is granted or revoked through the application
 * (ADR-005). Every rule is checked against stored data, and the grant and its
 * audit row are written in one transaction.
 */
class RoleGrantService
{
    private const SCOPE_TABLES = ['city' => 'cities', 'merchant' => 'merchants', 'branch' => 'branches'];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the new grant's id
     *
     * @throws AccessDenied
     */
    public function grant(User $actor, User $target, string $roleSlug, Scope $scope): string
    {
        return DB::transaction(function () use ($actor, $target, $roleSlug, $scope): string {
            $this->assertMayAssign($actor, $target, $roleSlug, $scope);

            if (DB::table('users')->where('id', $target->getKey())->value('status') !== 'active') {
                throw AccessDenied::because(AccessDenied::TARGET_INACTIVE);
            }

            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                throw AccessDenied::because(AccessDenied::UNKNOWN_ROLE);
            }

            $id = strtolower((string) Str::ulid());

            try {
                // Savepoint: a duplicate must not poison the caller's transaction.
                DB::transaction(fn () => DB::table('user_role_scopes')->insert([
                    'id' => $id,
                    'user_id' => $target->getKey(),
                    'role_id' => $roleId,
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw AccessDenied::because(AccessDenied::ALREADY_GRANTED);
            }

            $this->audit->record(
                actorUserId: (string) $actor->getKey(),
                action: 'role.granted',
                subjectType: 'user',
                subjectId: (string) $target->getKey(),
                scopeCityId: $scope->type === 'city' ? $scope->id : null,
                after: ['grant_id' => $id, 'role' => $roleSlug, 'scope_type' => $scope->type, 'scope_id' => $scope->id],
            );

            return $id;
        });
    }

    /**
     * @throws AccessDenied
     */
    public function revoke(User $actor, string $grantId): void
    {
        DB::transaction(function () use ($actor, $grantId): void {
            $grant = DB::table('user_role_scopes')
                ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                ->where('user_role_scopes.id', $grantId)
                ->whereNull('user_role_scopes.revoked_at')
                ->lockForUpdate()
                ->first(['user_role_scopes.user_id', 'user_role_scopes.scope_type', 'user_role_scopes.scope_id', 'roles.slug']);

            if ($grant === null) {
                throw AccessDenied::because(AccessDenied::GRANT_NOT_FOUND);
            }

            $scope = Scope::of($grant->scope_type, $grant->scope_id);
            $target = (new User)->forceFill(['id' => $grant->user_id]);

            // Whoever could not have granted it may not revoke it either.
            $this->assertMayAssign($actor, $target, $grant->slug, $scope, checkScopeTarget: false);

            DB::table('user_role_scopes')->where('id', $grantId)->update(['revoked_at' => now()]);

            $this->audit->record(
                actorUserId: (string) $actor->getKey(),
                action: 'role.revoked',
                subjectType: 'user',
                subjectId: $grant->user_id,
                scopeCityId: $scope->type === 'city' ? $scope->id : null,
                before: ['grant_id' => $grantId, 'role' => $grant->slug, 'scope_type' => $scope->type, 'scope_id' => $scope->id],
            );
        });
    }

    private function assertMayAssign(User $actor, User $target, string $roleSlug, Scope $scope, bool $checkScopeTarget = true): void
    {
        if ($actor->getKey() === $target->getKey()) {
            throw AccessDenied::because(AccessDenied::SELF_GRANT);
        }

        /** @var array{scope_types: list<string>, assignable_by: list<string>|null}|null $role */
        $role = config("rbac.roles.{$roleSlug}");
        if ($role === null) {
            throw AccessDenied::because(AccessDenied::UNKNOWN_ROLE);
        }

        // platform_owner, and roles owned by onboarding flows, are never
        // assignable here, whoever asks.
        if ($roleSlug === 'platform_owner' || $role['assignable_by'] === null) {
            throw AccessDenied::because(AccessDenied::ROLE_NOT_ASSIGNABLE);
        }

        if (! in_array($scope->type, $role['scope_types'], true)) {
            throw AccessDenied::because(AccessDenied::SCOPE_NOT_ALLOWED_FOR_ROLE);
        }

        if (! $this->access->allows($actor, 'users.roles.assign', $scope)) {
            throw AccessDenied::because(AccessDenied::NOT_PERMITTED);
        }

        // The actor must hold, over this scope, a role entitled to hand out
        // the requested one. This is what stops a city manager creating
        // another city manager, or anyone reaching above themselves.
        $actorRoles = $this->access->rolesCovering($actor, $scope);
        $entitled = ['platform_owner', ...$role['assignable_by']];

        if (array_intersect($actorRoles, $entitled) === []) {
            throw AccessDenied::because(AccessDenied::ROLE_NOT_ASSIGNABLE);
        }

        if ($checkScopeTarget && ! $scope->isGlobal()
            && ! DB::table(self::SCOPE_TABLES[$scope->type])->where('id', $scope->id)->exists()) {
            throw AccessDenied::because(AccessDenied::SCOPE_TARGET_MISSING);
        }
    }
}
