<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use LaraMint\LaravelBrain\Ai\ContextExporter;
use LaraMint\LaravelBrain\Ai\RulesExporter;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\OpenApi\OpenApiBuilder;

class BrainController extends Controller
{
    // ── Source ────────────────────────────────────────────────────────────────

    public function source(Request $request): JsonResponse
    {
        $filePath = $request->query('path', '');

        if (! $filePath || ! file_exists($filePath) || pathinfo($filePath, PATHINFO_EXTENSION) !== 'php') {
            return response()->json(['error' => 'File not found'], 404);
        }

        return response()->json(['content' => file_get_contents($filePath)]);
    }

    // ── Scan ──────────────────────────────────────────────────────────────────

    public function scan(Request $request): JsonResponse
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $projectPath = base_path();
        $analyzer = new ProjectAnalyzer;

        ob_start();
        $result = $analyzer->analyze($projectPath);
        ob_end_clean();

        $storageDir = storage_path('app/laravel-brain');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        file_put_contents($storageDir.'/.graph-manifest.json', $result->manifestJson);
        file_put_contents($storageDir.'/.graph-all.json', $result->fullGraph->toJson());

        foreach ($result->subgraphs as $tabId => $subgraph) {
            file_put_contents($storageDir."/.graph-{$tabId}.json", $subgraph->toJson());
        }

        return response()->json([
            'success' => true,
            'message' => 'Project scan completed successfully.',
            'analyzedAt' => $result->analyzedAt,
        ]);
    }

    // ── AI context export ─────────────────────────────────────────────────────

    public function context(Request $request): Response
    {
        $storageDir = storage_path('app/laravel-brain');

        if (! file_exists($storageDir.'/.graph-all.json')) {
            return response(
                'No scan data found — run php artisan brain:scan first',
                404,
                ['Content-Type' => 'text/plain']
            );
        }

        $nodeId = $request->query('nodeId') ? (string) $request->query('nodeId') : null;
        $route = $request->query('route') ? (string) $request->query('route') : null;
        $budget = max(500, min(50000, (int) $request->query('budget', 6000)));
        $format = in_array($request->query('format', 'markdown'), ['markdown', 'json'], true)
            ? (string) $request->query('format', 'markdown')
            : 'markdown';

        $exporter = new ContextExporter($storageDir, base_path());

        try {
            $output = $exporter->export(
                nodeId: $nodeId,
                routeLabel: $route,
                budget: $budget,
                format: $format,
            );
        } catch (\RuntimeException $e) {
            return response($e->getMessage(), 400, ['Content-Type' => 'text/plain']);
        }

        $contentType = $format === 'json'
            ? 'application/json'
            : 'text/markdown; charset=utf-8';

        return response($output, 200, ['Content-Type' => $contentType]);
    }

    // ── OpenAPI export ────────────────────────────────────────────────────────

    public function openapi(Request $request): Response
    {
        $projectPath = base_path();

        $routePaths = config('laravel-brain.route_paths', ['routes/*/*.php']);
        $autoDiscover = (bool) config('laravel-brain.auto_discover_routes', false);
        $excludeVendor = (bool) config('laravel-brain.auto_discover_exclude_vendor', true);

        $routes = (new RouteAnalyzer($routePaths, $autoDiscover, $excludeVendor))->analyze($projectPath);
        $controllers = (new ControllerAnalyzer)->analyze($projectPath, $routes);

        $title = (string) ($request->query('title') ?: (config('app.name') ?: 'Laravel API'));
        $version = (string) ($request->query('apiVersion') ?: '1.0.0');

        $spec = (new OpenApiBuilder)->build($routes, $controllers, $title, $version);
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return response('Failed to encode OpenAPI spec: '.json_last_error_msg(), 500, ['Content-Type' => 'text/plain']);
        }

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="openapi.json"',
        ]);
    }

    // ── AI rules generation ───────────────────────────────────────────────────

    public function generateRules(Request $request): JsonResponse
    {
        $storageDir = storage_path('app/laravel-brain');

        if (! file_exists($storageDir.'/.graph-all.json')) {
            return response()->json([
                'error' => 'No scan data found — run php artisan brain:scan first',
            ], 404);
        }

        $validTargets = array_keys(RulesExporter::TARGETS);
        $requested = $request->input('targets', $validTargets);

        if (! is_array($requested) || empty($requested)) {
            $requested = $validTargets;
        }

        $unknown = array_diff($requested, $validTargets);
        if (! empty($unknown)) {
            return response()->json([
                'error' => 'Unknown target(s): '.implode(', ', $unknown),
            ], 422);
        }

        $force = (bool) $request->input('force', false);
        $exporter = new RulesExporter($storageDir, base_path());

        // Check for existing files before writing (unless force is set)
        if (! $force) {
            $existing = [];
            foreach ($requested as $target) {
                $destPath = $exporter->targetPath($target);
                if (file_exists($destPath)) {
                    $existing[] = [
                        'target' => $target,
                        'label' => RulesExporter::TARGETS[$target]['label'],
                        'path' => str_replace(base_path().'/', '', $destPath),
                    ];
                }
            }

            if (! empty($existing)) {
                return response()->json([
                    'existing' => $existing,
                    'message' => count($existing).' file(s) already exist. Pass force=true to overwrite.',
                ], 409);
            }
        }

        $results = [];

        foreach ($requested as $target) {
            $label = RulesExporter::TARGETS[$target]['label'];
            $destPath = $exporter->targetPath($target);
            $relative = str_replace(base_path().'/', '', $destPath);

            try {
                $content = $exporter->generate($target);
                $dir = dirname($destPath);

                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                file_put_contents($destPath, $content);

                $results[] = [
                    'target' => $target,
                    'label' => $label,
                    'path' => $relative,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'target' => $target,
                    'label' => $label,
                    'path' => $relative,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    // ── Stress test ───────────────────────────────────────────────────────────

    public function stressTest(Request $request): JsonResponse
    {
        if (! class_exists('LaraMint\LaravelStress\StressTestRunner')) {
            return response()->json(['error' => 'The laramint/laravel-stress package is not installed.'], 501);
        }

        set_time_limit(120);

        $validated = $request->validate([
            'method' => 'required|in:GET,POST,PUT,PATCH,DELETE,HEAD',
            'url' => 'required|url',
            'count' => 'required|integer|min:1|max:200',
            'concurrency' => 'required|integer|min:1|max:20',
            'headers' => 'nullable|array',
            'body' => 'nullable|string',
            'timeout' => 'nullable|numeric|min:1|max:30',
            'includeCsrf' => 'nullable|boolean',
        ]);

        if (! $this->isAllowedHost($validated['url'])) {
            return response()->json(
                ['error' => 'URL restricted to localhost, 127.0.0.1, *.test, or *.local'],
                422
            );
        }

        if ($validated['includeCsrf'] ?? false) {
            $headers = $validated['headers'] ?? [];
            $cookieHeader = $request->header('Cookie', '');

            if (! isset($headers['X-CSRF-TOKEN']) && ! isset($headers['X-XSRF-TOKEN'])) {
                // The brain routes have no session middleware, so csrf_token() would
                // return a token for a different session than the browser's.
                // Instead, extract the XSRF-TOKEN cookie the browser already holds —
                // it contains the encrypted CSRF token Laravel set for this session.
                // Sending it as X-XSRF-TOKEN lets Laravel decrypt and verify it normally.
                foreach (explode(';', $cookieHeader) as $part) {
                    $part = trim($part);
                    if (str_starts_with($part, 'XSRF-TOKEN=')) {
                        $headers['X-XSRF-TOKEN'] = urldecode(substr($part, strlen('XSRF-TOKEN=')));
                        break;
                    }
                }
            }

            // Forward the full Cookie header so the session can be loaded and
            // the CSRF token verified against it.
            if (! isset($headers['Cookie']) && $cookieHeader !== '') {
                $headers['Cookie'] = $cookieHeader;
            }

            $validated['headers'] = $headers;
        }

        try {
            $stress = app('LaraMint\LaravelStress\StressTestRunner');

            // Background strategy: respond immediately so the web-server thread
            // is freed before the Guzzle pool makes requests back to it.
            // This prevents the single-threaded `php artisan serve` deadlock.
            $jobId = $stress->startBackground($validated);

            if ($jobId !== null) {
                return response()->json(['jobId' => $jobId, 'status' => 'running']);
            }

            // Synchronous fallback for multi-threaded servers (Nginx, Herd, Valet …).
            return response()->json($stress->run($validated));

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function stressTestPoll(Request $request, string $jobId): JsonResponse
    {
        if (! preg_match('/^[a-zA-Z0-9._]+$/', $jobId)) {
            return response()->json(['error' => 'Invalid job ID'], 400);
        }

        $payload = $this->readJobResult($jobId);

        if ($payload === null) {
            return response()->json(['status' => 'running']);
        }

        if (($payload['status'] ?? '') === 'done') {
            return response()->json(['status' => 'done', 'result' => $payload['result']]);
        }

        return response()->json(['status' => 'running']);
    }

    // ── SPA / static assets ───────────────────────────────────────────────────

    public function serve(Request $request, string $any = ''): Response|JsonResponse
    {
        $any = ltrim($any, '/');

        if (preg_match('/^\.graph-[a-z0-9_-]+\.json$/', $any)) {
            $path = storage_path('app/laravel-brain/'.$any);
            if (! file_exists($path)) {
                return response()->json(
                    ['error' => 'No scan data found — run php artisan brain:scan first'],
                    404
                );
            }

            return response(file_get_contents($path), 200, ['Content-Type' => 'application/json']);
        }

        if ($any !== '') {
            $filePath = $this->packageAssetPath($any);
            if ($filePath && file_exists($filePath) && is_file($filePath)) {
                return $this->serveFile($filePath);
            }
        }

        return response()->view('laravel-brain::index');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Restrict stress-test targets to development hosts only.
     *
     * In Docker the browser URL uses an external mapped port (e.g. localhost:8080)
     * but the subprocess runs inside the container where that port does not exist.
     * Users must point the Base URL at the internal service name (e.g. http://nginx).
     * We allow any host that looks like a private/internal network name:
     *   • classic localhost aliases
     *   • *.test / *.local (Herd, Valet, Herd Pro)
     *   • *.ddev.site (DDEV — DNS resolves inside the DDEV web container)
     *   • private IPv4 ranges (10.x, 172.16-31.x, 192.168.x)
     *   • single-label hostnames (Docker service names: nginx, app, web, …)
     *   • whatever host APP_URL is configured to
     */
    private function isAllowedHost(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        $allowedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        $allowedSuffixes = ['.test', '.local', '.ddev.site'];

        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Docker service names are single-label (no dots): nginx, app, web, php …
        if (! str_contains($host, '.')) {
            return true;
        }

        // Private IPv4 ranges used by Docker networks
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$a, $b] = array_map('intval', explode('.', $host));
            if ($a === 10
                || ($a === 172 && $b >= 16 && $b <= 31)
                || ($a === 192 && $b === 168)
            ) {
                return true;
            }
        }

        // Host configured in APP_URL (covers custom dev domains and Docker setups)
        $appHost = (string) parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return $appHost !== '' && $host === $appHost;
    }

    /**
     * Read the result file written by the laravel-stress subprocess.
     *
     * The file-naming convention (`lb_st_res_{jobId}.json`) is owned by
     * StressTestRunner::startBackground().  We mirror it here so the poll
     * endpoint can check progress without coupling to the runner's internals
     * beyond the agreed-upon file name prefix.
     *
     * Returns the decoded payload array, or null when the file is absent /
     * unreadable (meaning the subprocess hasn't written yet).
     *
     * @return array<string, mixed>|null
     */
    private function readJobResult(string $jobId): ?array
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lb_st_res_'.$jobId.'.json';

        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);

        if (! is_array($data)) {
            return null;
        }

        // Delete the result file once we've delivered the final result so temp
        // files don't accumulate indefinitely.
        if (($data['status'] ?? '') === 'done') {
            @unlink($path);
        }

        return $data;
    }

    private function serveFile(string $filePath): Response
    {
        $mimes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
            'json' => 'application/json',
            'html' => 'text/html',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
        ];

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $mimes[$ext] ?? 'application/octet-stream';

        return response(file_get_contents($filePath), 200, ['Content-Type' => $mime]);
    }

    private function packageAssetPath(string $file = ''): string
    {
        $base = realpath(__DIR__.'/../../../resources/assets');
        if (! $base) {
            return '';
        }

        $full = $base.($file !== '' ? '/'.ltrim($file, '/') : '');
        $realFull = realpath($full);

        if (! $realFull) {
            return '';
        }

        $baseWithSlash = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($realFull === $base || str_starts_with($realFull, $baseWithSlash)) {
            return $realFull;
        }

        return '';
    }
}
