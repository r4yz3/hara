<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Queries\ServiceabilityQuery;
use App\Domain\Geo\Coordinates;
use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Public, unauthenticated geography (spec 7.2). Returns whitelisted fields
 * only: never geometry, status, priority or anything internal.
 */
class PublicGeoController extends Controller
{
    public function cities(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        $limit = (int) ($input['limit'] ?? 25);
        $query = City::query()->where('status', 'active')->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $page->map(fn (City $city): array => CityResource::public($city))->values()->all(), $next);
    }

    public function serviceability(Request $request, ServiceabilityQuery $query): JsonResponse
    {
        // Plain decimal numbers only: no exponents, no "NaN", no arrays.
        $decimal = 'regex:/\A-?\d{1,3}(\.\d{1,12})?\z/';
        $request->validate([
            'latitude' => ['required', 'string', $decimal],
            'longitude' => ['required', 'string', $decimal],
        ]);

        try {
            $point = Coordinates::fromLatitudeLongitude((float) $request->query('latitude'), (float) $request->query('longitude'));
        } catch (InvalidArgumentException $e) {
            $field = str_starts_with($e->getMessage(), 'Latitude') ? 'latitude' : 'longitude';

            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }

        // The position is used for this answer only: it is not logged or stored.
        return ApiResponse::data($request, $query->at($point));
    }
}
