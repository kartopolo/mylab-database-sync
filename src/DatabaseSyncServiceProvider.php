<?php

namespace MylabDatabaseSync;

use Illuminate\Support\ServiceProvider;
use MylabDatabaseSync\Commands\SetupTriggersCommand;
use MylabDatabaseSync\Commands\SyncDatabaseCommand;
use MylabDatabaseSync\Commands\CleanupAuditLogCommand;
use MylabDatabaseSync\Commands\InitialSyncCommand;
use MylabDatabaseSync\Services\DatabaseSyncService;
use MylabDatabaseSync\Services\TableDiscoveryService;
use MylabDatabaseSync\Services\TypeMapperService;

class DatabaseSyncServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync.php', 'database-sync');
        
        $this->app->singleton(DatabaseSyncService::class);
        $this->app->singleton(TableDiscoveryService::class);
        $this->app->singleton(TypeMapperService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/sync.php' => config_path('database-sync.php'),
        ], 'database-sync-config');
        
        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'database-sync-migrations');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SetupTriggersCommand::class,
                SyncDatabaseCommand::class,
                CleanupAuditLogCommand::class,
                InitialSyncCommand::class,
            ]);
        }
    }
}
