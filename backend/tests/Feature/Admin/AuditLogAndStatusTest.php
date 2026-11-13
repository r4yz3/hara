<?php

namespace Tests\Feature\Admin;

use App\Application\Commands\AuditLogger;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\AuthTestCase;

/**
 * GET /admin/audit-logs and GET /admin/system/status (spec 7.4, backlog C-10).
 */
class AuditLogAndStatusTest extends AuthTestCase
{
    private function staff(string $role, ?City $city = null, string $email = 'staff@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    /** Clears rows written by sign-in and grants so each test counts its own. */
    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /** @return list<string> */
    private function actions(string $query = ''): array
    {
        $response = $this->via('admin')->getJson('/api/v1/admin/audit-logs'.$query)->assertOk();

        return array_column($response->json('data'), 'action');
    }

    public function test_both_routes_need_a_session_and_the_admin_app(): void
    {
        $this->via('admin')->getJson('/api/v1/admin/audit-logs')->assertStatus(401);
        $this->via('admin')->getJson('/api/v1/admin/system/status')->assertStatus(401);

        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();
        $this->via('customer')->getJson('/api/v1/admin/audit-logs')->assertStatus(404);
        $this->via('customer')->getJson('/api/v1/admin/system/status')->assertStatus(404);
    }

    public function test_staff_without_the_permission_are_refused(): void
    {
        $city = City::factory()->create();
        $this->staff('city_manager', $city);

        $this->via('admin')->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
        $this->via('admin')->getJson('/api/v1/admin/system/status')->assertStatus(403);
    }

    public function test_owner_reads_entries_newest_first_with_safe_fields_only(): void
    {
        $owner = $this->staff('platform_owner');
        $city = City::factory()->create();
        $this->audit()->record($owner->id, 'test.first', 'thing', null, $city->id, after: ['name' => 'A'], ip: '203.0.113.9');
        $this->audit()->record($owner->id, 'test.second', 'thing', null, null, before: ['name' => 'A'], after: ['name' => 'B']);

        $response = $this->via('admin')->getJson('/api/v1/admin/audit-logs?limit=2')->assertOk();

        $this->assertSame(['test.second', 'test.first'], array_column($response->json('data'), 'action'));
        $this->assertSame(
            ['id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'city_id', 'before', 'after', 'correlation_id', 'created_at'],
            array_keys($response->json('data.0')),
        );
        $response->assertJsonPath('data.0.before', ['name' => 'A'])->assertJsonPath('data.0.after', ['name' => 'B']);
        $this->assertStringNotContainsString('ip_hash', (string) $response->getContent());
        $this->assertStringNotContainsString('203.0.113.9', (string) $response->getContent());
    }

    public function test_sensitive_values_are_redacted_even_if_stored_raw(): void
    {
        $owner = $this->staff('platform_owner');
        // Written around the logger, as a bug or an old row might be.
        DB::table('audit_logs')->insert([
            'id' => '01zzzzzzzzzzzzzzzzzzzzzzzz', 'actor_user_id' => $owner->id, 'action' => 'test.raw', 'subject_type' => 'thing',
            'after_json' => json_encode(['password' => 'hunter2', 'nested' => ['api_token' => 'abc123'], 'name' => 'kept']),
        ]);

        $body = (string) $this->via('admin')->getJson('/api/v1/admin/audit-logs?action=test.raw')->assertOk()->getContent();

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('abc123', $body);
        $this->assertStringContainsString('kept', $body);
    }

    public function test_filters_and_paging(): void
    {
        $owner = $this->staff('platform_owner');
        $city = City::factory()->create();
        $subject = '01aaaaaaaaaaaaaaaaaaaaaaaa';
        $this->audit()->record($owner->id, 'test.a', 'widget', $subject, $city->id);
        $this->audit()->record(null, 'test.b', 'widget', null, null);
        $this->audit()->record($owner->id, 'test.c', 'gadget', null, $city->id);

        $this->assertSame(['test.b'], $this->actions('?action=test.b'));
        $this->assertSame(['test.b', 'test.a'], $this->actions('?subject_type=widget'));
        $this->assertSame(['test.a'], $this->actions("?subject_type=widget&subject_id={$subject}"));
        $this->assertSame(['test.c', 'test.a'], array_values(array_filter($this->actions("?city_id={$city->id}"), fn (string $a): bool => str_starts_with($a, 'test.'))));
        $this->assertNotContains('test.b', $this->actions("?actor_user_id={$owner->id}"));

        $first = $this->via('admin')->getJson('/api/v1/admin/audit-logs?subject_type=widget&limit=1')->assertOk();
        $this->assertSame(['test.b'], array_column($first->json('data'), 'action'));
        $cursor = (string) $first->json('meta.next_cursor');
        $this->assertSame(['test.a'], $this->actions("?subject_type=widget&limit=1&cursor={$cursor}"));

        $this->via('admin')->getJson('/api/v1/admin/audit-logs?city_id=nope')->assertStatus(422);
        $this->via('admin')->getJson('/api/v1/admin/audit-logs?cursor=%%%')->assertStatus(422);
    }

    public function test_a_city_scoped_admin_sees_only_their_city(): void
    {
        $mine = City::factory()->create();
        $other = City::factory()->create();
        $this->audit()->record(null, 'test.mine', 'thing', null, $mine->id);
        $this->audit()->record(null, 'test.other', 'thing', null, $other->id);
        $this->audit()->record(null, 'test.platform', 'thing', null, null);

        $this->staff('platform_admin', $mine);

        $this->assertSame(['test.mine'], $this->actions('?subject_type=thing'));
        // Asking for another city by id returns nothing, not an error that confirms it exists.
        $this->assertSame([], $this->actions("?city_id={$other->id}"));
        // City-scoped staff do not get the deployment-wide status page.
        $this->via('admin')->getJson('/api/v1/admin/system/status')->assertStatus(403);
    }

    public function test_system_status_reports_health_and_counts_without_secrets(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);
        City::factory()->create();

        $response = $this->via('admin')->getJson('/api/v1/admin/system/status')->assertOk();

        $this->assertSame(
            ['environment', 'debug', 'checks', 'postgis', 'cities', 'zones', 'features', 'map_style_configured', 'geometry_caps_approved'],
            array_keys($response->json('data')),
        );
        $response->assertJsonPath('data.checks.database', true)
            ->assertJsonPath('data.cities', ['draft' => 1, 'active' => 1, 'paused' => 0, 'archived' => 0])
            ->assertJsonPath('data.zones', ['draft' => 0, 'active' => 0, 'paused' => 0, 'archived' => 0])
            ->assertJsonPath('data.features.online_payments', false)
            ->assertJsonPath('data.geometry_caps_approved', false);
        $this->assertTrue($response->json('data.postgis'));

        $body = strtolower((string) $response->getContent());
        foreach (['password', 'secret', 'app_key', 'base64:', (string) config('database.connections.pgsql.host')] as $word) {
            $this->assertStringNotContainsString(strtolower($word), $body);
        }
    }
}
