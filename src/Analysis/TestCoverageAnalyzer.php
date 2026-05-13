<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Scans tests/ to associate routes and controllers with the tests that exercise them.
 *
 * Two signals are detected:
 *
 *  - HTTP feature tests: calls like $this->get('/path'), $this->postJson('/path', ...) mapped to the route URI/method.
 *  - Class-references: direct mentions of a controller/service FQCN in any test file (unit coverage).
 *
 * Optionally consumes a PHPUnit Clover XML file when present at the project root,
 * which adds line-level coverage data per file.
 */
final class TestCoverageAnalyzer
{
    private PhpFileParser $parser;

    public function __construct(?PhpFileParser $parser = null)
    {
        $this->parser = $parser ?? new PhpFileParser;
    }

    /**
     * @param  RouteDefinition[]  $routes
     * @param  array<string, ControllerDefinition>  $controllers
     * @return array{
     *     routes: array<string, array{strength: string, covered_by: list<string>}>,
     *     classes: array<string, list<string>>,
     *     cloverFile: ?string,
     * }
     */
    public function analyze(array $routes, array $controllers, string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $testsDir = $projectRoot.'/tests';

        $httpCalls = [];
        $classRefs = [];

        if (is_dir($testsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = ltrim(substr($file->getPathname(), strlen($projectRoot)), '/');
                $this->scanFile($file->getPathname(), $relative, $httpCalls, $classRefs);
            }
        }

        $routeCoverage = [];
        foreach ($routes as $route) {
            $routeId = "route::{$route->method}::{$route->uri}";
            $coveredBy = [];

            $normalisedUri = ltrim($route->uri, '/');

            foreach ($httpCalls as $call) {
                if (strtoupper($call['method']) !== strtoupper($route->method)) {
                    continue;
                }
                if ($this->uriMatches($normalisedUri, ltrim($call['path'], '/'))) {
                    $coveredBy[] = $call['file'];
                }
            }

            $coveredBy = array_values(array_unique($coveredBy));
            $strength = $coveredBy !== [] ? 'http' : 'none';

            // Fallback to class-level coverage (unit test referencing the controller)
            if ($strength === 'none' && isset($classRefs[$route->controller])) {
                $coveredBy = $classRefs[$route->controller];
                $strength = 'unit';
            }

            $routeCoverage[$routeId] = [
                'strength' => $strength,
                'covered_by' => $coveredBy,
            ];
        }

        $cloverFile = null;
        foreach (['coverage.xml', 'build/logs/clover.xml', 'tests/coverage.xml'] as $candidate) {
            $abs = $projectRoot.'/'.$candidate;
            if (is_file($abs)) {
                $cloverFile = $candidate;
                break;
            }
        }

        return [
            'routes' => $routeCoverage,
            'classes' => $classRefs,
            'cloverFile' => $cloverFile,
        ];
    }

    /**
     * @param  array<int, array{method: string, path: string, file: string}>  $httpCalls
     * @param  array<string, array<int, string>>  $classRefs
     */
    private function scanFile(string $path, string $relativePath, array &$httpCalls, array &$classRefs): void
    {
        $parsed = $this->parser->parse($path);
        if ($parsed['ast'] === null) {
            return;
        }
        $useMap = $parsed['useMap'] ?? [];

        // Cheap textual scan for FQCNs/short names mentioned anywhere
        $contents = (string) file_get_contents($path);
        foreach ($useMap as $short => $full) {
            if ($full === '' || strpos($contents, $short) === false) {
                continue;
            }
            $classRefs[$full] ??= [];
            if (! in_array($relativePath, $classRefs[$full], true)) {
                $classRefs[$full][] = $relativePath;
            }
        }

        $methods = ['get', 'getJson', 'post', 'postJson', 'put', 'putJson', 'patch', 'patchJson', 'delete', 'deleteJson', 'options', 'head', 'call', 'json'];

        $traverser = new NodeTraverser;
        $visitor = new class($methods, $relativePath) extends NodeVisitorAbstract
        {
            /** @var list<string> */
            private array $methods;

            private string $file;

            /** @var list<array{method: string, path: string, file: string}> */
            public array $calls = [];

            /**
             * @param  list<string>  $methods
             */
            public function __construct(array $methods, string $file)
            {
                $this->methods = $methods;
                $this->file = $file;
            }

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Expr\MethodCall) {
                    return null;
                }
                if (! $node->name instanceof Node\Identifier) {
                    return null;
                }
                $method = $node->name->toString();
                if (! in_array($method, $this->methods, true)) {
                    return null;
                }

                $argOffset = 0;
                if ($method === 'json' || $method === 'call') {
                    // $this->json('POST', '/path', ...) or $this->call('POST', '/path')
                    if (! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg
                        || ! $node->args[0]->value instanceof Node\Scalar\String_) {
                        return null;
                    }
                    $httpMethod = strtoupper($node->args[0]->value->value);
                    $argOffset = 1;
                } else {
                    $httpMethod = strtoupper(preg_replace('/Json$/', '', $method));
                }

                if (! isset($node->args[$argOffset]) || ! $node->args[$argOffset] instanceof Node\Arg) {
                    return null;
                }
                $pathArg = $node->args[$argOffset]->value;
                if (! $pathArg instanceof Node\Scalar\String_) {
                    return null;
                }

                $this->calls[] = [
                    'method' => $httpMethod,
                    'path' => $pathArg->value,
                    'file' => $this->file,
                ];

                return null;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        foreach ($visitor->calls as $c) {
            $httpCalls[] = $c;
        }
    }

    private function uriMatches(string $routeUri, string $callPath): bool
    {
        if ($routeUri === $callPath) {
            return true;
        }
        // Match by replacing {param} with a wildcard regex.
        $pattern = '#^'.preg_replace('/\\\\{[^}]+\\\\}/', '[^/]+', preg_quote($routeUri, '#')).'(?:\\?.*)?$#';

        return preg_match($pattern, $callPath) === 1;
    }
}
