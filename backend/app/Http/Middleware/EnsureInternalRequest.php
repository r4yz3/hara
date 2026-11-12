<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureInternalRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('health.ready_allowed_cidrs', []);
        $ip = $request->ip();

        if ($ip === null || $allowed === [] || ! IpUtils::checkIp($ip, $allowed)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
