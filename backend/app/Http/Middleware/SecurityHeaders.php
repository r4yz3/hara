<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (backlog E-02). The API serves
 * JSON only, never a page, so its policy allows nothing to load, frame or
 * submit. API responses are never cached: many carry a person's data.
 *
 * X-Powered-By is set by PHP itself, before this runs; `expose_php = Off` in
 * the image removes it at the source and the removal here covers anything
 * else that adds it.
 */
class SecurityHeaders
{
    public const CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Content-Security-Policy', self::CSP);
        $headers->remove('X-Powered-By');

        if ($request->is('api/*')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        // Only over HTTPS, where the browser honours it. No includeSubDomains or
        // preload: those bind every sibling host and need the domain decision.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
