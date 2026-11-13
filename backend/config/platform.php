<?php

/*
| Deployment-wide settings (spec 1.4). One currency per deployment for the
| MVP. The display timezone applies only to screens with no city; each city
| carries its own timezone and none is ever assumed for a city (ADR-004).
*/

return [
    'currency' => strtoupper((string) env('DEFAULT_CURRENCY', 'PHP')),
    'display_timezone' => (string) env('DEFAULT_DISPLAY_TIMEZONE', 'Asia/Manila'),
];
