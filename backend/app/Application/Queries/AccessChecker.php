<?php

namespace App\Application\Queries;

use App\Domain\Identity\Scope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do this, here?" (ADR-005).
 *
 * Deny by default. Every answer is read from the database at call time, so a
 * revoked grant or a suspended user stops working on the very next check;
 * nothing is cached.
 */
class AccessChecker
{
    /**
     * The five checks: active actor, live grant, role carries the permission,
     * grant scope covers the target, target relationship resolved from stored
     * data (never from the request).
     */
    public function allows(User $user, string $permission, Scope $target): bool
    {
        foreach ($this->grantScopes($user, $permission) as $scope) {
            if ($this->covers($scope, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * City ids the user may act on with this permission, for filtering lists
     * in the query. `all` means a global grant.
     *
     * @return array{all: bool, city_ids: list<string>}
     */
    public function cityReach(User $user, string $permission): array
    {
        $all = false;
        $cityIds = [];

        foreach ($this->grantScopes($user, $permission) as $scope) {
            if ($scope->isGlobal()) {
                $all = true;
            } elseif ($scope->type === 'city' && $scope->id !== null) {
                $cityIds[] = $scope->id;
            }
        }

        return ['all' => $all, 'city_ids' => array_values(array_unique($cityIds))];
    }

    /**
     * Role slugs the user holds through live grants that cover the target.
     *
     * @return list<string>
     */
    public function rolesCovering(User $user, Scope $target): array
    {
        if (! $this->isActive($user)) {
            return [];
        }

        $rows = DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $user->getKey())
            ->whereNull('user_role_scopes.revoked_at')
            ->get(['roles.slug', 'user_role_scopes.scope_type', 'user_role_scopes.scope_id']);

        $roles = [];

        foreach ($rows as $row) {
            if ($this->covers(Scope::of($row->scope_type, $row->scope_id), $target)) {
                $roles[] = $row->slug;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<Scope> scopes of the user's live grants whose role carries the permission
     */
    private function grantScopes(User $user, string $permission): array
    {
        if (! $this->isActive($user)) {
            return [];
        }

        return DB::table('user_role_scopes')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_role_scopes.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_role_scopes.user_id', $user->getKey())
            ->whereNull('user_role_scopes.revoked_at')
            ->where('permissions.slug', $permission)
            ->get(['user_role_scopes.scope_type', 'user_role_scopes.scope_id'])
            ->map(fn (object $row): Scope => Scope::of($row->scope_type, $row->scope_id))
            ->all();
    }

    private function isActive(User $user): bool
    {
        // Read from storage, not from the possibly stale model instance.
        return DB::table('users')->where('id', $user->getKey())->value('status') === 'active';
    }

    private function covers(Scope $grant, Scope $target): bool
    {
        if ($grant->isGlobal() || $grant->equals($target)) {
            return true;
        }

        // A branch sits inside one merchant and one city.
        if ($target->type === 'branch' && in_array($grant->type, ['city', 'merchant'], true)) {
            $column = $grant->type === 'city' ? 'city_id' : 'merchant_id';

            return DB::table('branches')->where('id', $target->id)->where($column, $grant->id)->exists();
        }

        return false;
    }
}
