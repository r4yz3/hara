<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

/**
 * ADR-018: `staff:bootstrap-owner`, the console-only way to create the first
 * platform owner on an environment where nothing can seed one.
 */
class BootstrapOwnerCommandTest extends AuthTestCase
{
    private const PASSWORD_CHOSEN = 'a-brand-new-passphrase';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{int, string}
     */
    private function runCommand(array $args = ['email' => 'first.owner@example.com']): array
    {
        $code = Artisan::call('staff:bootstrap-owner', $args);

        return [$code, Artisan::output()];
    }

    private function tokenFrom(string $output): string
    {
        $this->assertSame(1, preg_match('~http://localhost:3002/invite#token=([0-9a-f]{64})~', $output, $m), $output);

        return $m[1];
    }

    private function owners(): int
    {
        return DB::table('user_role_scopes as g')
            ->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('r.slug', 'platform_owner')->whereNull('g.revoked_at')->where('u.status', 'active')
            ->count();
    }

    /** @return TestResponse<JsonResponse> */
    private function accept(string $token, string $password = self::PASSWORD_CHOSEN): TestResponse
    {
        $this->via('admin')->getJson('/api/v1/auth/csrf')->assertOk();

        return $this->via('admin')->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => $password]);
    }

    /** @return list<string> */
    private function loggedText(): array
    {
        return array_map(fn (MessageLogged $e): string => $e->message.json_encode($e->context), $this->logged);
    }

    public function test_the_first_run_prints_a_fragment_link_to_the_admin_origin_and_creates_no_owner_yet(): void
    {
        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code, $out);
        $token = $this->tokenFrom($out);
        $this->assertStringNotContainsString('?', $out);
        $this->assertStringNotContainsString('?token', $out);

        $this->assertSame(0, $this->owners());
        $this->assertSame(0, DB::table('users')->count());
        $invite = DB::table('staff_invitations')->sole();
        $this->assertSame('first.owner@example.com', $invite->email);
        $this->assertTrue((bool) $invite->bootstrap);
        $this->assertNull($invite->invited_by);
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertSame('global', $invite->scope_type);
        Mail::assertNothingSent();
    }

    public function test_the_email_is_normalised(): void
    {
        [$code, $out] = $this->runCommand(['email' => '  First.Owner@Example.COM ']);

        $this->assertSame(0, $code, $out);
        $this->assertSame('first.owner@example.com', DB::table('staff_invitations')->value('email'));
    }

    public function test_a_second_run_refuses_while_the_first_link_is_live(): void
    {
        $this->runCommand();

        [$code, $out] = $this->runCommand(['email' => 'someone.else@example.com']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('pending', strtolower($out));
        $this->assertStringNotContainsString('#token=', $out);
        $this->assertSame(1, DB::table('staff_invitations')->count());
    }

    public function test_replace_revokes_the_pending_link_and_issues_a_new_one(): void
    {
        [, $first] = $this->runCommand();
        $old = $this->tokenFrom($first);

        [$code, $second] = $this->runCommand(['email' => 'first.owner@example.com', '--replace' => true]);

        $this->assertSame(0, $code, $second);
        $new = $this->tokenFrom($second);
        $this->assertNotSame($old, $new);
        $this->assertSame(1, DB::table('staff_invitations')->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('staff_invitations')->whereNotNull('revoked_at')->count());

        $this->accept($old)->assertStatus(404);
        $this->accept($new)->assertOk();
        $this->assertSame(1, $this->owners());
    }

    public function test_an_expired_link_does_not_block_a_new_run(): void
    {
        $this->runCommand();
        DB::table('staff_invitations')->update(['expires_at' => now()->subMinute()]);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code, $out);
    }

    public function test_it_refuses_when_an_active_owner_exists(): void
    {
        $this->grantRole($this->user('owner@example.com'), 'platform_owner');

        [$code, $out] = $this->runCommand();

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('platform owner already exists', strtolower($out));
        $this->assertSame(0, DB::table('staff_invitations')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'staff_bootstrap_owner.issued')->count());
    }

    public function test_replace_still_refuses_when_an_owner_exists(): void
    {
        $this->grantRole($this->user('owner@example.com'), 'platform_owner');

        [$code] = $this->runCommand(['email' => 'x@example.com', '--replace' => true]);

        $this->assertNotSame(0, $code);
    }

    public function test_a_suspended_or_revoked_owner_does_not_count(): void
    {
        $suspended = $this->user('suspended@example.com');
        $this->grantRole($suspended, 'platform_owner');
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'suspended']);
        $revoked = $this->user('revoked@example.com');
        $this->grantRole($revoked, 'platform_owner');
        DB::table('user_role_scopes')->where('user_id', $revoked->id)->update(['revoked_at' => now()]);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code, $out);
    }

    public function test_a_non_owner_staff_member_does_not_block_it(): void
    {
        $this->grantRole($this->user('admin@example.com'), 'platform_admin');

        [$code] = $this->runCommand();

        $this->assertSame(0, $code);
    }

    /** @return array<string, array{string}> */
    public static function badEmails(): array
    {
        return [
            'empty' => [''],
            'no at' => ['nobody'],
            'no domain' => ['a@'],
            'spaces' => ['a b@example.com'],
            'long' => [str_repeat('a', 250).'@example.com'],
        ];
    }

    #[DataProvider('badEmails')]
    public function test_an_invalid_email_is_rejected_and_nothing_is_stored(string $email): void
    {
        [$code, $out] = $this->runCommand(['email' => $email]);

        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('#token=', $out);
        $this->assertSame(0, DB::table('staff_invitations')->count());
    }

    public function test_the_invitation_is_accepted_through_the_normal_flow_and_makes_an_owner(): void
    {
        [, $out] = $this->runCommand();
        $token = $this->tokenFrom($out);

        $this->via('admin')->getJson('/api/v1/auth/csrf');
        $this->via('admin')->postJson('/api/v1/auth/invitations/inspect', ['token' => $token])->assertOk()
            ->assertJsonPath('data.role_label', 'Platform owner');
        $this->accept($token, 'short')->assertStatus(422);
        $this->accept($token)->assertOk();

        $this->assertSame(1, $this->owners());
        $user = DB::table('users')->where('email', 'first.owner@example.com')->sole();
        $this->assertNotSame(self::PASSWORD_CHOSEN, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull(DB::table('staff_invitations')->value('accepted_at'));
        $this->login('admin', 'first.owner@example.com', self::PASSWORD_CHOSEN)->assertOk();

        // Used once.
        $this->accept($token)->assertStatus(404);
        $this->assertSame(1, $this->owners());

        // The command now refuses.
        [$code] = $this->runCommand(['email' => 'another@example.com']);
        $this->assertNotSame(0, $code);
    }

    public function test_two_links_cannot_make_two_owners(): void
    {
        [, $out] = $this->runCommand();
        $token = $this->tokenFrom($out);
        // An owner appears between issuing and accepting.
        $this->grantRole($this->user('owner@example.com'), 'platform_owner');

        $this->accept($token)->assertStatus(404);

        $this->assertSame(1, $this->owners());
        $this->assertSame(0, DB::table('users')->where('email', 'first.owner@example.com')->count());
        $this->assertNull(DB::table('staff_invitations')->value('accepted_at'));
    }

    public function test_an_existing_account_must_prove_its_password_and_keeps_it(): void
    {
        $existing = User::factory()->create(['email' => 'first.owner@example.com', 'password' => self::PASSWORD]);
        [, $out] = $this->runCommand();
        $token = $this->tokenFrom($out);

        $this->accept($token)->assertStatus(404);
        $this->assertSame(0, $this->owners());
        $this->accept($token, self::PASSWORD)->assertOk();
        $this->assertSame(1, $this->owners());
        $this->login('admin', 'first.owner@example.com')->assertOk();
        $this->assertSame($existing->id, DB::table('users')->where('email', 'first.owner@example.com')->value('id'));
    }

    public function test_the_audit_records_are_by_the_console_and_hold_no_token_or_address(): void
    {
        [, $out] = $this->runCommand();
        $token = $this->tokenFrom($out);
        $this->accept($token)->assertOk();

        $issued = DB::table('audit_logs')->where('action', 'staff_bootstrap_owner.issued')->sole();
        $this->assertNull($issued->actor_user_id);
        $this->assertSame('system/console', json_decode((string) $issued->after_json, true)['actor']);
        $granted = DB::table('audit_logs')->where('action', 'role.granted')->sole();
        $this->assertNull($granted->actor_user_id);
        $this->assertSame('system/console', json_decode((string) $granted->after_json, true)['actor']);
        $this->assertSame('bootstrap', json_decode((string) $granted->after_json, true)['via']);

        $everything = json_encode(DB::table('audit_logs')->get()).json_encode($this->loggedText());
        $this->assertStringNotContainsString($token, $everything);
        $this->assertStringNotContainsString('first.owner@example.com', $everything);
        $this->assertStringNotContainsString(self::PASSWORD_CHOSEN, $everything);
    }

    public function test_the_token_never_reaches_the_log(): void
    {
        [, $out] = $this->runCommand();
        $token = $this->tokenFrom($out);

        $this->assertStringNotContainsString($token, json_encode($this->loggedText()));
    }

    public function test_it_adds_no_http_route(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('bootstrap', $route->uri());
        }
    }
}
