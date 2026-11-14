<?php

use App\Http\Controllers\Api\V1\Admin\CityController;
use App\Http\Controllers\Api\V1\Admin\ExclusionController;
use App\Http\Controllers\Api\V1\Admin\OversightController;
use App\Http\Controllers\Api\V1\Admin\StaffAccessController;
use App\Http\Controllers\Api\V1\Admin\StaffInvitationController;
use App\Http\Controllers\Api\V1\Admin\ZoneController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\PublicGeoController;
use App\Http\Controllers\Api\V1\RiderAuthController;
use App\Http\Controllers\Api\V1\StaffInvitationAcceptController;
use App\Http\Middleware\EnsureAdminSurface;
use App\Http\Middleware\EnsureRiderToken;
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
Route::get('/public/config', [PublicGeoController::class, 'config'])->middleware('throttle:public-config')->name('public.config');
Route::get('/public/cities', [PublicGeoController::class, 'cities'])->middleware('throttle:public-cities')->name('public.cities');
// POST on purpose: the position travels in the body, never in the URL (ADR-013).
Route::post('/locations/serviceability', [PublicGeoController::class, 'serviceability'])->middleware('throttle:locations-serviceability')->name('locations.serviceability');

// Sign-in.
Route::get('/auth/csrf', [AuthController::class, 'csrf'])->middleware('throttle:auth-csrf')->name('auth.csrf');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register')->name('auth.register');
// Per-account limiting happens in the controller; this caps one address overall.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login')->name('auth.login');
Route::post('/auth/mfa/verify', [AuthController::class, 'verifyMfa'])->middleware('throttle:auth-mfa-verify')->name('auth.mfa.verify');
Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-password-forgot')->name('auth.password.forgot');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-password-reset')->name('auth.password.reset');
// The invitee holds only the emailed token, sent in the body (never a URL). Failures all answer alike.
Route::post('/auth/invitations/inspect', [StaffInvitationAcceptController::class, 'inspect'])->middleware('throttle:invitation-inspect')->name('auth.invitations.inspect');
Route::post('/auth/invitations/accept', [StaffInvitationAcceptController::class, 'accept'])->middleware('throttle:invitation-accept')->name('auth.invitations.accept');

// Rider app: bearer tokens, not cookies. Per-account limiting is in the controller.
Route::post('/auth/mobile/token', [RiderAuthController::class, 'issue'])->middleware('throttle:auth-mobile-token')->name('auth.mobile.token');

Route::middleware(EnsureRiderToken::class)->group(function (): void {
    Route::post('/auth/mobile/token/refresh', [RiderAuthController::class, 'refresh'])->name('auth.mobile.refresh');
    Route::delete('/auth/mobile/token', [RiderAuthController::class, 'revoke'])->name('auth.mobile.revoke');
    Route::get('/rider/me', [RiderAuthController::class, 'me'])->name('rider.me');
});

Route::middleware(EnsureSurfaceSession::class)->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me.show');
    Route::post('/me/mfa/totp', [MfaController::class, 'start'])->name('me.mfa.totp.start');
    Route::post('/me/mfa/totp/confirm', [MfaController::class, 'confirm'])->name('me.mfa.totp.confirm');
    Route::post('/me/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->name('me.mfa.recovery-codes.regenerate');

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
        Route::get('/users', [StaffAccessController::class, 'index'])->name('admin.users.index');
        Route::get('/roles', [StaffAccessController::class, 'roles'])->name('admin.roles.index');
        Route::post('/users/{user}/roles', [StaffAccessController::class, 'grant'])->name('admin.users.roles.store');
        Route::delete('/users/{user}/roles/{grant}', [StaffAccessController::class, 'revoke'])->name('admin.users.roles.destroy');
        Route::post('/users/{user}/mfa/reset', [MfaController::class, 'reset'])->name('admin.users.mfa.reset');
        Route::get('/invitations', [StaffInvitationController::class, 'index'])->name('admin.invitations.index');
        Route::post('/invitations', [StaffInvitationController::class, 'store'])->middleware('throttle:admin-invitations-store')->name('admin.invitations.store');
        Route::delete('/invitations/{invitation}', [StaffInvitationController::class, 'destroy'])->name('admin.invitations.destroy');
        Route::get('/audit-logs', [OversightController::class, 'auditLogs'])->name('admin.audit-logs.index');
        Route::get('/system/status', [OversightController::class, 'systemStatus'])->name('admin.system.status');
    });
});
