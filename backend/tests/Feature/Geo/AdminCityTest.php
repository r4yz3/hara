<?php

namespace Tests\Feature\Geo;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Auth\AuthTestCase;

/**
 * Covers the spec's AdminCityCreateTest, CitySlugTest and
 * CityStatusTransitionTest (10.1) and the cross-city cases in
 * docs/qa/phase1-acceptance.md.
 */
class AdminCityTest extends AuthTestCase
{
    private const VALID = [
        'name' => 'Example City',
        'slug' => 'example-city',
        'country_code' => 'ZZ',
        'currency_code' => 'PHP',
        'timezone' => 'UTC',
    ];

    private function staff(string $role, ?City $city = null, string $email = 'staff@example.com'): User
    {
        $user = $this->user($email);
        $this->grantRole($user, $role, $city === null ? 'global' : 'city', $city?->id);
        $this->login('admin', $email)->assertOk();

        return $user;
    }

    public function test_admin_routes_need_a_session(): void
    {
        $this->via('admin')->getJson('/api/v1/admin/cities')->assertStatus(401);
        $this->via('admin')->postJson('/api/v1/admin/cities', self::VALID)->assertStatus(401);
        $this->assertSame(0, DB::table('cities')->count());
    }

    public function test_a_customer_session_cannot_reach_admin_routes(): void
    {
        $this->user();
        $this->login('customer', 'person@example.com')->assertOk();

        $this->via('customer')->getJson('/api/v1/admin/cities')->assertStatus(404);
        $this->via('customer')->postJson('/api/v1/admin/cities', self::VALID)->assertStatus(404);
        $this->assertSame(0, DB::table('cities')->count());
    }

    public function test_owner_creates_a_draft_city_and_it_is_audited(): void
    {
        $owner = $this->staff('platform_owner');

        $response = $this->via('admin')->postJson('/api/v1/admin/cities', self::VALID)->assertStatus(201);

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', 'example-city')
            ->assertJsonPath('data.timezone', 'UTC');
        $this->assertSame(
            ['id', 'slug', 'name', 'country_code', 'currency_code', 'timezone', 'status', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );

        $audit = DB::table('audit_logs')->where('action', 'city.created')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame($response->json('data.id'), $audit->subject_id);
    }

    public function test_status_and_id_in_a_create_request_are_ignored(): void
    {
        $this->staff('platform_owner');

        $response = $this->via('admin')
            ->postJson('/api/v1/admin/cities', self::VALID + ['status' => 'active', 'id' => '01jexample0000000000000000'])
            ->assertStatus(201);

        $this->assertSame('draft', $response->json('data.status'));
        $this->assertNotSame('01jexample0000000000000000', $response->json('data.id'));
    }

    public function test_invalid_city_input_is_rejected_field_by_field(): void
    {
        $this->staff('platform_owner');

        $this->via('admin')->postJson('/api/v1/admin/cities', [
            'name' => '', 'slug' => 'Not A Slug', 'country_code' => 'zzz', 'currency_code' => 'USD', 'timezone' => 'Mars/Olympus',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'slug', 'country_code', 'currency_code', 'timezone']]]);

        // A timezone is never assumed.
        $this->via('admin')->postJson('/api/v1/admin/cities', array_diff_key(self::VALID, ['timezone' => 1]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['timezone']]]);

        $this->assertSame(0, DB::table('cities')->count());
    }

    public function test_a_slug_can_be_used_once(): void
    {
        $this->staff('platform_owner');
        $this->via('admin')->postJson('/api/v1/admin/cities', self::VALID)->assertStatus(201);

        $this->via('admin')->postJson('/api/v1/admin/cities', ['name' => 'Other'] + self::VALID)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['slug']]]);
        $this->assertSame(1, DB::table('cities')->count());
    }

    public function test_a_city_manager_sees_only_their_city_and_cannot_create_or_edit(): void
    {
        $mine = City::factory()->create();
        $other = City::factory()->create();
        $this->staff('city_manager', $mine);

        $list = $this->via('admin')->getJson('/api/v1/admin/cities')->assertOk();
        $this->assertSame([$mine->id], array_column($list->json('data'), 'id'));

        $this->via('admin')->getJson("/api/v1/admin/cities/{$mine->id}")->assertOk();
        $this->via('admin')->getJson("/api/v1/admin/cities/{$other->id}")->assertStatus(404);
        $this->via('admin')->patchJson("/api/v1/admin/cities/{$other->id}", ['name' => 'Taken over'])->assertStatus(404);

        $this->via('admin')->postJson('/api/v1/admin/cities', self::VALID)->assertStatus(403);
        $this->via('admin')->patchJson("/api/v1/admin/cities/{$mine->id}", ['name' => 'Renamed'])->assertStatus(403);

        $this->assertSame($other->name, DB::table('cities')->where('id', $other->id)->value('name'));
        $this->assertSame($mine->name, DB::table('cities')->where('id', $mine->id)->value('name'));
        $this->assertSame(2, DB::table('cities')->count());
    }

    public function test_staff_without_city_permissions_get_nothing(): void
    {
        $city = City::factory()->create();
        $user = $this->user('staff@example.com');
        // A role that opens the admin app but is scoped to another city.
        $this->grantRole($user, 'dispatcher', 'city', City::factory()->create()->id);
        $this->login('admin', 'staff@example.com')->assertOk();

        $this->via('admin')->getJson("/api/v1/admin/cities/{$city->id}")->assertStatus(404);
    }

    public function test_ids_are_matched_in_any_letter_case_and_malformed_ids_are_not_found(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();

        $this->via('admin')->getJson('/api/v1/admin/cities/'.strtoupper($city->id))->assertOk()->assertJsonPath('data.id', $city->id);
        $this->via('admin')->getJson('/api/v1/admin/cities/not-an-id')->assertStatus(404);
        $this->via('admin')->getJson("/api/v1/admin/cities/' OR 1=1 --")->assertStatus(404);
    }

    public function test_owner_edits_safe_fields_only(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();

        $this->via('admin')->patchJson("/api/v1/admin/cities/{$city->id}", [
            'name' => 'Renamed City', 'timezone' => 'Asia/Manila', 'slug' => 'changed-slug', 'currency_code' => 'USD',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed City')->assertJsonPath('data.timezone', 'Asia/Manila');

        $row = DB::table('cities')->where('id', $city->id)->first();
        $this->assertSame($city->slug, $row->slug, 'slug is fixed after creation');
        $this->assertSame('PHP', $row->currency_code);

        $audit = DB::table('audit_logs')->where('action', 'city.updated')->sole();
        $this->assertSame($city->name, json_decode($audit->before_json, true)['name']);
        $this->assertSame('Renamed City', json_decode($audit->after_json, true)['name']);
    }

    public function test_status_moves_only_along_allowed_transitions(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        $patch = fn (string $status) => $this->via('admin')->patchJson("/api/v1/admin/cities/{$city->id}", ['status' => $status]);

        $patch('paused')->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
        $patch('nonsense')->assertStatus(422);
        $patch('active')->assertOk()->assertJsonPath('data.status', 'active');
        $patch('draft')->assertStatus(409);
        $patch('archived')->assertStatus(409);
        $patch('paused')->assertOk();
        $patch('active')->assertOk();
        $patch('paused')->assertOk();
        $patch('archived')->assertOk()->assertJsonPath('data.status', 'archived');
        $patch('active')->assertStatus(409);

        $this->assertSame('archived', DB::table('cities')->where('id', $city->id)->value('status'));
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'city.status_changed')->count());
    }

    public function test_an_archived_city_cannot_be_edited(): void
    {
        $this->staff('platform_owner');
        $city = City::factory()->create();
        DB::table('cities')->where('id', $city->id)->update(['status' => 'archived']);

        $this->via('admin')->patchJson("/api/v1/admin/cities/{$city->id}", ['name' => 'Revived'])->assertStatus(409);
    }

    public function test_list_is_paged_sorted_and_filterable(): void
    {
        $this->staff('platform_owner');
        $cities = City::factory()->count(5)->create()->sortBy('id')->values();
        DB::table('cities')->where('id', $cities[0]->id)->update(['status' => 'active']);

        $first = $this->via('admin')->getJson('/api/v1/admin/cities?limit=2')->assertOk();
        $this->assertSame([$cities[0]->id, $cities[1]->id], array_column($first->json('data'), 'id'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->via('admin')->getJson('/api/v1/admin/cities?limit=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertSame([$cities[2]->id, $cities[3]->id], array_column($second->json('data'), 'id'));

        $last = $this->via('admin')->getJson('/api/v1/admin/cities?limit=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk();
        $this->assertSame([$cities[4]->id], array_column($last->json('data'), 'id'));
        $this->assertNull($last->json('meta.next_cursor'));

        $active = $this->via('admin')->getJson('/api/v1/admin/cities?status=active')->assertOk();
        $this->assertSame([$cities[0]->id], array_column($active->json('data'), 'id'));

        $this->via('admin')->getJson('/api/v1/admin/cities?limit=101')->assertStatus(422);
        $this->via('admin')->getJson('/api/v1/admin/cities?cursor=garbage')->assertStatus(422);
        $this->via('admin')->getJson('/api/v1/admin/cities?status=open')->assertStatus(422);
    }
}
