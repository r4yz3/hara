<?php

namespace App\Application\Commands;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a customer account and nothing else: the role is fixed here, never
 * read from the request (AGENTS.md rule 7).
 */
class RegisterCustomer
{
    /**
     * @return bool false when the email already belongs to an account; the
     *              caller must not reveal which case happened
     */
    public function handle(string $name, string $email, string $password): bool
    {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($name, $email, $password): bool {
            // Serializes two registrations of the same address.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["register:{$email}"]);

            if (DB::table('users')->where('email', $email)->exists()) {
                return false;
            }

            $user = User::create(['name' => trim($name), 'email' => $email, 'password' => $password]);

            DB::table('customer_profiles')->insert([
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_role_scopes')->insert([
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $user->id,
                'role_id' => DB::table('roles')->where('slug', 'customer')->value('id'),
                'scope_type' => 'global',
                'scope_id' => null,
            ]);

            return true;
        });
    }
}
