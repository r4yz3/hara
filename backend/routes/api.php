<?php

use App\Http\Controllers\Api\V1\Admin\CityController;
use App\Http\Controllers\Api\V1\Admin\ExclusionController;
use App\Http\Controllers\Api\V1\Admin\ZoneController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PublicGeoController;
use App\Http\Middleware\EnsureAdminSurface;
use App\Http\Middleware\EnsureSurfaceSession;
use Illuminate\Support\Facades\Route;

/*
| Versioned API routes, served under /api/v1 (see bootstrap/app.php).
|
| A route is registered only when the feature behind it exists and is tested
| (AGENTS.md rule 3, spec 7.6). Adding one here means adding it to
| tests/Feature/RouteInventoryTest.php and to openapi/openapi.yaml as well.
*/

// Public, unauthenticated.
Route::get('/public/cities', [PublicGeoController::class, 'cities'])->middleware('throttle:120,1')->name('public.cities');
Route::get('/locations/serviceability', [PublicGeoController::class, 'serviceability'])->middleware('throttle:60,1')->name('locations.serviceability');

// Sign-in.
Route::get('/auth/csrf', [AuthController::class, 'csrf'])->middleware('throttle:60,1')->name('auth.csrf');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('auth.register');
// Per-account limiting happens in the controller; this caps one address overall.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1')->name('auth.login');

Route::middleware(EnsureSurfaceSession::class)->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me.show');

    // Staff. Each action checks its own permission and scope (ADR-005).
    Route::middleware(EnsureAdminSurface::class)->prefix('admin')->group(function (): void {
        Route::get('/cities', [CityController::class, 'index'])->name('admin.cities.index');
        Route::post('/cities', [CityController::class, 'store'])->name('admin.cities.store');
        Route::get('/cities/{city}', [CityController::class, 'show'])->name('admin.cities.show');
        Route::patch('/cities/{city}', [CityController::class, 'update'])->name('admin.cities.update');
        Route::get('/cities/{city}/zones', [ZoneController::class, 'index'])->name('admin.zones.index');
        Route::post('/cities/{city}/zones', [ZoneController::class, 'store'])->name('admin.zones.store');
        Route::get('/zones/{zone}', [ZoneController::class, 'show'])->name('admin.zones.show');
        Route::patch('/zones/{zone}', [ZoneController::class, 'update'])->name('admin.zones.update');
        Route::post('/zones/{zone}/activate', [ZoneController::class, 'activate'])->name('admin.zones.activate');
        Route::post('/zones/{zone}/pause', [ZoneController::class, 'pause'])->name('admin.zones.pause');
        Route::post('/zones/{zone}/archive', [ZoneController::class, 'archive'])->name('admin.zones.archive');
        Route::get('/zones/{zone}/exclusions', [ExclusionController::class, 'index'])->name('admin.exclusions.index');
        Route::post('/zones/{zone}/exclusions', [ExclusionController::class, 'store'])->name('admin.exclusions.store');
        Route::delete('/exclusions/{exclusion}', [ExclusionController::class, 'destroy'])->name('admin.exclusions.destroy');
    });
});
