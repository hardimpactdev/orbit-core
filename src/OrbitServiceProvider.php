<?php

namespace HardImpact\Orbit;

use HardImpact\Orbit\Console\Commands\OrbitInit;
use HardImpact\Orbit\Http\Middleware\HandleInertiaRequests;
use HardImpact\Orbit\Http\Middleware\ImplicitEnvironment;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class OrbitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orbit.php', 'orbit');
    }

    public function boot(): void
    {
        $this->configureVite();
        $this->registerViews();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerMcp();
    }

    protected function registerMiddleware(): void
    {
        // Skip middleware registration in CLI context (e.g., orbit-cli)
        if ($this->app->runningInConsole() && ! $this->app->bound(Kernel::class)) {
            return;
        }

        $kernel = $this->app->make(Kernel::class);

        $kernel->appendMiddlewareToGroup('web', HandleInertiaRequests::class);

        // Register alias for route usage
        $this->app['router']->aliasMiddleware('implicit.environment', ImplicitEnvironment::class);
    }

    protected function configureVite(): void
    {
        Vite::useHotFile(__DIR__.'/../public/hot');
        Vite::useBuildDirectory('vendor/orbit/build');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'orbit');
        config(['inertia.root_view' => 'orbit::app']);
    }

    /**
     * Register the package's routes.
     * Called by consuming apps in their RouteServiceProvider or bootstrap.
     */
    public static function routes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../routes/api.php');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                OrbitInit::class,
            ]);
        }
    }

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/orbit.php' => config_path('orbit.php'),
            ], 'orbit-config');

            $this->publishes([
                __DIR__.'/../public/build' => public_path('vendor/orbit/build'),
            ], 'orbit-assets');
        }
    }

    /**
     * Register MCP routes for AI tool integration.
     * Loads when laravel/mcp is installed (supports both CLI and HTTP transports).
     */
    protected function registerMcp(): void
    {
        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            OrbitInit::class,
        ];
    }
}
