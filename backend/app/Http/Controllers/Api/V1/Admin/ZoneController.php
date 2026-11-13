<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\ZoneService;
use App\Application\Queries\AccessChecker;
use App\Domain\Geo\CityStatus;
use App\Domain\Geo\GeometryLimits;
use App\Domain\Geo\InvalidZoneGeometry;
use App\Domain\Geo\ZoneGeometry;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Http\Resources\ZoneResource;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff zone endpoints (backlog C-04). Same rules as cities (ADR-005): a
 * zone or city the caller cannot read is 404, and the city scope comes from
 * the stored zone, never from the request.
 */
class ZoneController extends Controller
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly ZoneService $zones,
    ) {}

    public function index(Request $request, string $city): JsonResponse
    {
        $cityId = $this->readableCity($city);
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }
        $limit = (int) ($input['limit'] ?? 25);

        $query = ZoneResource::query(withGeometry: false)->where('city_id', $cityId)->orderBy('id');
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $page->map(fn (object $row): array => ZoneResource::admin($row))->values()->all(), $next);
    }

    public function store(Request $request, string $city): JsonResponse
    {
        $cityId = $this->readableCity($city);
        $this->requireWrite($cityId);

        $input = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:160'],
            'slug' => [
                'required', 'string', 'max:160', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/',
                Rule::unique('delivery_zones', 'slug')->where('city_id', $cityId),
            ],
            'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'geometry' => ['required', 'array'],
        ]);

        $id = $this->zones->create((string) $this->user()->id, $cityId, [
            'name' => (string) $input['name'],
            'slug' => (string) $input['slug'],
            'priority' => (int) ($input['priority'] ?? 0),
        ], $this->geometry($input['geometry']));

        return ApiResponse::data($request, $this->load($id), 201);
    }

    public function show(Request $request, string $zone): JsonResponse
    {
        return ApiResponse::data($request, $this->load($this->readableZone($zone)));
    }

    public function update(Request $request, string $zone): JsonResponse
    {
        $id = $this->readableZone($zone);
        $this->requireWrite((string) DB::table('delivery_zones')->where('id', $id)->value('city_id'));

        // Slug and city are fixed after creation; status has its own routes.
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:160'],
            'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'geometry' => ['sometimes', 'array'],
        ]);

        /** @var array{name?: string, priority?: int} $fields */
        $fields = array_intersect_key($input, ['name' => true, 'priority' => true]);
        $geometry = isset($input['geometry']) ? $this->geometry($input['geometry']) : null;

        $this->zones->update((string) $this->user()->id, $id, (int) $input['version'], $fields, $geometry);

        return ApiResponse::data($request, $this->load($id));
    }

    /**
     * Putting a zone live is confirmed explicitly (spec 6.4): the request
     * must carry `confirm: true`.
     */
    public function activate(Request $request, string $zone): JsonResponse
    {
        return $this->transition($request, $zone, CityStatus::Active, ['confirm' => ['required', 'accepted']]);
    }

    public function pause(Request $request, string $zone): JsonResponse
    {
        return $this->transition($request, $zone, CityStatus::Paused, ['reason' => ['required', 'string', 'min:1', 'max:255']]);
    }

    public function archive(Request $request, string $zone): JsonResponse
    {
        return $this->transition($request, $zone, CityStatus::Archived, ['reason' => ['sometimes', 'string', 'min:1', 'max:255']]);
    }

    /** @param  array<string, list<string>>  $rules */
    private function transition(Request $request, string $zone, CityStatus $target, array $rules): JsonResponse
    {
        $id = $this->readableZone($zone);
        $this->requireWrite((string) DB::table('delivery_zones')->where('id', $id)->value('city_id'));

        $input = $request->validate(['version' => ['required', 'integer', 'min:1']] + $rules);
        $reason = isset($input['reason']) ? (string) $input['reason'] : null;

        $this->zones->changeStatus((string) $this->user()->id, $id, (int) $input['version'], $target, $reason);

        return ApiResponse::data($request, $this->load($id));
    }

    /** @param  array<mixed>  $geoJson */
    private function geometry(array $geoJson): ZoneGeometry
    {
        try {
            return ZoneGeometry::fromGeoJson($geoJson, new GeometryLimits(
                (int) config('geo.zone_max_vertices'),
                (float) config('geo.zone_max_extent_degrees'),
            ));
        } catch (InvalidZoneGeometry $e) {
            throw ValidationException::withMessages(['geometry' => [$e->getMessage()]]);
        }
    }

    /** @return array<string, mixed> */
    private function load(string $id): array
    {
        $row = ZoneResource::query(withGeometry: true)->where('id', $id)->first() ?? throw ApiError::notFound();

        return ZoneResource::admin($row);
    }

    private function readableCity(string $id): string
    {
        $id = strtolower($id);
        if (preg_match('/\A[0-9a-z]{26}\z/', $id) !== 1
            || ! $this->access->allows($this->user(), 'zones.read', Scope::city($id))
            || ! DB::table('cities')->where('id', $id)->exists()) {
            throw ApiError::notFound();
        }

        return $id;
    }

    private function readableZone(string $id): string
    {
        $id = strtolower($id);
        $cityId = preg_match('/\A[0-9a-z]{26}\z/', $id) === 1
            ? DB::table('delivery_zones')->where('id', $id)->value('city_id')
            : null;

        if ($cityId === null || ! $this->access->allows($this->user(), 'zones.read', Scope::city((string) $cityId))) {
            throw ApiError::notFound();
        }

        return $id;
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
