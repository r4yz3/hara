<?php

namespace Tests\Feature\Customer;

use App\Http\Middleware\EnsureCustomerSurface;
use App\Http\Middleware\EnsureSurfaceSession;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Saved delivery addresses (customer surface). A saved address is a person's
 * exact home position: these tests also prove it never reaches a URL, a log
 * line or an audit row. Positions are synthetic (near longitude 100, latitude
 * 10); no real place is used.
 *
 * CrossTenantIsolationTest covers admin routes only, so the ownership rules
 * for these /me routes are proved here.
 */
class SavedAddressTest extends AuthTestCase
{
    private const SQUARE = '{"type":"Polygon","coordinates":[[[100,10],[100.02,10],[100.02,10.02],[100,10.02],[100,10]]]}';

    private const OTHER_SQUARE = '{"type":"Polygon","coordinates":[[[110,10],[110.02,10],[110.02,10.02],[110,10.02],[110,10]]]}';

    private const BASE = '/api/v1/me/addresses';

    private User $alice;

    private City $city;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = $e;
        });
        $this->alice = $this->user('alice@example.com');
        $this->city = $this->coverage('Sampleville', self::SQUARE);
    }

    private function coverage(string $name, string $geoJson, string $zoneStatus = 'active', string $cityStatus = 'active'): City
    {
        $city = City::factory()->create(['name' => $name]);
        DB::table('cities')->where('id', $city->id)->update(['status' => $cityStatus]);
        $zone = strtolower((string) Str::ulid());
        DB::insert(
            'INSERT INTO delivery_zones (id, city_id, name, slug, status, coverage) VALUES (?, ?, ?, ?, ?, ST_Multi(ST_GeomFromGeoJSON(?)))',
            [$zone, $city->id, 'Zone', 'z-'.$zone, $zoneStatus, $geoJson],
        );

        return $city;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return $override + [
            'address_line' => '12 Sample Street, Barangay Testing',
            'landmark' => 'Blue gate beside the sari-sari store',
            'tag' => 'Home',
            'latitude' => '10.0123',
            'longitude' => '100.0156',
        ];
    }

    private function signIn(string $email = 'alice@example.com', string $surface = 'customer'): void
    {
        $this->app['auth']->forgetGuards();
        $this->login($surface, $email)->assertOk();
    }

    /** @param array<string, mixed> $override */
    private function create(array $override = []): string
    {
        return (string) $this->via('customer')->postJson(self::BASE, $this->payload($override))->assertCreated()->json('data.id');
    }

    private function row(string $id): ?object
    {
        return DB::table('saved_addresses')->where('id', $id)->first();
    }

    /** @return list<string> */
    private function defaults(): array
    {
        return DB::table('saved_addresses')->where('is_default', true)->where('user_id', $this->alice->id)->orderBy('id')->pluck('id')->all();
    }

    // ---- create / read ----

    public function test_a_customer_creates_an_address_and_reads_it_back(): void
    {
        $this->signIn();

        $response = $this->via('customer')->postJson(self::BASE, $this->payload())->assertCreated();

        $data = $response->json('data');
        $this->assertSame(
            ['id', 'tag', 'address_line', 'landmark', 'latitude', 'longitude', 'is_default', 'city', 'serviceable', 'created_at', 'updated_at'],
            array_keys($data),
        );
        $this->assertSame(['id' => $this->city->id, 'name' => 'Sampleville'], $data['city']);
        $this->assertTrue($data['serviceable']);
        $this->assertSame(10.0123, $data['latitude']);
        $this->assertSame(100.0156, $data['longitude']);
        $this->assertSame('Home', $data['tag']);
        $this->assertSame(26, strlen($data['id']));
        $this->assertSame($data['id'], strtolower($data['id']));

        $this->assertSame($this->alice->id, $this->row($data['id'])->user_id);
        $this->assertSame($this->city->id, $this->row($data['id'])->city_id);

        $this->via('customer')->getJson(self::BASE)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $data['id']);
    }

    public function test_the_point_is_stored_longitude_first(): void
    {
        $this->signIn();
        $id = $this->create(['latitude' => 10.0123, 'longitude' => 100.0456]);

        $stored = DB::selectOne('SELECT ST_X(point) AS x, ST_Y(point) AS y, ST_SRID(point) AS srid FROM saved_addresses WHERE id = ?', [$id]);
        $this->assertEqualsWithDelta(100.0456, $stored->x, 1e-9);
        $this->assertEqualsWithDelta(10.0123, $stored->y, 1e-9);
        $this->assertSame(4326, $stored->srid);
    }

    public function test_the_response_rounds_to_six_decimals_and_user_id_is_never_returned(): void
    {
        $this->signIn();
        $response = $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => '10.01234567891', 'longitude' => '100.04567891234']))
            ->assertCreated();

        $this->assertSame(10.012346, $response->json('data.latitude'));
        $this->assertSame(100.045679, $response->json('data.longitude'));
        $this->assertStringNotContainsString($this->alice->id, (string) $response->getContent());
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
        $this->assertArrayNotHasKey('city_id', $response->json('data'));
    }

    public function test_numbers_and_strings_are_both_accepted_for_the_position(): void
    {
        $this->signIn();
        $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => 10.5, 'longitude' => 100.5]))->assertCreated();
        $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => '10.5', 'longitude' => '100.5']))->assertCreated();
    }

    public function test_tag_is_optional_and_text_is_trimmed(): void
    {
        $this->signIn();
        $payload = $this->payload(['address_line' => "  1 Test Lane \t", 'landmark' => '  Near the chapel  ']);
        unset($payload['tag']);

        $this->via('customer')->postJson(self::BASE, $payload)->assertCreated()
            ->assertJsonPath('data.tag', null)
            ->assertJsonPath('data.address_line', '1 Test Lane')
            ->assertJsonPath('data.landmark', 'Near the chapel');
    }

    public function test_length_limits(): void
    {
        $this->signIn();
        $this->via('customer')->postJson(self::BASE, $this->payload(['address_line' => str_repeat('a', 500), 'landmark' => str_repeat('b', 255), 'tag' => str_repeat('c', 40)]))->assertCreated();
        $this->via('customer')->postJson(self::BASE, $this->payload(['address_line' => str_repeat('a', 501)]))->assertStatus(422)->assertJsonValidationErrors('address_line', 'error.fields');
        $this->via('customer')->postJson(self::BASE, $this->payload(['landmark' => str_repeat('b', 256)]))->assertStatus(422)->assertJsonValidationErrors('landmark', 'error.fields');
        $this->via('customer')->postJson(self::BASE, $this->payload(['tag' => str_repeat('c', 41)]))->assertStatus(422)->assertJsonValidationErrors('tag', 'error.fields');
        // Trimming comes first: padding does not count.
        $this->via('customer')->postJson(self::BASE, $this->payload(['landmark' => '  '.str_repeat('b', 255).'  ']))->assertCreated();
    }

    #[TestWith(['address_line'])]
    #[TestWith(['landmark'])]
    #[TestWith(['latitude'])]
    #[TestWith(['longitude'])]
    public function test_required_fields_are_required(string $field): void
    {
        $this->signIn();
        $payload = $this->payload();
        unset($payload[$field]);

        $this->via('customer')->postJson(self::BASE, $payload)->assertStatus(422)->assertJsonValidationErrors($field, 'error.fields');
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    #[TestWith(['address_line', ''])]
    #[TestWith(['address_line', '   '])]
    #[TestWith(['landmark', "  \t "])]
    #[TestWith(['landmark', null])]
    #[TestWith(['address_line', ['array']])]
    #[TestWith(['landmark', 12])]
    public function test_blank_or_wrongly_typed_text_is_refused(string $field, mixed $value): void
    {
        $this->signIn();

        $this->via('customer')->postJson(self::BASE, $this->payload([$field => $value]))->assertStatus(422)->assertJsonValidationErrors($field, 'error.fields');
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    #[TestWith(['address_line', "1 Test\nLane"])]
    #[TestWith(['address_line', "1 Test\tLane"])]
    #[TestWith(['landmark', "Ga\x00te"])]
    #[TestWith(['landmark', "Gate\x1b[0m"])]
    #[TestWith(['tag', "Ho\u{202E}me"])]
    #[TestWith(['tag', "Ho\u{200B}me"])]
    #[TestWith(['address_line', "1 Test\u{FEFF}Lane"])]
    #[TestWith(['landmark', "Gate\u{2066}"])]
    public function test_control_and_invisible_format_characters_are_refused(string $field, string $value): void
    {
        $this->signIn();

        $this->via('customer')->postJson(self::BASE, $this->payload([$field => $value]))->assertStatus(422)->assertJsonValidationErrors($field, 'error.fields');
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    public function test_accents_and_joiner_emoji_are_accepted(): void
    {
        $this->signIn();
        $this->via('customer')->postJson(self::BASE, $this->payload(['address_line' => '7 Calle Niño, Poblacion', 'tag' => "Mama \u{1F469}\u{200D}\u{1F373}"]))->assertCreated();
    }

    #[TestWith(['91', '100'])]
    #[TestWith(['-90.0001', '100'])]
    #[TestWith(['10', '180.5'])]
    #[TestWith(['10', '-181'])]
    #[TestWith(['abc', '100'])]
    #[TestWith(['1e1', '100'])]
    #[TestWith(['10', 'NaN'])]
    #[TestWith([['10'], '100'])]
    #[TestWith([null, '100'])]
    #[TestWith([true, '100'])]
    #[TestWith(['10.1234567890123', '100'])]
    public function test_invalid_positions_are_refused(mixed $latitude, mixed $longitude): void
    {
        $this->signIn();

        $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => $latitude, 'longitude' => $longitude]))->assertStatus(422);
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    public function test_a_position_in_the_query_string_is_refused(): void
    {
        $this->signIn();

        $this->via('customer')->postJson(self::BASE.'?latitude=10.01&longitude=100.04', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude'], 'error.fields');
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    public function test_city_id_and_user_id_from_the_client_are_ignored(): void
    {
        $this->signIn();
        $other = $this->coverage('Elsewhere', self::OTHER_SQUARE);
        $bob = $this->user('bob@example.com');

        $id = $this->create(['city_id' => $other->id, 'user_id' => $bob->id, 'id' => strtolower((string) Str::ulid())]);

        $this->assertSame($this->city->id, $this->row($id)->city_id);
        $this->assertSame($this->alice->id, $this->row($id)->user_id);
    }

    // ---- city and serviceable ----

    public function test_an_address_outside_coverage_has_no_city_and_is_not_serviceable(): void
    {
        $this->signIn();

        $response = $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => '11.5', 'longitude' => '101.5']))->assertCreated();

        $response->assertJsonPath('data.city', null)->assertJsonPath('data.serviceable', false);
        $this->assertNull($this->row($response->json('data.id'))->city_id);
    }

    public function test_a_draft_zone_or_inactive_city_gives_no_city(): void
    {
        $this->signIn();
        $this->coverage('Drafty', '{"type":"Polygon","coordinates":[[[120,10],[120.02,10],[120.02,10.02],[120,10.02],[120,10]]]}', zoneStatus: 'draft');
        $this->coverage('Sleepy', '{"type":"Polygon","coordinates":[[[130,10],[130.02,10],[130.02,10.02],[130,10.02],[130,10]]]}', cityStatus: 'draft');

        $this->via('customer')->postJson(self::BASE, $this->payload(['longitude' => '120.01']))->assertCreated()->assertJsonPath('data.city', null);
        $this->via('customer')->postJson(self::BASE, $this->payload(['longitude' => '130.01']))->assertCreated()->assertJsonPath('data.city', null);
    }

    public function test_serviceable_reflects_current_coverage_not_coverage_at_write_time(): void
    {
        $this->signIn();
        $id = $this->create();
        $this->via('customer')->getJson(self::BASE)->assertJsonPath('data.0.serviceable', true);

        DB::table('delivery_zones')->where('city_id', $this->city->id)->update(['status' => 'paused']);

        $listed = $this->via('customer')->getJson(self::BASE)->assertOk();
        $listed->assertJsonPath('data.0.serviceable', false);
        // The city is the one derived when the address was written.
        $listed->assertJsonPath('data.0.city.id', $this->city->id);
        $this->assertSame($this->city->id, $this->row($id)->city_id);
    }

    public function test_an_address_added_while_uncovered_has_no_city_but_is_serviceable_once_covered(): void
    {
        $this->signIn();
        DB::table('delivery_zones')->update(['status' => 'draft']);
        $id = $this->create();
        DB::table('delivery_zones')->update(['status' => 'active']);

        $this->via('customer')->getJson(self::BASE)
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.city', null)->assertJsonPath('data.0.serviceable', true);
    }

    // ---- list ----

    public function test_the_list_shows_the_default_first_then_newest(): void
    {
        $this->signIn();
        $first = $this->create(['tag' => 'First']);
        $second = $this->create(['tag' => 'Second']);
        $third = $this->create(['tag' => 'Third']);
        // The first address became the default automatically.
        $this->assertSame([$first, $third, $second], array_column($this->via('customer')->getJson(self::BASE)->json('data'), 'id'));

        $this->via('customer')->postJson(self::BASE."/{$second}/default")->assertOk();

        $this->assertSame([$second, $third, $first], array_column($this->via('customer')->getJson(self::BASE)->json('data'), 'id'));
    }

    public function test_the_list_holds_only_the_callers_addresses(): void
    {
        $this->user('bob@example.com');
        $this->signIn('bob@example.com');
        $bobs = $this->create(['address_line' => 'Bob only street']);
        $this->signIn();
        $mine = $this->create();

        $ids = array_column($this->via('customer')->getJson(self::BASE)->json('data'), 'id');
        $this->assertSame([$mine], $ids);
        $this->assertNotContains($bobs, $ids);
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $this->signIn();

        $response = $this->via('customer')->getJson(self::BASE)->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    // ---- the limit of 20 ----

    public function test_a_person_may_hold_twenty_addresses_and_not_a_twenty_first(): void
    {
        $this->signIn();
        for ($i = 0; $i < 20; $i++) {
            $this->create(['tag' => "Spot {$i}"]);
        }

        $this->via('customer')->postJson(self::BASE, $this->payload())->assertStatus(422);
        $this->assertSame(20, DB::table('saved_addresses')->where('user_id', $this->alice->id)->count());
        $this->via('customer')->getJson(self::BASE)->assertJsonCount(20, 'data');

        // Another person is unaffected, and a deletion frees a place.
        $this->user('bob@example.com');
        $this->signIn('bob@example.com');
        $this->create();
        $this->signIn();
        $one = DB::table('saved_addresses')->where('user_id', $this->alice->id)->value('id');
        $this->via('customer')->deleteJson(self::BASE."/{$one}")->assertNoContent();
        $this->create();
    }

    // ---- update ----

    public function test_patch_changes_any_subset(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => 'Red roof'])->assertOk()
            ->assertJsonPath('data.landmark', 'Red roof')
            ->assertJsonPath('data.address_line', '12 Sample Street, Barangay Testing')
            ->assertJsonPath('data.tag', 'Home')
            ->assertJsonPath('data.latitude', 10.0123);

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['tag' => null])->assertOk()->assertJsonPath('data.tag', null);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['tag' => ' Work '])->assertOk()->assertJsonPath('data.tag', 'Work');
        $this->assertSame('Work', $this->row($id)->tag);
    }

    public function test_patch_moving_the_point_rederives_the_city(): void
    {
        $this->signIn();
        $elsewhere = $this->coverage('Elsewhere', self::OTHER_SQUARE);
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['latitude' => '10.01', 'longitude' => '110.01'])->assertOk()
            ->assertJsonPath('data.city.id', $elsewhere->id)->assertJsonPath('data.longitude', 110.01);
        $this->assertSame($elsewhere->id, $this->row($id)->city_id);

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['latitude' => '50', 'longitude' => '50'])->assertOk()
            ->assertJsonPath('data.city', null)->assertJsonPath('data.serviceable', false);
        $this->assertNull($this->row($id)->city_id);
    }

    public function test_patch_needs_both_coordinates_or_neither(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['latitude' => '10.5'])->assertStatus(422)->assertJsonValidationErrors('longitude', 'error.fields');
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['longitude' => '100.5'])->assertStatus(422)->assertJsonValidationErrors('latitude', 'error.fields');
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['latitude' => null, 'longitude' => null])->assertStatus(422);
        $this->assertEqualsWithDelta(10.0123, DB::selectOne('SELECT ST_Y(point) AS y FROM saved_addresses WHERE id = ?', [$id])->y, 1e-9);
    }

    public function test_patch_applies_the_same_text_rules(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['address_line' => ''])->assertStatus(422);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => null])->assertStatus(422);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => "a\u{200B}b"])->assertStatus(422);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['tag' => str_repeat('t', 41)])->assertStatus(422);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['latitude' => '99', 'longitude' => '100'])->assertStatus(422);
        $this->assertSame('12 Sample Street, Barangay Testing', $this->row($id)->address_line);
    }

    public function test_patch_with_no_editable_field_is_refused(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", [])->assertStatus(422);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['city_id' => $this->city->id, 'user_id' => $this->alice->id])->assertStatus(422);
    }

    public function test_patch_ignores_client_supplied_city_and_owner(): void
    {
        $this->signIn();
        $bob = $this->user('bob@example.com');
        $other = $this->coverage('Elsewhere', self::OTHER_SQUARE);
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => 'New', 'city_id' => $other->id, 'user_id' => $bob->id])->assertOk();

        $this->assertSame($this->city->id, $this->row($id)->city_id);
        $this->assertSame($this->alice->id, $this->row($id)->user_id);
    }

    public function test_put_is_not_routed(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->putJson(self::BASE."/{$id}", $this->payload())->assertStatus(405);
    }

    // ---- default ----

    public function test_the_first_address_becomes_the_default_and_a_later_one_does_not(): void
    {
        $this->signIn();
        $this->via('customer')->postJson(self::BASE, $this->payload())->assertCreated()->assertJsonPath('data.is_default', true);
        $this->via('customer')->postJson(self::BASE, $this->payload())->assertCreated()->assertJsonPath('data.is_default', false);
    }

    public function test_creating_with_is_default_moves_the_default(): void
    {
        $this->signIn();
        $first = $this->create();
        $second = $this->create(['is_default' => true]);

        $this->assertSame([$second], $this->defaults());
        $this->assertFalse((bool) $this->row($first)->is_default);
    }

    public function test_the_default_endpoint_moves_the_default_in_one_step(): void
    {
        $this->signIn();
        $first = $this->create();
        $second = $this->create();

        $this->via('customer')->postJson(self::BASE."/{$second}/default")->assertOk()
            ->assertJsonPath('data.id', $second)->assertJsonPath('data.is_default', true);

        $this->assertSame([$second], $this->defaults());
        $this->assertFalse((bool) $this->row($first)->is_default);
        // Idempotent.
        $this->via('customer')->postJson(self::BASE."/{$second}/default")->assertOk();
        $this->assertSame([$second], $this->defaults());
    }

    public function test_patch_is_default_true_moves_the_default_and_false_clears_it(): void
    {
        $this->signIn();
        $first = $this->create();
        $second = $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$second}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$second], $this->defaults());

        $this->via('customer')->patchJson(self::BASE."/{$second}", ['is_default' => false])->assertOk()->assertJsonPath('data.is_default', false);
        $this->assertSame([], $this->defaults());
        $this->assertFalse((bool) $this->row($first)->is_default);
    }

    public function test_deleting_the_default_leaves_no_default(): void
    {
        $this->signIn();
        $first = $this->create();
        $second = $this->create();

        $this->via('customer')->deleteJson(self::BASE."/{$first}")->assertNoContent();

        $this->assertSame([], $this->defaults());
        $this->assertFalse((bool) $this->row($second)->is_default);
    }

    public function test_defaults_of_two_people_do_not_interfere(): void
    {
        $this->signIn();
        $mine = $this->create();
        $bob = $this->user('bob@example.com');
        $this->signIn('bob@example.com');
        $bobs = $this->create();

        $this->assertSame([$mine], $this->defaults());
        $this->assertSame([$bobs], DB::table('saved_addresses')->where('user_id', $bob->id)->where('is_default', true)->pluck('id')->all());
    }

    /**
     * Two requests cannot be interleaved inside one test transaction, so this
     * proves the mechanism instead: every write takes a row lock on the owner
     * before it touches saved_addresses, which serialises one person's writes
     * (default switch and the limit of 20) in PostgreSQL.
     */
    public function test_every_write_locks_the_owner_row_before_touching_addresses(): void
    {
        $this->signIn();
        $first = $this->create();
        $second = $this->create();

        $runs = [
            'create' => fn () => $this->via('customer')->postJson(self::BASE, $this->payload(['is_default' => true])),
            'default' => fn () => $this->via('customer')->postJson(self::BASE."/{$second}/default"),
            'patch' => fn () => $this->via('customer')->patchJson(self::BASE."/{$first}", ['is_default' => true]),
            'delete' => fn () => $this->via('customer')->deleteJson(self::BASE."/{$first}"),
        ];

        foreach ($runs as $name => $run) {
            $queries = [];
            DB::listen(function ($q) use (&$queries): void {
                $queries[] = strtolower($q->sql);
            });
            $run();

            $lock = null;
            $write = null;
            foreach ($queries as $i => $sql) {
                if ($lock === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                    $lock = $i;
                }
                if ($write === null && preg_match('/^(insert into|update|delete from) "?saved_addresses/', $sql) === 1) {
                    $write = $i;
                }
            }
            $this->assertNotNull($lock, "The {$name} write did not lock the user row.");
            $this->assertNotNull($write, "The {$name} write wrote nothing.");
            $this->assertLessThan($write, $lock, "The {$name} write wrote before it locked the user row.");
        }
    }

    // ---- delete and ids ----

    public function test_delete_removes_the_row_and_answers_204(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->deleteJson(self::BASE."/{$id}")->assertNoContent();

        $this->assertNull($this->row($id));
        $this->via('customer')->getJson(self::BASE)->assertJsonCount(0, 'data');
        // A deleted address is 404 on every route.
        $this->via('customer')->deleteJson(self::BASE."/{$id}")->assertNotFound();
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => 'x'])->assertNotFound();
        $this->via('customer')->postJson(self::BASE."/{$id}/default")->assertNotFound();
    }

    public function test_ids_are_accepted_in_any_case(): void
    {
        $this->signIn();
        $id = $this->create();

        $this->via('customer')->patchJson(self::BASE.'/'.strtoupper($id), ['landmark' => 'Upper'])->assertOk()->assertJsonPath('data.id', $id);
    }

    #[TestWith(['not-an-id'])]
    #[TestWith(['01ARZ3NDEKTSV4RRFFQ69G5FA'])]
    #[TestWith(['01ARZ3NDEKTSV4RRFFQ69G5FAVV'])]
    #[TestWith(['01arz3ndektsv4rrffq69g5fa!'])]
    #[TestWith(['01arz3ndektsv4rrffq69g5fav'])]
    public function test_a_malformed_or_unknown_id_is_404_on_every_route(string $id): void
    {
        $this->signIn();
        $this->create();

        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => 'x'])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->via('customer')->deleteJson(self::BASE."/{$id}")->assertNotFound();
        $this->via('customer')->postJson(self::BASE."/{$id}/default")->assertNotFound();
        $this->assertSame(1, DB::table('saved_addresses')->count());
    }

    // ---- ownership ----

    public function test_person_b_can_neither_change_delete_nor_default_person_as_address(): void
    {
        $this->signIn();
        $a1 = $this->create(['address_line' => 'Alice private road']);
        $a2 = $this->create(['address_line' => 'Alice second road']);
        $this->user('bob@example.com');
        $this->signIn('bob@example.com');
        $b1 = $this->create(['address_line' => 'Bob road']);
        $before = DB::table('saved_addresses')->orderBy('id')->get()->all();

        // 404 for every verb, never 403, and the same answer as an unknown id.
        $this->via('customer')->patchJson(self::BASE."/{$a1}", ['landmark' => 'Hijacked', 'is_default' => true])->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->via('customer')->deleteJson(self::BASE."/{$a1}")->assertNotFound();
        $this->via('customer')->postJson(self::BASE."/{$a2}/default")->assertNotFound();
        $unknown = $this->via('customer')->patchJson(self::BASE.'/'.strtolower((string) Str::ulid()), ['landmark' => 'x']);
        $theirs = $this->via('customer')->patchJson(self::BASE."/{$a1}", ['landmark' => 'x']);
        $this->assertSame($unknown->json('error.message'), $theirs->json('error.message'));
        // There is no read route for one address, and the list never shows A's rows.
        $this->via('customer')->getJson(self::BASE."/{$a1}")->assertStatus(405);
        $this->assertSame([$b1], array_column($this->via('customer')->getJson(self::BASE)->json('data'), 'id'));

        $this->assertEquals($before, DB::table('saved_addresses')->orderBy('id')->get()->all());
        $this->assertSame($this->alice->id, $this->row($a1)->user_id);
        $this->assertTrue((bool) $this->row($a1)->is_default);
    }

    // ---- surfaces and tokens ----

    #[TestWith(['merchant'])]
    #[TestWith(['admin'])]
    public function test_the_merchant_and_admin_apps_get_404_on_every_route(string $surface): void
    {
        $this->signIn();
        $id = $this->create();
        $staff = $this->user('staff@example.com');
        $this->grantRole($staff, 'platform_owner');
        $merchant = strtolower((string) Str::ulid());
        DB::table('merchants')->insert(['id' => $merchant, 'legal_name' => 'M', 'display_name' => 'M', 'slug' => 'm', 'status' => 'active']);
        DB::table('merchant_staff')->insert(['id' => strtolower((string) Str::ulid()), 'merchant_id' => $merchant, 'user_id' => $staff->id, 'role' => 'owner']);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->signIn('staff@example.com', $surface);

        $this->via($surface)->getJson(self::BASE)->assertNotFound();
        $this->via($surface)->postJson(self::BASE, $this->payload())->assertNotFound();
        $this->via($surface)->patchJson(self::BASE."/{$id}", ['landmark' => 'x'])->assertNotFound();
        $this->via($surface)->deleteJson(self::BASE."/{$id}")->assertNotFound();
        $this->via($surface)->postJson(self::BASE."/{$id}/default")->assertNotFound();
        $this->assertSame(1, DB::table('saved_addresses')->count());
    }

    public function test_every_route_needs_a_session(): void
    {
        $id = strtolower((string) Str::ulid());

        $this->via('customer')->getJson(self::BASE)->assertStatus(401);
        $this->via('customer')->postJson(self::BASE, $this->payload())->assertStatus(401);
        $this->via('customer')->patchJson(self::BASE."/{$id}", ['landmark' => 'x'])->assertStatus(401);
        $this->via('customer')->deleteJson(self::BASE."/{$id}")->assertStatus(401);
        $this->via('customer')->postJson(self::BASE."/{$id}/default")->assertStatus(401);
    }

    public function test_a_rider_bearer_token_is_refused(): void
    {
        $user = $this->user('rider@example.com');
        DB::table('riders')->insert([
            'id' => strtolower((string) Str::ulid()), 'user_id' => $user->id, 'status' => 'approved', 'approved_at' => now(),
        ]);
        $this->withoutHeader('Origin');
        $token = $this->postJson('/api/v1/auth/mobile/token', [
            'email' => 'rider@example.com', 'password' => self::PASSWORD, 'device_name' => 'Test phone', 'platform' => 'android',
        ])->assertCreated()->json('data.token');

        foreach ([null, self::ORIGINS['customer']] as $origin) {
            $this->app['auth']->forgetGuards();
            $client = $origin === null ? $this->withoutHeader('Origin') : $this->withHeader('Origin', $origin);
            $client->withToken($token)->getJson(self::BASE)->assertStatus(401);
            $this->app['auth']->forgetGuards();
            $client->withToken($token)->postJson(self::BASE, $this->payload())->assertStatus(401);
        }
        $this->assertSame(0, DB::table('saved_addresses')->count());
    }

    public function test_the_address_routes_sit_behind_the_session_guard_then_the_customer_guard(): void
    {
        $found = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/me/addresses')) {
                continue;
            }
            $found++;
            $middleware = $route->gatherMiddleware();
            $session = array_search(EnsureSurfaceSession::class, $middleware, true);
            $customer = array_search(EnsureCustomerSurface::class, $middleware, true);
            $this->assertNotFalse($session);
            $this->assertNotFalse($customer);
            $this->assertLessThan($customer, $session);
        }
        $this->assertSame(5, $found);
    }

    // ---- audit and log hygiene ----

    public function test_a_full_cycle_writes_the_four_audit_actions_with_ids_and_field_names_only(): void
    {
        $this->signIn();
        $a = $this->create(['address_line' => 'Secretline Avenue 99', 'landmark' => 'Hiddenmark tower', 'latitude' => '10.01234', 'longitude' => '100.04567']);
        $b = $this->create();
        $this->via('customer')->patchJson(self::BASE."/{$a}", ['landmark' => 'Newmark plaza', 'latitude' => '10.01987', 'longitude' => '100.04321', 'tag' => 'Cabin'])->assertOk();
        $this->via('customer')->postJson(self::BASE."/{$b}/default")->assertOk();
        $this->via('customer')->deleteJson(self::BASE."/{$a}")->assertNoContent();

        $rows = DB::table('audit_logs')->where('subject_type', 'saved_address')->orderBy('id')->get();
        $this->assertSame(
            ['address.created', 'address.created', 'address.updated', 'address.default_changed', 'address.deleted'],
            $rows->pluck('action')->all(),
        );
        foreach ($rows as $row) {
            $this->assertSame($this->alice->id, $row->actor_user_id);
            $this->assertNotNull($row->ip_hash);
        }
        $this->assertSame($a, $rows[0]->subject_id);
        $this->assertSame($b, $rows[1]->subject_id);
        $this->assertSame($a, $rows[2]->subject_id);
        $changed = json_decode($rows[2]->after_json, true)['changed'];
        sort($changed);
        $this->assertSame(['landmark', 'latitude', 'longitude', 'tag'], $changed);
        $this->assertSame($b, $rows[3]->subject_id);
        $this->assertSame($a, $rows[4]->subject_id);
    }

    public function test_an_unchanged_patch_or_repeated_default_writes_no_audit_row(): void
    {
        $this->signIn();
        $a = $this->create();
        $this->via('customer')->postJson(self::BASE."/{$a}/default")->assertOk();
        $this->via('customer')->patchJson(self::BASE."/{$a}", ['landmark' => 'Blue gate beside the sari-sari store', 'is_default' => true])->assertOk();

        $this->assertSame(['address.created'], DB::table('audit_logs')->where('subject_type', 'saved_address')->pluck('action')->all());
    }

    public function test_a_refused_request_writes_no_audit_row(): void
    {
        $this->signIn();
        $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => '99']))->assertStatus(422);

        $this->assertSame(0, DB::table('audit_logs')->where('subject_type', 'saved_address')->count());
    }

    public function test_no_log_line_audit_row_or_error_body_holds_a_position_or_address_text(): void
    {
        $this->signIn();

        $a = $this->create(['address_line' => 'Quillmoor Boulevard 4471', 'landmark' => 'Wexhallow Gate', 'latitude' => '10.0187651', 'longitude' => '100.0123489']);
        $this->via('customer')->patchJson(self::BASE."/{$a}", ['address_line' => 'Zephyrine Row 8820', 'landmark' => 'Gruntlefold Arch', 'latitude' => '10.0155555', 'longitude' => '100.0166666'])->assertOk();
        $this->create();
        $this->via('customer')->postJson(self::BASE."/{$a}/default")->assertOk();
        $this->via('customer')->getJson(self::BASE)->assertOk();
        $refused = $this->via('customer')->postJson(self::BASE, $this->payload(['latitude' => '91.5551234', 'address_line' => 'Refusedline Terrace 3']))->assertStatus(422);
        $this->via('customer')->deleteJson(self::BASE."/{$a}")->assertNoContent();

        $needles = [
            'Quillmoor', 'Wexhallow', 'Zephyrine', 'Gruntlefold', 'Refusedline', '91.5551234',
            '10.0187', '100.0123', '10.0155', '100.0166', '12 Sample Street', 'sari-sari',
        ];
        $this->assertNotEmpty($this->logged);
        $log = json_encode(array_map(fn (MessageLogged $e): array => [$e->message, $e->context], $this->logged), JSON_THROW_ON_ERROR);
        $audit = json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR);
        $body = (string) $refused->getContent();

        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $log, "log holds {$needle}");
            $this->assertStringNotContainsString($needle, $audit, "audit holds {$needle}");
            $this->assertStringNotContainsString($needle, $body, "error body holds {$needle}");
        }

        // The access log records the route template, not the id.
        $requests = array_values(array_filter($this->logged, fn (MessageLogged $e): bool => $e->message === 'http.request'));
        $routes = array_column(array_map(fn (MessageLogged $e): array => $e->context, $requests), 'route');
        $this->assertContains('api/v1/me/addresses/{address}', $routes);
        $this->assertStringNotContainsString($a, json_encode($routes, JSON_THROW_ON_ERROR));
    }
}
