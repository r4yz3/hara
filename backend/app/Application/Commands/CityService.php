<?php

namespace App\Application\Commands;

use App\Domain\Geo\CityStatus;
use App\Models\City;
use App\Support\Errors\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * Creates and changes cities. Each change and its audit row are written in
 * one transaction (spec 6.3). Authorization is the caller's job.
 */
class CityService
{
    private const AUDITED = ['slug', 'name', 'country_code', 'currency_code', 'timezone', 'status'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, slug: string, country_code: string, currency_code: string, timezone: string}  $fields
     */
    public function create(string $actorId, array $fields): City
    {
        return DB::transaction(function () use ($actorId, $fields): City {
            // Status is never taken from input: every city starts as a draft.
            $city = City::create($fields)->refresh();

            $this->audit->record($actorId, 'city.created', 'city', $city->id, $city->id, after: $city->only(self::AUDITED));

            return $city;
        });
    }

    /**
     * @param  array{name?: string, timezone?: string}  $fields
     */
    public function update(string $actorId, string $cityId, array $fields, ?CityStatus $status): City
    {
        return DB::transaction(function () use ($actorId, $cityId, $fields, $status): City {
            /** @var City $city */
            $city = City::query()->lockForUpdate()->findOrFail($cityId);
            $current = CityStatus::from($city->status);

            if ($current === CityStatus::Archived) {
                throw new ApiError(409, 'CONFLICT', 'An archived city cannot be changed.');
            }

            if ($status !== null && $status !== $current && ! $current->canBecome($status)) {
                throw new ApiError(409, 'CONFLICT', "A {$current->value} city cannot become {$status->value}.");
            }

            $before = $city->only(self::AUDITED);

            if ($fields !== []) {
                $city->fill($fields);

                if ($city->isDirty()) {
                    $city->save();
                    $this->audit->record($actorId, 'city.updated', 'city', $city->id, $city->id, before: $before, after: $city->only(self::AUDITED));
                }
            }

            if ($status !== null && $status !== $current) {
                $city->forceFill(['status' => $status->value])->save();
                $this->audit->record(
                    $actorId, 'city.status_changed', 'city', $city->id, $city->id,
                    before: ['status' => $current->value], after: ['status' => $status->value],
                );
            }

            return $city->refresh();
        });
    }
}
