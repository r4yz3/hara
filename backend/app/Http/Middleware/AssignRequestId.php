<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request an id that appears in the response header, the
 * response body and the logs (ADR-010). A caller's own id is kept only if it
 * is short and made of harmless characters; anything else is replaced.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::for($request);
        Log::withContext(['request_id' => $id]);
        $started = hrtime(true);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        self::logRequest($request, $response, $started);

        return $response;
    }

    /**
     * The `http.request` event (spec 11.3). The route template is logged, not
     * the path or query string, so ids and any personal data in the URL stay
     * out of the log; an unmatched path is logged as null.
     */
    private static function logRequest(Request $request, Response $response, int $started): void
    {
        $status = $response->getStatusCode();
        $context = [
            'method' => $request->getMethod(),
            'route' => $request->route()?->uri(),
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ];

        if ($status >= 500) {
            Log::error('http.request', $context);
        } else {
            Log::info('http.request', $context);
        }
    }

    /**
     * Also used by the exception renderer, which can run before this
     * middleware has (for example on an unknown route).
     */
    public static function for(Request $request): string
    {
        $existing = $request->attributes->get('request_id');
        if (is_string($existing)) {
            return $existing;
        }

        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/\A[A-Za-z0-9_-]{8,64}\z/', $incoming) === 1
            ? $incoming
            : strtolower((string) Str::ulid());

        $request->attributes->set('request_id', $id);

        return $id;
    }
}
