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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Public, unauthenticated geography (spec 7.2). Returns whitelisted fields
 * only: never geometry, status, priority or anything internal.
 */
class PublicGeoController extends Controller
{
    /**
     * Deployment facts any client may read before signing in. Built from a
     * fixed list: adding a config value never publishes it by accident.
     */
    public function config(Request $request): JsonResponse
    {
        $style = config('platform.map_style_url');
        $open = DB::table('delivery_zones as z')
            ->join('cities as c', 'c.id', '=', 'z.city_id')
            ->where('z.status', 'active')
            ->where('c.status', 'active')
            ->exists();

        return ApiResponse::data($request, [
            'currency' => (string) config('platform.currency'),
            'display_timezone' => (string) config('platform.display_timezone'),
            'service_open' => $open,
            'map_style_url' => is_string($style) && preg_match('#\Ahttps://[^\s]+\z#', $style) === 1 ? $style : null,
            'features' => [
                'public_registration' => (bool) config('features.public_registration'),
                'live_dispatch' => (bool) config('features.live_dispatch'),
                'online_payments' => (bool) config('features.online_payments'),
                'rider_tracking' => (bool) config('features.rider_tracking'),
            ],
        ]);
    }

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
        // A position in the query string would be written to access logs by
        // every server and proxy on the way, so it is refused outright (ADR-013).
        if ($request->query->has('latitude') || $request->query->has('longitude')) {
            throw ValidationException::withMessages([
                'latitude' => ['Send the position in the request body, not in the URL.'],
                'longitude' => ['Send the position in the request body, not in the URL.'],
            ]);
        }

        // A plain decimal, as a JSON string or number: no exponents, no "NaN", no arrays.
        $decimal = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail("The {$attribute} must be a number.");
            } elseif (preg_match('/\A-?\d{1,3}(\.\d{1,12})?\z/', (string) $value) !== 1) {
                $fail("The {$attribute} must be a plain decimal number.");
            }
        };
        $body = $request->json()->all();
        validator($body, [
            'latitude' => ['present', $decimal],
            'longitude' => ['present', $decimal],
        ])->validate();

        try {
            $point = Coordinates::fromLatitudeLongitude((float) $body['latitude'], (float) $body['longitude']);
        } catch (InvalidArgumentException $e) {
            $field = str_starts_with($e->getMessage(), 'Latitude') ? 'latitude' : 'longitude';

            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }

        // The position is used for this answer only: it is not logged or stored.
        return ApiResponse::data($request, $query->at($point));
    }
}
