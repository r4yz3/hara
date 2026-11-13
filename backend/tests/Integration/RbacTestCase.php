<?php

namespace Tests\Integration;

use App\Models\City;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class RbacTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    protected function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * Inserts a grant directly, standing in for the bootstrap procedure.
     * Production code must go through RoleGrantService.
     */
    protected function userWith(string $role, ?City $city = null): User
    {
        $user = User::factory()->create();

        DB::table('user_role_scopes')->insert([
            'id' => $this->ulid(),
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('slug', $role)->value('id'),
            'scope_type' => $city === null ? 'global' : 'city',
            'scope_id' => $city?->id,
        ]);

        return $user;
    }

    protected function insertMerchantWithBranch(City $city): string
    {
        $merchantId = $this->ulid();
        $branchId = $this->ulid();

        DB::table('merchants')->insert(['id' => $merchantId, 'legal_name' => 'Test Merchant', 'display_name' => 'Test Merchant', 'slug' => 'm-'.$merchantId]);
        DB::insert(
            "INSERT INTO branches (id, merchant_id, city_id, display_name, address_text, location)
             VALUES (?, ?, ?, 'Branch', 'Synthetic address', ST_SetSRID(ST_MakePoint(100.01, 10.01), 4326))",
            [$branchId, $merchantId, $city->id],
        );

        return $branchId;
    }
}
