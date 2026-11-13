<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a signed-in user may know about themselves: an explicit list of
 * fields, so nothing added to the users table later leaks by default.
 */
final readonly class CurrentUserResource
{
    public function __construct(private User $user, private string $surface) {}

    /**
     * @return array{id: string, name: string, email: string, email_verified: bool, surface: string, grants: list<array{role: string, scope_type: string, scope_id: string|null, permissions: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->user->id,
            'name' => (string) $this->user->name,
            'email' => (string) $this->user->email,
            'email_verified' => $this->user->email_verified_at !== null,
            'surface' => $this->surface,
            'grants' => $this->grants(),
        ];
    }

    /**
     * @return list<array{role: string, scope_type: string, scope_id: string|null, permissions: list<string>}>
     */
    private function grants(): array
    {
        $rows = DB::table('user_role_scopes')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $this->user->id)
            ->whereNull('user_role_scopes.revoked_at')
            ->orderBy('roles.slug')
            ->orderBy('user_role_scopes.scope_id')
            ->get(['roles.id as role_id', 'roles.slug', 'user_role_scopes.scope_type', 'user_role_scopes.scope_id']);

        $grants = [];

        foreach ($rows as $row) {
            /** @var list<string> $permissions */
            $permissions = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_permissions.role_id', $row->role_id)
                ->orderBy('permissions.slug')
                ->pluck('permissions.slug')
                ->all();

            $grants[] = [
                'role' => $row->slug,
                'scope_type' => $row->scope_type,
                'scope_id' => $row->scope_id,
                'permissions' => $permissions,
            ];
        }

        return $grants;
    }
}
