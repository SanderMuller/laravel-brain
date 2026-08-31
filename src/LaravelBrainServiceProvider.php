<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain;

use Illuminate\Support\ServiceProvider;
use LaraMint\LaravelBrain\Commands\ExportContextCommand;
use LaraMint\LaravelBrain\Commands\GenerateRulesCommand;
use LaraMint\LaravelBrain\Commands\ScanCommand;
use LaraMint\LaravelBrain\Mcp\BrainMcpServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;

class LaravelBrainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-brain.php', 'laravel-brain');

        $this->registerGraphConnections();
    }

    /**
     * Expose the package's self-contained DB connections to Laravel so the
     * graph can live in a dedicated database with its own credentials,
     * without the user editing config/database.php.
     */
    private function registerGraphConnections(): void
    {
        $connections = config('laravel-brain.database.connections', []);

        if (! is_array($connections) || $connections === []) {
            return;
        }

        config([
            'database.connections' => array_merge(
                (array) config('database.connections', []),
                $connections,
            ),
        ]);
    }

    public function boot(): void
    {
        // Only register routes and commands in local environment for security
        if (! $this->app->isLocal()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-brain.php' => config_path('laravel-brain.php'),
        ], 'laravel-brain-config');

        // The graph table is created on demand by the database driver the
        // first time a scan runs (CLI or UI), so the migration is NOT loaded
        // automatically. It is still publishable for anyone who prefers to
        // manage it with `php artisan migrate`.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'laravel-brain-migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-brain');
        $this->commands([ScanCommand::class, ExportContextCommand::class, GenerateRulesCommand::class]);
        $this->loadRoutesFrom(__DIR__.'/../routes/brain.php');
        $this->registerMcpServer();
    }

    /**
     * Register the "brain" MCP server, reachable via `php artisan mcp:start brain`, so an
     * AI client can query the last-scanned graph interactively.
     *
     * Only takes effect when the optional laravel/mcp package is installed (see
     * composer.json "suggest") — a class_exists() probe, the same pattern already used at
     * runtime in BrainController::stressTest() for the optional laravel-stress integration.
     * A user who never installs laravel/mcp sees no change at all.
     */
    private function registerMcpServer(): void
    {
        if (! class_exists(Server::class)) {
            return;
        }

        if (! (bool) config('laravel-brain.mcp.enabled', true)) {
            return;
        }

        Mcp::local('brain', BrainMcpServer::class);
    }
}
