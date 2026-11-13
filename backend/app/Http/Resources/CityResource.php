<?php

namespace App\Http\Resources;

use App\Models\City;
use Illuminate\Support\Carbon;

final class CityResource
{
    /**
     * Full record, for staff.
     *
     * @return array<string, mixed>
     */
    public static function admin(City $city): array
    {
        return [
            'id' => $city->id,
            'slug' => $city->slug,
            'name' => $city->name,
            'country_code' => $city->country_code,
            'currency_code' => $city->currency_code,
            'timezone' => $city->timezone,
            'status' => $city->status,
            'created_at' => self::utc($city->created_at),
            'updated_at' => self::utc($city->updated_at),
        ];
    }

    /**
     * What anyone may see about an active city.
     *
     * @return array<string, mixed>
     */
    public static function public(City $city): array
    {
        return [
            'id' => $city->id,
            'name' => $city->name,
            'country_code' => $city->country_code,
            'currency_code' => $city->currency_code,
            'timezone' => $city->timezone,
        ];
    }

    private static function utc(mixed $value): ?string
    {
        return $value instanceof Carbon ? $value->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
