<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff member's TOTP secret. Written only by StaffMfa; the secret is
 * encrypted at rest and never serialised.
 */
#[Hidden(['secret'])]
class UserMfaFactor extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_step' => 'integer',
        ];
    }
}
