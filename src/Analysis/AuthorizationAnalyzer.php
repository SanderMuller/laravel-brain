<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Builds a per-route authorization summary: middleware-derived auth, in-method
 * $this->authorize() calls, and a project-wide model→policy map.
 *
 * Output shape per route ID (matches GraphBuilder's "route::{METHOD}::{URI}" key):
 *
 *   [
 *     'kind'         => 'public' | 'authenticated' | 'policy-gated',
 *     'guards'       => ['auth', 'auth:sanctum', ...],
 *     'abilities'    => ['can:edit-post', 'role:admin', ...],
 *     'authorizes'   => [ ['ability' => 'update', 'model' => 'App\\Models\\Post'], ... ],
 *   ]
 */
final class AuthorizationAnalyzer
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
     *     routes: array<string, array<string, mixed>>,
     *     policies: array<string, string>,
     * }
     */
    public function analyze(array $routes, array $controllers, string $projectRoot): array
    {
        $policies = $this->discoverPolicies($projectRoot);

        $routeMap = [];
        foreach ($routes as $route) {
            $routeId = "route::{$route->method}::{$route->uri}";

            [$guards, $abilities] = $this->splitMiddleware($route->middlewares);
            $authorizes = $this->extractAuthorizeCalls($route, $controllers);

            $kind = 'public';
            if ($guards !== []) {
                $kind = 'authenticated';
            }
            if ($abilities !== [] || $authorizes !== []) {
                $kind = 'policy-gated';
            }

            $routeMap[$routeId] = [
                'kind' => $kind,
                'guards' => $guards,
                'abilities' => $abilities,
                'authorizes' => $authorizes,
            ];
        }

        return [
            'routes' => $routeMap,
            'policies' => $policies,
        ];
    }

    /**
     * @param  string[]  $middlewares
     * @return array{0: list<string>, 1: list<string>} [guards, abilities]
     */
    private function splitMiddleware(array $middlewares): array
    {
        $guards = [];
        $abilities = [];

        foreach ($middlewares as $mw) {
            if ($mw === 'auth' || str_starts_with($mw, 'auth:') || $mw === 'auth.basic') {
                $guards[] = $mw;
            } elseif (str_starts_with($mw, 'can:')
                || str_starts_with($mw, 'role:')
                || str_starts_with($mw, 'permission:')
                || str_starts_with($mw, 'ability:')
            ) {
                $abilities[] = $mw;
            }
        }

        return [array_values(array_unique($guards)), array_values(array_unique($abilities))];
    }

    /**
     * @param  array<string, ControllerDefinition>  $controllers
     * @return list<array{ability: string, model: ?string}>
     */
    private function extractAuthorizeCalls(RouteDefinition $route, array $controllers): array
    {
        $controller = $controllers[$route->controller] ?? null;
        if ($controller === null) {
            return [];
        }
        $method = null;
        foreach ($controller->methods as $m) {
            if ($m->name === $route->action) {
                $method = $m;
                break;
            }
        }
        if ($method === null || $method->ast === null) {
            return [];
        }

        $useMap = $method->methodUseMap ?? $controller->useMap;

        $traverser = new NodeTraverser;
        $visitor = new class($useMap) extends NodeVisitorAbstract
        {
            /** @var list<array{ability: string, model: ?string}> */
            public array $found = [];

            /** @var array<string, string> */
            private array $useMap;

            /**
             * @param  array<string, string>  $useMap
             */
            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Expr\MethodCall) {
                    return null;
                }
                if (! $node->var instanceof Node\Expr\Variable || $node->var->name !== 'this') {
                    return null;
                }
                if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'authorize') {
                    return null;
                }

                $ability = null;
                $model = null;

                $args = $node->args;
                if (isset($args[0]) && $args[0] instanceof Node\Arg && $args[0]->value instanceof Node\Scalar\String_) {
                    $ability = $args[0]->value->value;
                }
                if (isset($args[1]) && $args[1] instanceof Node\Arg) {
                    $val = $args[1]->value;
                    if ($val instanceof Node\Expr\ClassConstFetch && $val->class instanceof Node\Name) {
                        $short = $val->class->toString();
                        $model = $this->useMap[$short] ?? $short;
                    }
                }

                if ($ability !== null) {
                    $this->found[] = ['ability' => $ability, 'model' => $model];
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse([$method->ast]);

        return $visitor->found;
    }

    /**
     * @return array<string, string> FQCN(model) => FQCN(policy)
     */
    private function discoverPolicies(string $projectRoot): array
    {
        $policies = [];

        // Convention scan: app/Policies/*Policy.php
        $base = rtrim($projectRoot, '/').'/app/Policies';
        if (is_dir($base)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $parsed = $this->parser->parse($file->getPathname());
                if ($parsed['ast'] === null) {
                    continue;
                }
                $fqcn = $this->extractClassFqcn($parsed['ast']);
                if ($fqcn === null || ! str_ends_with($fqcn, 'Policy')) {
                    continue;
                }
                $modelShort = substr(ltrim(strrchr($fqcn, '\\') ?: $fqcn, '\\'), 0, -strlen('Policy'));
                if ($modelShort === '') {
                    continue;
                }
                $policies['App\\Models\\'.$modelShort] = $fqcn;
            }
        }

        // Explicit map: AuthServiceProvider::$policies
        $authProvider = rtrim($projectRoot, '/').'/app/Providers/AuthServiceProvider.php';
        if (is_file($authProvider)) {
            $explicit = $this->extractPoliciesProperty($authProvider);
            foreach ($explicit as $model => $policy) {
                $policies[$model] = $policy;
            }
        }

        return $policies;
    }

    /**
     * @param  Node[]  $ast
     */
    private function extractClassFqcn(array $ast): ?string
    {
        $namespace = '';
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $namespace = $node->name !== null ? $node->name->toString() : '';
                foreach ($node->stmts as $inner) {
                    if ($inner instanceof Node\Stmt\Class_ && $inner->name !== null) {
                        return ($namespace !== '' ? $namespace.'\\' : '').$inner->name->toString();
                    }
                }
            }
            if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                return $node->name->toString();
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractPoliciesProperty(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }
        $useMap = $parsed['useMap'] ?? [];
        $out = [];

        $traverser = new NodeTraverser;
        $visitor = new class($useMap) extends NodeVisitorAbstract
        {
            public array $map = [];

            /** @var array<string, string> */
            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Stmt\Property) {
                    return null;
                }
                foreach ($node->props as $prop) {
                    if ($prop->name->toString() !== 'policies' || ! $prop->default instanceof Node\Expr\Array_) {
                        continue;
                    }
                    foreach ($prop->default->items as $item) {
                        if (! $item instanceof Node\Expr\ArrayItem) {
                            continue;
                        }
                        $modelFqcn = $this->resolveClassRef($item->key);
                        $policyFqcn = $this->resolveClassRef($item->value);
                        if ($modelFqcn !== null && $policyFqcn !== null) {
                            $this->map[$modelFqcn] = $policyFqcn;
                        }
                    }
                }

                return null;
            }

            private function resolveClassRef(?Node $node): ?string
            {
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $short = $node->class->toString();

                    return $this->useMap[$short] ?? $short;
                }
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return $visitor->map;
    }
}
