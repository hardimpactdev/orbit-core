<?php

namespace HardImpact\Orbit;

use HardImpact\Orbit\Console\Commands\OrbitInit;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrbitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orbit.php', 'orbit');
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerMigrations();
        $this->registerPublishing();
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
