<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/public/config (spec 7.2): what any client may know about this
 * deployment before signing in. Nothing here is a secret.
 */
class PublicConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_show_nothing_open_and_everything_off(): void
    {
        config(['platform.map_style_url' => null]);

        $response = $this->getJson('/api/v1/public/config')->assertOk();

        $this->assertSame(['currency', 'display_timezone', 'service_open', 'map_style_url', 'features'], array_keys($response->json('data')));
        $response->assertJsonPath('data.currency', config('platform.currency'))
            ->assertJsonPath('data.service_open', false)
            ->assertJsonPath('data.map_style_url', null)
            ->assertJsonPath('data.features', [
                'public_registration' => false,
                'live_dispatch' => false,
                'online_payments' => false,
                'rider_tracking' => false,
            ]);
    }

    public function test_service_is_open_only_with_an_active_zone_in_an_active_city(): void
    {
        $city = City::factory()->create();
        $insert = fn (string $slug, string $status) => DB::insert(
            "INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, 'Z', ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))",
            [str_pad($slug, 26, '0'), $city->id, $slug, $status, '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}'],
        );

        $insert('a', 'active');
        $this->getJson('/api/v1/public/config')->assertJsonPath('data.service_open', false);

        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);
        $this->getJson('/api/v1/public/config')->assertJsonPath('data.service_open', true);

        DB::table('delivery_zones')->update(['status' => 'paused']);
        $this->getJson('/api/v1/public/config')->assertJsonPath('data.service_open', false);
    }

    public function test_flags_and_map_style_follow_configuration(): void
    {
        config(['features.public_registration' => true, 'platform.map_style_url' => 'https://tiles.example.test/style.json']);

        $this->getJson('/api/v1/public/config')
            ->assertJsonPath('data.features.public_registration', true)
            ->assertJsonPath('data.features.online_payments', false)
            ->assertJsonPath('data.map_style_url', 'https://tiles.example.test/style.json');
    }

    public function test_a_map_style_that_is_not_an_https_url_is_withheld(): void
    {
        foreach (['javascript:alert(1)', 'http://tiles.example.test/style.json', 'not a url', ''] as $value) {
            config(['platform.map_style_url' => $value]);
            $this->getJson('/api/v1/public/config')->assertJsonPath('data.map_style_url', null);
        }
    }

    public function test_it_exposes_no_secret_or_internal_setting(): void
    {
        $body = (string) $this->getJson('/api/v1/public/config')->getContent();

        foreach (['key', 'password', 'secret', 'token', 'debug', 'database', 'redis', 'demo'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, str_replace('request_id', '', $body));
        }
    }
}
