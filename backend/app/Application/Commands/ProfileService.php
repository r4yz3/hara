<?php

namespace App\Application\Commands;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes the signed-in person's own profile. Only the display name is
 * editable: email and phone need a verification flow, and status, roles and
 * password change only through their own audited services. The change and its
 * audit row are written in one transaction (spec 6.3).
 */
class ProfileService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function rename(User $user, string $name, ?string $ip, ?string $correlationId): User
    {
        return DB::transaction(function () use ($user, $name, $ip, $correlationId): User {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = (string) $locked->name;

            if ($before !== $name) {
                $locked->forceFill(['name' => $name])->save();
                $this->audit->record(
                    (string) $locked->id, 'user.profile_updated', 'user', (string) $locked->id,
                    before: ['name' => $before], after: ['name' => $name],
                    ip: $ip, correlationId: $correlationId,
                );
            }

            return $locked;
        });
    }
}
