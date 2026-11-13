<?php

namespace App\Support\Auth;

use App\Support\ProductionGuard;
use Illuminate\Support\Facades\DB;

/**
 * Whether a user may hold a session on a surface. Asked at sign-in and again
 * on every authenticated request, from stored data, so losing the right ends
 * the session on the next request.
 */
class SurfaceAccess
{
    public function isOpen(string $surface): bool
    {
        // ADR-011: deployed admin surfaces stay closed until MFA is reviewed.
        return $surface !== 'admin' || app()->environment(ProductionGuard::DEVELOPMENT_ENVIRONMENTS);
    }

    public function allows(string $userId, string $surface): bool
    {
        if (! $this->isOpen($surface) || DB::table('users')->where('id', $userId)->value('status') !== 'active') {
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
