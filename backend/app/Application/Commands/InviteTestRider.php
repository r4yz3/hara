<?php

namespace App\Application\Commands;

use App\Domain\Identity\TestRiderRefused;
use App\Mail\TestRiderInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One synthetic rider so the rider app can complete a real sign-in (ADR-021).
 * Console only: nothing in the HTTP layer reaches this class.
 *
 * The account is created with a random password nobody sees, and the operator
 * chooses the real one through the existing password-reset flow (the link
 * opens the customer app's /reset-password page; the token is in the fragment).
 * No documents, vehicle, city access, location or payout data is created.
 */
class InviteTestRider
{
    public const ACTOR = 'system/console';

    public const CREATED = 'rider_test.created';

    private const MAILS_PER_WINDOW = 3;

    private const WINDOW_SECONDS = 900;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return bool true when a new account was created, false when an account
     *              this command made earlier was reused
     *
     * @throws TestRiderRefused
     */
    public function handle(string $email, string $name, string $customerOrigin): bool
    {
        $email = strtolower(trim($email));

        $key = 'rider-invite-test:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, self::MAILS_PER_WINDOW)) {
            throw TestRiderRefused::rateLimited(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $token = bin2hex(random_bytes(32));

        $created = DB::transaction(function () use ($email, $name, $token): bool {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["register:{$email}"]);

            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            $created = $user === null;

            if ($user === null) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    // Random and discarded (hashed by the model cast): no known password exists.
                    'password' => bin2hex(random_bytes(32)),
                ]);
                $riderId = strtolower((string) Str::ulid());
                DB::table('riders')->insert([
                    'id' => $riderId,
                    'user_id' => $user->id,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit->record(null, self::CREATED, 'rider', $riderId, after: [
                    'actor' => self::ACTOR,
                    'synthetic' => true,
                    'status' => 'approved',
                ]);
            } else {
                $this->assertReusable($user);
            }

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => hash('sha256', $token), 'created_at' => now()],
            );

            $this->audit->record(null, 'rider_test.link_issued', 'user', $user->id, after: [
                'actor' => self::ACTOR,
                'expires_in_minutes' => PasswordRecovery::MINUTES_VALID,
                'new_account' => $created,
            ]);

            return $created;
        });

        // In the fragment: never sent to a server, so not in access logs.
        $url = rtrim($customerOrigin, '/').'/reset-password#token='.$token;
        Mail::to($email)->send(new TestRiderInvitation($url, $token, PasswordRecovery::MINUTES_VALID));

        return $created;
    }

    /** Only an account this command made, still active and approved, with no other role. */
    private function assertReusable(User $user): void
    {
        if (DB::table('user_role_scopes')->where('user_id', $user->id)->whereNull('revoked_at')->exists()
            || DB::table('customer_profiles')->where('user_id', $user->id)->exists()
            || DB::table('merchant_staff')->where('user_id', $user->id)->exists()) {
            throw TestRiderRefused::staffAccount();
        }

        $madeHere = DB::table('audit_logs')
            ->where('action', self::CREATED)
            ->whereIn('subject_id', DB::table('riders')->where('user_id', $user->id)->select('id'))
            ->exists();

        if (! $madeHere) {
            throw TestRiderRefused::notATestRider();
        }

        $status = DB::table('riders')->where('user_id', $user->id)->value('status');
        if ($user->status !== 'active' || $status !== 'approved') {
            throw TestRiderRefused::suspended();
        }
    }
}
