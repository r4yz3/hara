<?php

namespace Tests\Feature;

use App\Application\Commands\AuditLogger;
use App\Models\City;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Backlog E-01: the named events of spec 11.3 are emitted with the request
 * id, and exact positions never reach the log. Shapes and positions are
 * synthetic (near longitude 100, latitude 10).
 */
class StructuredLogTest extends AuthTestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
    }

    /** @return list<MessageLogged> */
    private function events(string $name): array
    {
        return array_values(array_filter($this->logged, fn (MessageLogged $e): bool => $e->message === $name));
    }

    private function staff(string $role, ?City $city = null): User
    {
        $user = $this->user('staff@example.com');
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', 'staff@example.com')->assertOk();

        return $user;
    }

    private function activeCity(): City
    {
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);

        return $city;
    }

    public function test_every_request_logs_http_request_with_its_id_and_route_template(): void
    {
        $response = $this->postJson('/api/v1/locations/serviceability', ['latitude' => '10.0123', 'longitude' => '100.0456'])->assertOk();

        $events = $this->events('http.request');
        $this->assertCount(1, $events);
        $context = $events[0]->context;
        $this->assertSame($response->headers->get('X-Request-Id'), $context['request_id']);
        $this->assertSame('POST', $context['method']);
        $this->assertSame('api/v1/locations/serviceability', $context['route']);
        $this->assertSame(200, $context['status']);
        $this->assertIsFloat($context['duration_ms']);
    }

    public function test_no_log_record_carries_the_position_that_was_checked(): void
    {
        $this->postJson('/api/v1/locations/serviceability', ['latitude' => '10.0123', 'longitude' => '100.0456'])->assertOk();

        $this->assertNotEmpty($this->logged);
        $all = json_encode(array_map(fn (MessageLogged $e): array => [$e->message, $e->context], $this->logged), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('10.0123', $all);
        $this->assertStringNotContainsString('100.0456', $all);
    }

    public function test_the_route_template_hides_ids_from_the_path(): void
    {
        $this->staff('platform_owner');
        $city = $this->activeCity();

        $this->via('admin')->getJson("/api/v1/admin/cities/{$city->id}")->assertOk();

        $route = array_column(array_map(fn (MessageLogged $e): array => $e->context, $this->events('http.request')), 'route');
        $this->assertContains('api/v1/admin/cities/{city}', $route);
        $this->assertStringNotContainsString($city->id, json_encode($route, JSON_THROW_ON_ERROR));
    }

    public function test_a_permission_refusal_logs_rbac_denied(): void
    {
        $city = $this->activeCity();
        $other = $this->activeCity();
        $user = $this->staff('city_manager', $city);

        $this->via('admin')->getJson("/api/v1/admin/cities/{$other->id}")->assertNotFound();

        $denied = $this->events('rbac.denied');
        $this->assertNotEmpty($denied);
        $this->assertSame('warning', $denied[0]->level);
        $this->assertSame($user->id, $denied[0]->context['user_id']);
        $this->assertSame('city', $denied[0]->context['scope_type']);
        $this->assertSame($other->id, $denied[0]->context['scope_id']);
        $this->assertArrayHasKey('request_id', $denied[0]->context);
    }

    public function test_a_city_change_logs_city_updated_after_commit(): void
    {
        $owner = $this->staff('platform_owner');
        $city = $this->activeCity();

        $this->via('admin')->patchJson("/api/v1/admin/cities/{$city->id}", ['name' => 'Renamed', 'version' => 1])->assertOk();

        $events = $this->events('city.updated');
        $this->assertCount(1, $events);
        $this->assertSame($owner->id, $events[0]->context['actor_user_id']);
        $this->assertSame($city->id, $events[0]->context['subject_id']);
        $this->assertArrayNotHasKey('after', $events[0]->context);
    }

    public function test_an_audit_entry_rolled_back_is_never_logged(): void
    {
        try {
            DB::transaction(function (): void {
                app(AuditLogger::class)->record(null, 'city.updated', 'city', 'x');
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->events('city.updated'));
    }

    public function test_activating_a_zone_logs_zone_activated(): void
    {
        $this->staff('platform_owner');
        $city = $this->activeCity();
        $zone = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$zone, $city->id, 'Zone', 'z-'.$zone, 'draft', '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}'],
        );

        $this->via('admin')->postJson("/api/v1/admin/zones/{$zone}/activate", ['version' => 1, 'confirm' => true])->assertOk();

        $events = $this->events('zone.activated');
        $this->assertCount(1, $events);
        $this->assertSame($zone, $events[0]->context['zone_id']);
        $this->assertSame($city->id, $events[0]->context['city_id']);
    }

    public function test_a_rejected_shape_logs_zone_validation_failed_without_coordinates(): void
    {
        $this->staff('platform_owner');
        $city = $this->activeCity();
        $open = ['type' => 'Polygon', 'coordinates' => [[[100, 10], [100.02, 10], [100.02, 10.02], [100.0177, 10.0199]]]];

        $this->via('admin')->postJson("/api/v1/admin/cities/{$city->id}/zones", ['name' => 'N', 'slug' => 'n', 'priority' => 0, 'geometry' => $open])
            ->assertStatus(422);

        $events = $this->events('zone.validation_failed');
        $this->assertCount(1, $events);
        $this->assertSame('zone', $events[0]->context['shape']);
        $this->assertIsString($events[0]->context['reason']);
        $this->assertStringNotContainsString('100.0177', json_encode($events[0]->context, JSON_THROW_ON_ERROR));
    }

    public function test_the_structured_channel_writes_json_and_redacts_positions_and_secrets(): void
    {
        $path = storage_path('logs/structured-test-'.Str::random(8).'.log');

        try {
            // The real `single` channel with only its path changed: taps apply
            // to configured channels, not to Log::build().
            config(['logging.channels.structured_probe' => ['path' => $path] + config('logging.channels.single')]);
            Log::channel('structured_probe')->info('probe', ['user_id' => 'u1', 'latitude' => 10.5, 'nested' => ['password' => 'p', 'lng' => 100.5]]);

            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));
            $this->assertCount(1, $lines);
            $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('probe', $record['message']);
            $this->assertSame('u1', $record['context']['user_id']);
            $this->assertSame('[redacted]', $record['context']['latitude']);
            $this->assertSame('[redacted]', $record['context']['nested']['password']);
            $this->assertSame('[redacted]', $record['context']['nested']['lng']);
        } finally {
            @unlink($path);
        }
    }
}
