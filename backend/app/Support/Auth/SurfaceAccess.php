<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Whether a user may hold a session on a surface. Asked at sign-in and again
 * on every authenticated request, from stored data, so losing the right ends
 * the session on the next request.
 */
class SurfaceAccess
{
    public function allows(string $userId, string $surface): bool
    {
        // Whether a deployed admin session may do more than enrol a second
        // factor is decided by EnsureSurfaceSession (ADR-011), not here.
        if (DB::table('users')->where('id', $userId)->value('status') !== 'active') {
            return false;
        }

        return match ($surface) {
            'customer' => true,
            'admin' => DB::table('user_role_scopes')
                ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                ->where('user_role_scopes.user_id', $userId)
                ->whereNull('user_role_scopes.revoked_at')
                ->whereIn('roles.slug', (array) config('surfaces.staff_roles'))
                ->exists(),
            'merchant' => DB::table('merchant_staff')
                ->join('merchants', 'merchants.id', '=', 'merchant_staff.merchant_id')
                ->where('merchant_staff.user_id', $userId)
                ->where('merchant_staff.status', 'active')
                ->where('merchants.status', '<>', 'suspended')
                ->exists(),
            default => false,
        };
    }
}
