<?php

namespace App\Support\Http;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The success envelope (ADR-010): {"data": ..., "meta": {"request_id": ...}}.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => AssignRequestId::for($request)],
        ], $status);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function page(Request $request, array $items, ?string $nextCursor): JsonResponse
    {
        return response()->json([
            'data' => $items,
            'meta' => ['request_id' => AssignRequestId::for($request), 'next_cursor' => $nextCursor],
        ]);
    }
}
