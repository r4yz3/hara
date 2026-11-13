<?php

/*
| Deployment-level feature switches (spec 4.3). Everything is off by default.
| The later-phase flags exist so configuration is stable, but no code sits
| behind them yet: turning one on changes nothing.
*/

return [
    'public_registration' => (bool) env('FEATURE_PUBLIC_REGISTRATION', false),
    'live_dispatch' => (bool) env('FEATURE_LIVE_DISPATCH', false),
    'online_payments' => (bool) env('FEATURE_ONLINE_PAYMENTS', false),
    'rider_tracking' => (bool) env('FEATURE_RIDER_TRACKING', false),
];
