<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureInternalRequest;
use Illuminate\Support\Facades\Route;

/*
| Health routes sit outside /api/v1 (ADR-010) and outside every middleware
| group: no session, cookie or CSRF handling, so liveness never depends on the
| database, Redis or the session store.
|
| /health/live  : public, minimal, touches no dependency.
| /health/ready : internal network only; reports dependency state.
*/
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])
    ->middleware(EnsureInternalRequest::class)
    ->name('health.ready');
