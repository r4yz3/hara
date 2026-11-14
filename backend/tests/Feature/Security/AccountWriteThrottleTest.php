<?php

namespace Tests\Feature\Security;

use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Threat model T31: the signed-in profile and address write routes share one bucket per account
 * (30 writes a minute), keyed by user id and not by address. Positions are synthetic.
 */
class AccountWriteThrottleTest extends AuthTestCase
{
    private const SQUARE = '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}';

    private const LIMIT = 30;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('account-writes');
        $city = City::factory()->create(['name' => 'Sampleville']);
        DB::table('cities')->where('id', $city->id)->update(['status' => 'active']);
        $zone = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$zone, $city->id, 'Zone', 'z-'.$zone, 'active', self::SQUARE],
        );
        $this->user('alice@example.com');
        $this->user('bob@example.com');
    }

    private function signIn(string $email): void
    {
        $this->app['auth']->forgetGuards();
        $this->login('customer', $email)->assertOk();
    }

    /** @return TestResponse<JsonResponse> */
    private function rename(): TestResponse
    {
        return $this->via('customer')->patchJson('/api/v1/me', ['name' => 'Alice Sample']);
    }

    /** @return TestResponse<JsonResponse> */
    private function createAddress(): TestResponse
    {
        return $this->via('customer')->postJson('/api/v1/me/addresses', [
            'address_line' => '12 Sample Street, Barangay Testing', 'landmark' => 'Blue gate beside the store', 'tag' => 'Home', 'latitude' => '10.0123', 'longitude' => '100.0156',
        ]);
    }

    /** @param TestResponse<JsonResponse> $response */
    private function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        $this->assertStringNotContainsString('Alice Sample', (string) $response->getContent());
        $this->assertStringNotContainsString('Sample Street', (string) $response->getContent());
    }

    private function burn(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->rename()->assertOk();
        }
    }

    public function test_the_write_after_the_limit_answers_429_with_retry_after(): void
    {
        $this->signIn('alice@example.com');
        $this->burn(self::LIMIT);

        $this->assertThrottled($this->rename());
    }

    public function test_each_account_has_its_own_bucket_even_from_one_address(): void
    {
        $this->signIn('alice@example.com');
        $this->burn(self::LIMIT);
        $this->assertThrottled($this->rename());

        $this->signIn('bob@example.com');
        $this->burn(self::LIMIT);
        $this->assertThrottled($this->rename());
    }

    public function test_reads_are_not_counted(): void
    {
        $this->signIn('alice@example.com');
        for ($i = 0; $i < self::LIMIT + 10; $i++) {
            $this->via('customer')->getJson('/api/v1/me')->assertOk();
            $this->via('customer')->getJson('/api/v1/me/addresses')->assertOk();
        }

        $this->burn(self::LIMIT);
        $this->assertThrottled($this->rename());
    }

    public function test_the_bucket_resets_after_the_window(): void
    {
        $this->signIn('alice@example.com');
        $this->burn(self::LIMIT);
        $this->assertThrottled($this->rename());

        $this->travel(61)->seconds();

        $this->rename()->assertOk();
    }

    public function test_the_limit_is_shared_across_the_five_routes(): void
    {
        $this->signIn('alice@example.com');
        $id = (string) $this->createAddress()->assertCreated()->json('data.id');
        $this->burn(19); // 20 writes so far
        for ($i = 0; $i < 10; $i++) {
            $this->via('customer')->postJson("/api/v1/me/addresses/{$id}/default")->assertOk();
        }

        $this->assertThrottled($this->rename());
        $this->assertThrottled($this->createAddress());
        $this->assertThrottled($this->via('customer')->patchJson("/api/v1/me/addresses/{$id}", ['tag' => 'Work']));
        $this->assertThrottled($this->via('customer')->postJson("/api/v1/me/addresses/{$id}/default"));
        $this->assertThrottled($this->via('customer')->deleteJson("/api/v1/me/addresses/{$id}"));
        $this->assertNotNull(DB::table('saved_addresses')->where('id', $id)->first());
    }
}
