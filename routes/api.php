<?php

use HardImpact\Orbit\Http\Controllers\EnvironmentController;
use HardImpact\Orbit\Http\Controllers\JobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless (no session) to avoid session locking.
| This allows them to run in parallel without blocking Inertia navigation.
|
*/

Route::prefix('environments/{environment}')->group(function (): void {
    // Dashboard data endpoints
    Route::post('test-connection', [EnvironmentController::class, 'testConnection']);
    Route::get('status', [EnvironmentController::class, 'status']);
    Route::get('sites', [EnvironmentController::class, 'sites']);
    Route::get('config', [EnvironmentController::class, 'getConfig']);
    Route::get('worktrees', [EnvironmentController::class, 'worktrees']);

    // Async data loading endpoints
    Route::get('projects', [EnvironmentController::class, 'projectsApi']);
    Route::get('workspaces', [EnvironmentController::class, 'workspacesApi']);
    Route::get('workspaces/{workspace}', [EnvironmentController::class, 'workspaceApi']);

    // Service control endpoints (stateless API for Vue async calls)
    Route::post('start', [EnvironmentController::class, 'start']);
    Route::post('stop', [EnvironmentController::class, 'stop']);
    Route::post('restart', [EnvironmentController::class, 'restart']);

    // Individual service routes
    Route::get('services/available', [EnvironmentController::class, 'availableServices']);
    Route::post('services/{service}/start', [EnvironmentController::class, 'startService']);
    Route::post('services/{service}/stop', [EnvironmentController::class, 'stopService']);
    Route::post('services/{service}/restart', [EnvironmentController::class, 'restartService']);
    Route::post('host-services/{service}/start', [EnvironmentController::class, 'startHostService']);
    Route::post('host-services/{service}/stop', [EnvironmentController::class, 'stopHostService']);
    Route::post('host-services/{service}/restart', [EnvironmentController::class, 'restartHostService']);
    Route::get('services/{service}/logs', [EnvironmentController::class, 'serviceLogs']);
    Route::get('host-services/{service}/logs', [EnvironmentController::class, 'hostServiceLogs']);
    Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService']);
    Route::delete('services/{service}', [EnvironmentController::class, 'disableService']);
    Route::put('services/{service}/config', [EnvironmentController::class, 'configureService']);
    Route::get('services/{service}/info', [EnvironmentController::class, 'serviceInfo']);

    // PHP Configuration
    Route::get('php/config/{version?}', [EnvironmentController::class, 'getPhpConfig']);
    Route::post('php/config/{version?}', [EnvironmentController::class, 'setPhpConfig']);
});

// Project routes (without environment prefix - used when remoteApiUrl is set)
// These are accessed directly via orbit.{tld}/api/projects/{slug}
// Uses implicit.environment middleware to inject the local environment
Route::middleware('implicit.environment')->group(function (): void {
    // Legacy flat routes for desktop app compatibility
    Route::get('status', [EnvironmentController::class, 'status']);
    Route::get('sites', [EnvironmentController::class, 'sites']);
    Route::get('config', [EnvironmentController::class, 'getConfig']);
    Route::get('worktrees', [EnvironmentController::class, 'worktrees']);
    Route::get('projects', [EnvironmentController::class, 'projectsApi']);
    Route::get('workspaces', [EnvironmentController::class, 'workspacesApi']);

    Route::post('start', [EnvironmentController::class, 'start']);
    Route::post('stop', [EnvironmentController::class, 'stop']);
    Route::post('restart', [EnvironmentController::class, 'restart']);

    Route::post('projects', [EnvironmentController::class, 'storeProject']);
    Route::delete('projects/{projectName}', [EnvironmentController::class, 'destroyProject']);
    Route::post('projects/{projectName}/rebuild', [EnvironmentController::class, 'rebuildProject']);
    Route::get('projects/{projectSlug}/provision-status', [EnvironmentController::class, 'provisionStatus']);

    // Service control endpoints (legacy paths)
    Route::get('services/status', [EnvironmentController::class, 'status']);
    Route::post('services/{service}/start', [EnvironmentController::class, 'startService']);
    Route::post('services/{service}/stop', [EnvironmentController::class, 'stopService']);
    Route::post('services/{service}/restart', [EnvironmentController::class, 'restartService']);
    Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService']);
    Route::post('services/{service}/disable', [EnvironmentController::class, 'disableService']);

    // PHP Management
    Route::get('php-versions', function () {
        return response()->json([
            'success' => true,
            'versions' => ['8.3', '8.4', '8.5'],
        ]);
    });

    // Jobs
    Route::get('jobs/{trackedJob}', [JobController::class, 'show']);

    // Route discovery for verification
    Route::get('routes', function () {
        return collect(Route::getRoutes())->map(function ($route) {
            return $route->uri();
        });
    });
});
