<?php

/*
| Proxies whose X-Forwarded-For, -Proto and -Port headers are believed
| (ADR-014). Behind the host's TLS-terminating proxy this must list that
| proxy's address or range, or Laravel will treat every request as plain
| HTTP and never send HSTS.
|
| TRUSTED_PROXIES is a comma-separated list of IP addresses or CIDR ranges.
| Empty by default: nothing forwarded is believed. "*" (trust everyone) is
| refused by ProductionGuard, because it lets any client fake its own address
| and so slip past per-address rate limits.
|
| X-Forwarded-Host is never trusted (bootstrap/app.php): URLs come from APP_URL.
*/

return [
    'proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        fn (string $proxy): bool => $proxy !== '',
    )),
];
