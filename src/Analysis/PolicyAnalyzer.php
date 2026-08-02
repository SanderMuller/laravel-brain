<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Links Eloquent models to the authorization policies that guard them.
 *
 * Policies are resolved the same way Laravel's Gate resolves them, in
 * precedence order:
 *
 *  1. explicit registration — an `AuthServiceProvider::$policies` map
 *     (`Model::class => Policy::class`) or a `Gate::policy(Model::class,
 *     Policy::class)` call in a service provider;
 *  2. attribute — a model marked `#[UsePolicy(Policy::class)]`;
 *  3. convention — the guessed `App\Policies\FooPolicy` for model `App\Models\Foo`,
 *     used only when that policy class actually exists (mirroring Gate's
 *     `class_exists` guard, so no edge is invented for a missing policy).
 *
 * Each model resolves to at most one policy — the first form that matches —
 * and becomes one model → policy ("authorized by") edge, so a policy shows up
 * in the reach of the model it protects. The link is class-level by design:
 * it answers "what authorizes this model", not which ability maps to which
 * policy method.
 */
class PolicyAnalyzer
{
    private const POLICY_ATTRIBUTE = 'UsePolicy';

    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] provider directories (relative to project root) scanned for policy registrations */
    private array $providerPaths;

    /**
     * @param  string[]  $providerPaths
     */
    public function __construct(array $providerPaths = ['app/Providers'])
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->providerPaths = $providerPaths !== [] ? $providerPaths : ['app/Providers'];
    }

    /**
     * @param  string[]  $modelFqcns  the project's discovered model classes
     * @param  array<string, string[]>  $psr4Map  namespace prefix => base paths
     * @return array<string, string> model FQCN => policy FQCN
     */
    public function analyze(string $projectRoot, array $modelFqcns, array $psr4Map = []): array
    {
        $explicit = $this->explicitRegistrations($projectRoot);

        $resolved = [];
        foreach (array_unique($modelFqcns) as $model) {
            $model = ltrim($model, '\\');
            $policy = $explicit[$model]
                ?? $this->attributePolicy($model, $projectRoot, $psr4Map)
                ?? $this->conventionPolicy($model, $projectRoot, $psr4Map);

            if ($policy !== null && $policy !== $model) {
                $resolved[$model] = $policy;
            }
        }

        // Explicitly registered models that were not in the discovered set still
        // carry a real policy edge — keep them (the target may be any class).
        foreach ($explicit as $model => $policy) {
            if (! isset($resolved[$model]) && $policy !== $model) {
                $resolved[$model] = $policy;
            }
        }

        return $resolved;
    }

    /**
     * Model → policy pairs registered explicitly in a service provider, via
     * either the `$policies` property map or `Gate::policy()` calls.
     *
     * @return array<string, string>
     */
    private function explicitRegistrations(string $projectRoot): array
    {
        $map = [];
        foreach ($this->phpFiles($this->providerPaths, $projectRoot) as $file) {
            $context = $this->context($file);
            if ($context === null) {
                continue;
            }
            [$ast, $useMap, $namespace] = $context;

            foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Property::class) as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === 'policies' && $prop->default instanceof Node\Expr\Array_) {
                        foreach ($this->pairsFromMap($prop->default, $useMap, $namespace) as [$model, $policy]) {
                            $map[$model] = $policy;
                        }
                    }
                }
            }

            foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier
                    || $call->name->toString() !== 'policy'
                    || ! $call->class instanceof Node\Name
                    || ! $this->isGateFacade($call->class, $useMap, $namespace)) {
                    continue;
                }
                $args = $call->getArgs();
                $model = isset($args[0]) ? $this->classRef($args[0]->value, $useMap, $namespace) : null;
                $policy = isset($args[1]) ? $this->classRef($args[1]->value, $useMap, $namespace) : null;
                if ($model !== null && $policy !== null) {
                    $map[$model] = $policy;
                }
            }
        }

        return $map;
    }

    /**
     * Whether a `<Name>::policy()` call targets Laravel's Gate — resolved
     * through the use-map so an aliased import (`use ... Gate as Access`) is
     * recognised and an unrelated `App\Support\Gate` is not.
     */
    private function isGateFacade(Node\Name $class, array $useMap, string $namespace): bool
    {
        return in_array($this->qualify($class->toString(), $namespace, $useMap), [
            'Illuminate\\Support\\Facades\\Gate',
            'Illuminate\\Contracts\\Auth\\Access\\Gate',
        ], true);
    }

    /**
     * Parse a `$policies` map: Model::class => Policy::class.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairsFromMap(Node\Expr\Array_ $map, array $useMap, string $namespace): array
    {
        $pairs = [];
        foreach ($map->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem || $item->key === null) {
                continue;
            }
            $model = $this->classRef($item->key, $useMap, $namespace);
            $policy = $this->classRef($item->value, $useMap, $namespace);
            if ($model !== null && $policy !== null) {
                $pairs[] = [$model, $policy];
            }
        }

        return $pairs;
    }

    /**
     * The policy named by a `#[UsePolicy]` attribute on the model class.
     */
    private function attributePolicy(string $modelFqcn, string $projectRoot, array $psr4Map): ?string
    {
        $file = $this->resolveClassFile($modelFqcn, $projectRoot, $psr4Map);
        if ($file === null) {
            return null;
        }
        $context = $this->context($file);
        if ($context === null) {
            return null;
        }
        [$ast, $useMap, $namespace] = $context;

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class instanceof Node\Stmt\Class_) {
            return null;
        }

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() !== self::POLICY_ATTRIBUTE) {
                    continue;
                }
                $first = $attr->args[0]->value ?? null;
                if ($first !== null) {
                    return $this->classRef($first, $useMap, $namespace);
                }
            }
        }

        return null;
    }

    /**
     * The conventional policy for a model (`App\Models\Foo` → `App\Policies\FooPolicy`),
     * returned only when the policy class file actually exists.
     */
    private function conventionPolicy(string $modelFqcn, string $projectRoot, array $psr4Map): ?string
    {
        foreach ($this->guessPolicyNames($modelFqcn) as $candidate) {
            if ($this->resolveClassFile($candidate, $projectRoot, $psr4Map) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Candidate policy FQCNs for a model, in the same priority order as
     * Laravel's Gate::guessPolicyName: a `\Policies\` segment inserted at each
     * boundary of the model's namespace (deepest preferred), plus the
     * `\Models\` → `\Policies\` and `\Models\Policies\` rewrites. The first
     * candidate whose class file exists is used, mirroring Gate's `class_exists`
     * selection.
     *
     * @return list<string>
     */
    private function guessPolicyNames(string $modelFqcn): array
    {
        $base = ($pos = strrpos($modelFqcn, '\\')) !== false ? substr($modelFqcn, $pos + 1) : $modelFqcn;
        $classDirname = $pos !== false ? substr($modelFqcn, 0, $pos) : '';
        $segments = $classDirname === '' ? [] : explode('\\', $classDirname);

        $candidates = [];
        for ($index = 1; $index <= count($segments); $index++) {
            $prefix = implode('\\', array_slice($segments, 0, $index));
            $candidates[] = $prefix.'\\Policies\\'.$base.'Policy';
        }
        if (str_contains($classDirname, '\\Models\\')) {
            $candidates[] = str_replace('\\Models\\', '\\Policies\\', $classDirname).'\\'.$base.'Policy';
            $candidates[] = str_replace('\\Models\\', '\\Models\\Policies\\', $classDirname).'\\'.$base.'Policy';
        }

        $candidates = array_reverse($candidates);
        if ($candidates === []) {
            $candidates[] = $classDirname === '' ? $base.'Policy' : $classDirname.'\\Policies\\'.$base.'Policy';
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Resolve `Foo::class` or a string literal to an FQCN.
     */
    private function classRef(Node\Expr $expr, array $useMap, string $namespace): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class') {
            return $this->qualify($expr->class->toString(), $namespace, $useMap);
        }

        if ($expr instanceof Node\Scalar\String_) {
            return ltrim($expr->value, '\\');
        }

        return null;
    }

    /**
     * Resolve a (possibly short or aliased) class name to an FQCN.
     *
     * @param  array<string, string>  $useMap
     */
    private function qualify(string $name, string $namespace, array $useMap = []): string
    {
        $name = ltrim($name, '\\');
        if (isset($useMap[$name])) {
            return $useMap[$name];
        }
        $head = strtok($name, '\\');
        if ($head !== false && $head !== $name && isset($useMap[$head])) {
            return $useMap[$head].substr($name, strlen($head));
        }
        if (str_contains($name, '\\')) {
            return $name;
        }

        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    /**
     * @return array{0: Node\Stmt[], 1: array<string, string>, 2: string}|null [ast, useMap, namespace]
     */
    private function context(string $file): ?array
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }
        $namespaceNode = $this->finder->findFirstInstanceOf($parsed['ast'], Node\Stmt\Namespace_::class);
        $namespace = $namespaceNode instanceof Node\Stmt\Namespace_ && $namespaceNode->name !== null
            ? $namespaceNode->name->toString()
            : '';

        return [$parsed['ast'], $parsed['useMap'], $namespace];
    }

    /**
     * @param  string[]  $relativePaths
     * @return iterable<string>
     */
    private function phpFiles(array $relativePaths, string $projectRoot): iterable
    {
        foreach ($relativePaths as $relativePath) {
            $basePath = rtrim($projectRoot, '/').'/'.ltrim($relativePath, '/');
            if (! is_dir($basePath)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    yield $fileInfo->getPathname();
                }
            }
        }
    }

    /**
     * @param  array<string, string[]>  $psr4Map
     */
    private function resolveClassFile(string $fqcn, string $projectRoot, array $psr4Map): ?string
    {
        foreach ($psr4Map as $prefix => $basePaths) {
            if (str_starts_with($fqcn, rtrim($prefix, '\\').'\\')) {
                $relative = str_replace('\\', '/', substr($fqcn, strlen(rtrim($prefix, '\\')) + 1)).'.php';
                foreach ($basePaths as $basePath) {
                    $path = rtrim($basePath, '/').'/'.ltrim($relative, '/');
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }
        }

        $candidates = [];
        if (str_starts_with($fqcn, 'App\\')) {
            $candidates[] = 'app/'.str_replace('\\', '/', substr($fqcn, 4)).'.php';
        }
        $candidates[] = 'app/'.str_replace('\\', '/', $fqcn).'.php';
        $candidates[] = str_replace('\\', '/', $fqcn).'.php';

        foreach ($candidates as $candidate) {
            $path = rtrim($projectRoot, '/').'/'.$candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
