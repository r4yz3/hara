<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which browser surface a request comes from and gives that
 * surface its own session cookie (ADR-011). Must run before the session
 * starts.
 *
 * The surface comes from the Origin header matching a configured app origin
 * exactly. The header is set by the browser, not by page script, so a page on
 * another site cannot claim to be one of ours; CORS then stops it reading
 * anything anyway. A request with no matching origin has no surface and gets
 * no session.
 */
class ResolveSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        $surface = self::detect($request);
        $request->attributes->set('surface', $surface);

        if ($surface !== null) {
            config(['session.cookie' => "fd_{$surface}_session"]);
        } else {
            // Without these headers Sanctum treats the request as stateless.
            $request->headers->remove('referer');
            $request->headers->remove('origin');
        }

        return $next($request);
    }

    public static function of(Request $request): ?string
    {
        $surface = $request->attributes->get('surface');

        return is_string($surface) ? $surface : null;
    }

    private static function detect(Request $request): ?string
    {
        $origin = $request->headers->get('origin');

        if (! is_string($origin)) {
            return null;
        }

        /** @var array<string, string> $origins */
        $origins = config('surfaces.origins', []);
        $surface = array_search(rtrim($origin, '/'), $origins, true);

        return is_string($surface) ? $surface : null;
    }
}
