<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Commands;

use Illuminate\Console\Command;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\OpenApi\OpenApiBuilder;

class ExportOpenApiCommand extends Command
{
    protected $signature = 'brain:export-openapi
                            {--output= : Write to file path instead of stdout}
                            {--title= : OpenAPI info.title (defaults to app.name)}
                            {--api-version=1.0.0 : OpenAPI info.version}
                            {--force : Overwrite existing output file without prompting}';

    protected $description = 'Generate an OpenAPI 3.0 spec from the application routes';

    public function handle(): int
    {
        $projectRoot = base_path();

        $routePaths = config('laravel-brain.route_paths', ['routes/*/*.php']);
        $autoDiscover = (bool) config('laravel-brain.auto_discover_routes', false);
        $excludeVendor = (bool) config('laravel-brain.auto_discover_exclude_vendor', true);

        $routes = (new RouteAnalyzer($routePaths, $autoDiscover, $excludeVendor))->analyze($projectRoot);
        $controllers = (new ControllerAnalyzer)->analyze($projectRoot, $routes);

        $title = (string) ($this->option('title') ?: (config('app.name') ?: 'Laravel API'));
        $version = (string) ($this->option('api-version') ?: '1.0.0');

        $spec = (new OpenApiBuilder)->build($routes, $controllers, $title, $version);

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode OpenAPI spec as JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $outputPath = $this->option('output') ? (string) $this->option('output') : null;

        if ($outputPath === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        if (file_exists($outputPath) && ! $this->option('force')) {
            if (! $this->confirm("<fg=yellow>{$outputPath}</> already exists. Overwrite?", false)) {
                $this->line('<fg=yellow>Aborted.</> No file written.');

                return self::SUCCESS;
            }
        }

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $json);
        $this->line("  <fg=green>✓</> OpenAPI spec written to <fg=cyan>{$outputPath}</>");
        $this->line('  <fg=gray>'.count($spec['paths'] ?? []).' path(s)</>');

        return self::SUCCESS;
    }
}
