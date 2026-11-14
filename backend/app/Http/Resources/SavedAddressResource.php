<?php

namespace App\Http\Resources;

use App\Application\Queries\ServiceabilityQuery;
use App\Domain\Geo\Coordinates;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * One saved address as the customer sees it. `user_id` and the `city_id`
 * column are never exposed. `serviceable` is worked out now, not stored, so
 * it follows the current coverage; `city` is the one found when the address
 * was last written.
 */
final class SavedAddressResource
{
    public function __construct(private readonly ServiceabilityQuery $coverage) {}

    /** @return array<string, mixed> */
    public function toArray(stdClass $row): array
    {
        $latitude = (float) $row->latitude;
        $longitude = (float) $row->longitude;

        return [
            'id' => (string) $row->id,
            'tag' => $row->tag,
            'address_line' => (string) $row->address_line,
            'landmark' => (string) $row->landmark,
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
            'is_default' => (bool) $row->is_default,
            'city' => $row->city_id === null ? null : ['id' => (string) $row->city_id, 'name' => (string) $row->city_name],
            'serviceable' => $this->coverage->at(Coordinates::fromLatitudeLongitude($latitude, $longitude))['serviceable'],
            'created_at' => Carbon::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => Carbon::parse($row->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
