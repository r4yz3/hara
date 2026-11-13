<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\ExclusionService;
use App\Application\Queries\AccessChecker;
use App\Domain\Geo\GeometryLimits;
use App\Domain\Geo\InvalidZoneGeometry;
use App\Domain\Geo\ZoneGeometry;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Staff endpoints for coverage exclusions (backlog C-07). Access follows the
 * zone's city (ADR-005): unreadable is 404, readable but not writable is 403.
 * The city always comes from the stored zone.
 */
class ExclusionController extends Controller
{
    private const ID = '/\A[0-9a-z]{26}\z/';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly ExclusionService $exclusions,
    ) {}

    public function index(Request $request, string $zone): JsonResponse
    {
        [$zoneId] = $this->readableZone($zone);
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
        $limit = (int) ($input['limit'] ?? 25);

        $query = $this->select()->where('delivery_zone_id', $zoneId)->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $page->map(fn (object $row): array => $this->resource($row))->values()->all(), $next);
    }

    public function store(Request $request, string $zone): JsonResponse
    {
        [$zoneId, $cityId] = $this->readableZone($zone);
        $this->requireWrite($cityId);

        $input = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:255'],
            'geometry' => ['required', 'array'],
            // Full timestamps with an offset only; stored in UTC.
            'active_from' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'active_until' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
        ]);

        $from = $this->utc($input['active_from'] ?? null);
        $until = $this->utc($input['active_until'] ?? null);
        if ($from !== null && $until !== null && $until <= $from) {
            throw ValidationException::withMessages(['active_until' => ['The end must be after the start.']]);
        }

        try {
            $geometry = ZoneGeometry::fromGeoJson($input['geometry'], new GeometryLimits(
                (int) config('geo.zone_max_vertices'),
                (float) config('geo.zone_max_extent_degrees'),
            ));
        } catch (InvalidZoneGeometry $e) {
            Log::notice('zone.validation_failed', ['shape' => 'exclusion', 'zone_id' => $zoneId, 'reason' => $e->reason]);
            throw ValidationException::withMessages(['geometry' => [$e->getMessage()]]);
        }

        $id = $this->exclusions->create((string) $this->user()->id, $zoneId, (string) $input['reason'], $geometry, $from, $until);
        $row = $this->select()->where('id', $id)->first() ?? throw ApiError::notFound();

        return ApiResponse::data($request, $this->resource($row), 201);
    }

    public function destroy(string $exclusion): Response
    {
        $id = strtolower($exclusion);
        $cityId = preg_match(self::ID, $id) === 1
            ? DB::table('coverage_exclusions as e')->join('delivery_zones as z', 'z.id', '=', 'e.delivery_zone_id')->where('e.id', $id)->value('z.city_id')
            : null;

        if ($cityId === null || ! $this->access->allows($this->user(), 'zones.read', Scope::city((string) $cityId))) {
            throw ApiError::notFound();
        }
        $this->requireWrite((string) $cityId);

        $this->exclusions->delete((string) $this->user()->id, $id);

        return response()->noContent();
    }

    private function select(): Builder
    {
        return DB::table('coverage_exclusions')
            ->select(['id', 'delivery_zone_id', 'reason', 'active_from', 'active_until', 'created_at', 'updated_at'])
            ->selectRaw('ST_AsGeoJSON(geometry, 12) AS geometry');
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        $time = fn (?string $value): ?string => $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $row->id,
            'zone_id' => $row->delivery_zone_id,
            'reason' => $row->reason,
            'active_from' => $time($row->active_from),
            'active_until' => $time($row->active_until),
            'geometry' => json_decode((string) $row->geometry, true, 512, JSON_THROW_ON_ERROR),
            'created_at' => $time($row->created_at),
            'updated_at' => $time($row->updated_at),
        ];
    }

    private function utc(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:sP');
    }

    /** @return array{string, string} zone id and its city id */
    private function readableZone(string $id): array
    {
        $id = strtolower($id);
        $cityId = preg_match(self::ID, $id) === 1
            ? DB::table('delivery_zones')->where('id', $id)->value('city_id')
            : null;

        if ($cityId === null || ! $this->access->allows($this->user(), 'zones.read', Scope::city((string) $cityId))) {
            throw ApiError::notFound();
        }

        return [$id, (string) $cityId];
    }

    private function requireWrite(string $cityId): void
    {
        if (! $this->access->allows($this->user(), 'zones.write', Scope::city($cityId))) {
            throw ApiError::forbidden();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
