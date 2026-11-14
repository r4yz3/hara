<?php

namespace App\Support\Http;

use App\Domain\Geo\Coordinates;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * How a request carries a position (ADR-002, ADR-013): latitude and longitude
 * by name in the JSON body, as plain decimals (string or number), never in the
 * URL. Shared by the serviceability check and saved addresses so both accept
 * and refuse exactly the same values.
 */
final class PositionInput
{
    /** A plain decimal, as a JSON string or number: no exponents, no "NaN", no arrays, no booleans. */
    public static function decimal(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $fail("The {$attribute} must be a number.");
            } elseif (preg_match('/\A-?\d{1,3}(\.\d{1,12})?\z/', (string) $value) !== 1) {
                $fail("The {$attribute} must be a plain decimal number.");
            }
        };
    }

    /** A position in the query string would be written to access logs by every server and proxy on the way. */
    public static function refuseInUrl(Request $request): void
    {
        if ($request->query->has('latitude') || $request->query->has('longitude')) {
            throw ValidationException::withMessages([
                'latitude' => ['Send the position in the request body, not in the URL.'],
                'longitude' => ['Send the position in the request body, not in the URL.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body  already validated with decimal()
     */
    public static function coordinates(array $body): Coordinates
    {
        try {
            return Coordinates::fromLatitudeLongitude((float) $body['latitude'], (float) $body['longitude']);
        } catch (InvalidArgumentException $e) {
            $field = str_starts_with($e->getMessage(), 'Latitude') ? 'latitude' : 'longitude';

            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }
}
