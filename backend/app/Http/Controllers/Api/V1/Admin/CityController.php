<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Commands\CityService;
use App\Application\Queries\AccessChecker;
use App\Domain\Geo\CityStatus;
use App\Domain\Identity\Scope;
use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use App\Models\User;
use App\Support\Errors\ApiError;
use App\Support\Http\ApiResponse;
use App\Support\Http\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff city endpoints (spec 7.4). Every action follows ADR-005: a city the
 * caller has no read access to is reported as 404, and lists are filtered in
 * the query, never afterwards.
 */
class CityController extends Controller
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly CityService $cities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'string', Rule::in(CityStatus::values())],
        ]);

        $after = null;
        if (isset($input['cursor'])) {
            $after = Cursor::decode($input['cursor']) ?? throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        $reach = $this->access->cityReach($this->user(), 'cities.read');
        $limit = (int) ($input['limit'] ?? 25);

        $query = City::query()->orderBy('id');
        if (! $reach['all']) {
            $query->whereIn('id', $reach['city_ids']);
        }
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        $next = $rows->count() > $limit ? Cursor::encode((string) $page->last()?->id) : null;

        return ApiResponse::page($request, $page->map(fn (City $city): array => CityResource::admin($city))->values()->all(), $next);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->access->allows($this->user(), 'cities.write', Scope::global())) {
            throw ApiError::forbidden();
        }

        /** @var array{name: string, slug: string, country_code: string, currency_code: string, timezone: string} $fields */
        $fields = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:160'],
            'slug' => ['required', 'string', 'max:160', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/', Rule::unique('cities', 'slug')],
            'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            // One currency per deployment (spec 1.4).
            'currency_code' => ['required', 'string', Rule::in([(string) config('platform.currency')])],
            // Required and never defaulted (ADR-004).
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);

        $city = $this->cities->create((string) $this->user()->id, $fields);

        return ApiResponse::data($request, CityResource::admin($city), 201);
    }

    public function show(Request $request, string $city): JsonResponse
    {
        return ApiResponse::data($request, CityResource::admin($this->readable($city)));
    }

    public function update(Request $request, string $city): JsonResponse
    {
        $found = $this->readable($city);

        if (! $this->access->allows($this->user(), 'cities.write', Scope::city($found->id))) {
            throw ApiError::forbidden();
        }

        // Slug, country and currency are fixed after creation; they are not
        // listed here, so sending them changes nothing.
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:160'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'status' => ['sometimes', 'string', Rule::in(CityStatus::values())],
        ]);

        /** @var array{name?: string, timezone?: string} $fields */
        $fields = array_intersect_key($input, ['name' => true, 'timezone' => true]);
        $status = isset($input['status']) ? CityStatus::from($input['status']) : null;

        $updated = $this->cities->update((string) $this->user()->id, $found->id, $fields, $status);

        return ApiResponse::data($request, CityResource::admin($updated));
    }

    /**
     * The city, if it exists and the caller may read it; otherwise 404 either way.
     */
    private function readable(string $id): City
    {
        $id = strtolower($id);

        if (preg_match('/\A[0-9a-z]{26}\z/', $id) !== 1
            || ! $this->access->allows($this->user(), 'cities.read', Scope::city($id))) {
            throw ApiError::notFound();
        }

        return City::query()->find($id) ?? throw ApiError::notFound();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
