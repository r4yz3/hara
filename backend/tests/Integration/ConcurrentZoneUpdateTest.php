<?php

namespace Tests\Integration;

use App\Application\Commands\ZoneService;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConcurrentZoneUpdateTest extends PostgresConcurrencyTestCase
{
    public function test_a_waiting_editor_cannot_overwrite_the_committed_edit(): void
    {
        $city = City::factory()->create();
        $firstEditor = User::factory()->create();
        $secondEditor = User::factory()->create();
        $zone = strtolower((string) Str::ulid());
        DB::insert(
            "INSERT INTO delivery_zones (id, city_id, name, slug, coverage)
             VALUES (?, ?, 'Original', 'test-zone', ST_Multi(ST_MakeEnvelope(100, 10, 100.02, 10.02, 4326)))",
            [$zone, $city->id],
        );

        DB::beginTransaction();
        try {
            $this->app->make(ZoneService::class)->update((string) $firstEditor->id, $zone, 1, ['name' => 'First edit'], null);
            $result = $this->startContender(<<<'PHP'
                try {
                    $app->make(App\Application\Commands\ZoneService::class)
                        ->update($argv[2], $argv[3], 1, ['name' => 'Second edit'], null);
                    echo 'updated';
                } catch (App\Support\Errors\ApiError $error) {
                    echo $error->status.' '.$error->errorCode;
                }
                PHP, [(string) $secondEditor->id, $zone]);

            $this->assertSame('409 CONFLICT', $result);
        } finally {
            DB::rollBack();
        }

        $row = DB::table('delivery_zones')->where('id', $zone)->sole();
        $this->assertSame('First edit', $row->name);
        $this->assertSame(2, (int) $row->version);
        $audit = DB::table('audit_logs')->where('action', 'zone.updated')->where('subject_id', $zone)->sole();
        $this->assertSame($firstEditor->id, $audit->actor_user_id);
    }
}
