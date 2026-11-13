<?php

namespace App\Support\Errors;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The single place API errors become responses (ADR-010). The message for an
 * unexpected error is fixed text: no exception class, SQL, path or
 * configuration value is ever returned, whatever APP_DEBUG says.
 */
class ApiErrorRenderer
{
    private const BY_STATUS = [
        400 => ['BAD_REQUEST', 'The request could not be understood.'],
        401 => ['AUTH_REQUIRED', 'Sign in to continue.'],
        403 => ['FORBIDDEN', 'You are not allowed to do that.'],
        404 => ['NOT_FOUND', 'Not found.'],
        405 => ['METHOD_NOT_ALLOWED', 'That method is not allowed here.'],
        409 => ['CONFLICT', 'The resource changed. Reload and try again.'],
        419 => ['FORBIDDEN', 'The page expired. Reload and try again.'],
        429 => ['RATE_LIMITED', 'Too many attempts. Wait a moment and try again.'],
        503 => ['PROVIDER_UNAVAILABLE', 'The service is temporarily unavailable.'],
    ];

    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::response($request, 422, 'VALIDATION_ERROR', 'Please check the highlighted fields.', $e->errors());
        }

        if ($e instanceof ApiError) {
            return self::response($request, $e->status, $e->errorCode, $e->getMessage(), headers: $e->headers);
        }

        $status = match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof TokenMismatchException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof ThrottleRequestsException => 429,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };

        $headers = $e instanceof HttpExceptionInterface
            ? array_intersect_key($e->getHeaders(), ['Retry-After' => true, 'Allow' => true])
            : [];

        [$code, $message] = self::BY_STATUS[$status] ?? ($status >= 500
            ? ['SERVER_ERROR', 'Something went wrong on our side.']
            : ['BAD_REQUEST', 'The request could not be completed.']);

        return self::response($request, $status === 419 ? 403 : $status, $code, $message, headers: $headers);
    }

    /**
     * @param  array<string, list<string>>|null  $fields
     * @param  array<string, mixed>  $headers
     */
    private static function response(Request $request, int $status, string $code, string $message, ?array $fields = null, array $headers = []): JsonResponse
    {
        $id = AssignRequestId::for($request);
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        $error['request_id'] = $id;

        return response()->json(['error' => $error], $status, $headers + [AssignRequestId::HEADER => $id]);
    }
}
