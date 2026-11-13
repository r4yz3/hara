<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Backlog B-03 / spec 7.3: recovery never reveals whether an account exists,
 * and a reset link works once, for a limited time.
 */
class PasswordRecoveryTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** Requests a reset and returns the token from the captured email. */
    private function requestToken(string $email = 'person@example.com', string $surface = 'customer'): string
    {
        $this->via($surface)->postJson('/api/v1/auth/password/forgot', ['email' => $email])->assertStatus(202);

        $token = null;
        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        return (string) $token;
    }

    public function test_known_and_unknown_addresses_get_the_same_answer(): void
    {
        $this->user();

        $known = $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com']);
        $unknown = $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json('data'), $unknown->json('data'));
        $this->assertSame(['status' => 'reset_requested'], $known->json('data'));
        Mail::assertSentCount(1);
    }

    public function test_the_email_goes_to_the_account_and_links_to_the_app_that_asked(): void
    {
        $user = $this->user();

        $this->via('admin')->postJson('/api/v1/auth/password/forgot', ['email' => ' Person@Example.com '])->assertStatus(202);

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use ($user): bool {
            // The token rides in the URL fragment, which browsers never send to a server.
            return $mail->hasTo($user->email)
                && str_starts_with($mail->url, 'http://localhost:3002/reset-password#token=')
                && ! str_contains($mail->url, '?')
                && ! str_contains($mail->url, $user->email);
        });
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        $this->user();
        $token = $this->requestToken();

        $row = DB::table('password_reset_tokens')->where('email', 'person@example.com')->sole();

        $this->assertGreaterThanOrEqual(40, strlen($token));
        $this->assertNotSame($token, $row->token);
        $this->assertSame(hash('sha256', $token), $row->token);
    }

    public function test_a_valid_token_sets_a_new_password_once(): void
    {
        $user = $this->user();
        $token = $this->requestToken();

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD])
            ->assertOk()->assertJsonPath('data.status', 'password_reset');

        $this->assertGuest();
        $this->login('customer', 'person@example.com')->assertStatus(401);
        $this->login('customer', 'person@example.com', self::NEW_PASSWORD)->assertOk();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $audit = DB::table('audit_logs')->where('action', 'auth.password_reset')->sole();
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($audit));

        // Second use of the same link.
        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'yet-another-passphrase'])
            ->assertStatus(422);
        $this->login('customer', 'person@example.com', self::NEW_PASSWORD)->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->user();
        $token = $this->requestToken();

        $this->travel(61)->minutes();

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['token']]]);
        $this->travelBack();
        $this->login('customer', 'person@example.com')->assertOk();
    }

    public function test_a_wrong_token_is_refused_with_the_same_message_as_an_expired_one(): void
    {
        $this->user();
        $token = $this->requestToken();

        $wrong = $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => str_repeat('a', strlen($token)), 'password' => self::NEW_PASSWORD]);
        $this->travel(61)->minutes();
        $expired = $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);

        $wrong->assertStatus(422);
        $this->assertSame($wrong->json('error.fields'), $expired->json('error.fields'));
    }

    public function test_a_weak_new_password_is_refused_and_the_link_stays_usable(): void
    {
        $this->user();
        $token = $this->requestToken();

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'short'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_a_newer_request_cancels_the_older_link(): void
    {
        $this->user();
        $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com'])->assertStatus(202);
        $first = DB::table('password_reset_tokens')->value('token');
        $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com'])->assertStatus(202);

        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertNotSame($first, DB::table('password_reset_tokens')->value('token'));
    }

    public function test_a_suspended_account_gets_no_email(): void
    {
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

        $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com'])->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_repeated_requests_for_one_address_stop_sending_mail_but_answer_the_same(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->via('customer')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com'])->assertStatus(202);
        }

        Mail::assertSentCount(3);
    }

    public function test_recovery_needs_a_known_app_origin_and_valid_input(): void
    {
        $this->user();

        $this->withoutHeader('Origin')->postJson('/api/v1/auth/password/forgot', ['email' => 'person@example.com'])->assertStatus(403);
        $this->withoutHeader('Origin')->postJson('/api/v1/auth/password/reset', ['token' => 'x', 'password' => self::NEW_PASSWORD])->assertStatus(403);
        $this->via('customer')->postJson('/api/v1/auth/password/forgot', [])->assertStatus(422);
        $this->via('customer')->postJson('/api/v1/auth/password/reset', [])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_resetting_does_not_reactivate_a_suspended_account(): void
    {
        $user = $this->user();
        $token = $this->requestToken();
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);

        $this->via('customer')->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD])
            ->assertStatus(422);
        $this->assertSame('suspended', DB::table('users')->where('id', $user->id)->value('status'));
    }
}
