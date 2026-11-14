<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Commands\SavedAddressService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use App\Http\Requests\SavedAddressRequest;
use App\Http\Resources\SavedAddressResource;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * The signed-in customer's saved delivery addresses (customer surface only).
 * Positions and address text are personal data: they live in the request
 * body and the response body, never in a URL, a log line or an audit row.
 */
class SavedAddressController extends Controller
{
    public function __construct(
        private readonly SavedAddressService $addresses,
        private readonly SavedAddressResource $resource,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = array_map(fn (stdClass $row): array => $this->resource->toArray($row), $this->addresses->list($this->user()));

        return response()->json([
            'data' => $rows,
            'meta' => ['request_id' => AssignRequestId::for($request)],
        ]);
    }

    public function store(SavedAddressRequest $request): JsonResponse
    {
        $row = $this->addresses->create($this->user(), $request->validated(), $request->ip(), AssignRequestId::for($request));

        return ApiResponse::data($request, $this->resource->toArray($row), 201);
    }

    public function update(SavedAddressRequest $request, string $address): JsonResponse
    {
        // Check the id before the body is judged? The form request has already
        // run; a 422 on a foreign id leaks nothing because validation does not
        // look at the database.
        $row = $this->addresses->update(
            $this->user(), SavedAddressService::normaliseId($address), $request->validated(), $request->ip(), AssignRequestId::for($request),
        );

        return ApiResponse::data($request, $this->resource->toArray($row));
    }

    public function destroy(Request $request, string $address): Response
    {
        $this->addresses->delete($this->user(), SavedAddressService::normaliseId($address), $request->ip(), AssignRequestId::for($request));

        return response()->noContent();
    }

    public function makeDefault(Request $request, string $address): JsonResponse
    {
        $row = $this->addresses->makeDefault(
            $this->user(), SavedAddressService::normaliseId($address), $request->ip(), AssignRequestId::for($request),
        );

        return ApiResponse::data($request, $this->resource->toArray($row));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
