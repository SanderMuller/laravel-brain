<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Links Eloquent models to the observers that watch their lifecycle events.
 *
 * Observers are discovered from every way Laravel registers one:
 *
 *  - by attribute — a model class marked `#[ObservedBy(Observer::class)]`
 *                   (or `#[ObservedBy([A::class, B::class])]`);
 *  - by `observe()` — a `Model::observe(Observer::class)` call, whether made
 *                     from a service provider's `boot()` (the common form) or
 *                     from the model's own `booted()` via `self`/`static`.
 *
 * Each pairing becomes one model → observer link, so the graph can answer
 * "what runs when this model is created/updated/deleted", regardless of how
 * the observer was registered. Tracing the observer's own method bodies
 * (created/updated/…) is a separate concern handled by the call-chain tracer.
 *
 * The `observe()` scan is a static heuristic: any `<Name>::observe(Class::class)`
 * call is recorded, without confirming the left side is an Eloquent model — the
 * same pragmatic approach ListenerAnalyzer takes with `$listen`/`$subscribe`.
 * Only the statically resolvable forms are followed: a `::class` or string
 * argument; an instance argument (`observe(new Foo)`) or a variable target is
 * not resolved (and is not statically resolvable in the general case).
 */
class ObserverAnalyzer
{
    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] model directories (relative to project root) scanned for #[ObservedBy] + self::observe() */
    private array $modelPaths;

    /** @var string[] provider directories (relative to project root) scanned for Model::observe() */
    private array $providerPaths;

    /**
     * @param  string[]  $modelPaths
     * @param  string[]  $providerPaths
     */
    public function __construct(array $modelPaths = ['app/Models'], array $providerPaths = ['app/Providers'])
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->modelPaths = $modelPaths !== [] ? $modelPaths : ['app/Models'];
        $this->providerPaths = $providerPaths !== [] ? $providerPaths : ['app/Providers'];
    }

    /**
     * @return array<string, list<string>> model FQCN => observer FQCNs
     */
    public function analyze(string $projectRoot): array
    {
        $pairs = [];
        foreach ($this->phpFiles($this->modelPaths, $projectRoot) as $file) {
            array_push($pairs, ...$this->pairsFromModelFile($file));
        }
        foreach ($this->phpFiles($this->providerPaths, $projectRoot) as $file) {
            array_push($pairs, ...$this->pairsFromProviderFile($file));
        }

        return $this->group($pairs);
    }

    /**
     * `#[ObservedBy]` on the model class, plus any `self`/`static::observe()`
     * registered inside the model itself (e.g. from `booted()`).
     *
     * @return list<array{0: string, 1: string}> [modelFqcn, observerFqcn]
     */
    private function pairsFromModelFile(string $file): array
    {
        $context = $this->context($file);
        if ($context === null) {
            return [];
        }
        [$ast, $useMap, $namespace] = $context;

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return [];
        }
        $modelFqcn = $this->qualify($class->name->toString(), $namespace);

        $pairs = [];
        foreach ($this->observedByObservers($class->attrGroups, $useMap, $namespace) as $observer) {
            $pairs[] = [$modelFqcn, $observer];
        }
        array_push($pairs, ...$this->observeCallPairs($ast, $useMap, $namespace, $modelFqcn));

        return $pairs;
    }

    /**
     * `Model::observe(Observer::class)` calls in a service provider.
     *
     * @return list<array{0: string, 1: string}> [modelFqcn, observerFqcn]
     */
    private function pairsFromProviderFile(string $file): array
    {
        $context = $this->context($file);
        if ($context === null) {
            return [];
        }
        [$ast, $useMap, $namespace] = $context;

        return $this->observeCallPairs($ast, $useMap, $namespace, null);
    }

    /**
     * Find every `<Model>::observe(...)` static call in an AST and resolve it to
     * [modelFqcn, observerFqcn] pairs. When the call target is `self`/`static`
     * the model is $selfFqcn (set when scanning a model file, null otherwise).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function observeCallPairs(array $ast, array $useMap, string $namespace, ?string $selfFqcn): array
    {
        $pairs = [];
        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'observe') {
                continue;
            }
            if (! $call->class instanceof Node\Name) {
                continue;
            }
            $model = $this->resolveModelTarget($call->class, $useMap, $namespace, $selfFqcn);
            if ($model === null) {
                continue;
            }
            $args = $call->getArgs();
            if (! isset($args[0])) {
                continue;
            }
            foreach ($this->observerRefs($args[0]->value, $useMap, $namespace) as $observer) {
                $pairs[] = [$model, $observer];
            }
        }

        return $pairs;
    }

    /**
     * Resolve the class side of a `<Model>::observe()` call to a model FQCN.
     */
    private function resolveModelTarget(Node\Name $class, array $useMap, string $namespace, ?string $selfFqcn): ?string
    {
        $name = $class->toString();
        if ($name === 'self' || $name === 'static') {
            return $selfFqcn;
        }

        return $this->qualify($name, $namespace, $useMap);
    }

    /**
     * Read the observer FQCNs named by `#[ObservedBy]` attributes on a class.
     *
     * @param  Node\AttributeGroup[]  $groups
     * @return list<string>
     */
    private function observedByObservers(array $groups, array $useMap, string $namespace): array
    {
        $observers = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() !== 'ObservedBy') {
                    continue;
                }
                $first = $attr->args[0]->value ?? null;
                if ($first !== null) {
                    array_push($observers, ...$this->observerRefs($first, $useMap, $namespace));
                }
            }
        }

        return $observers;
    }

    /**
     * Resolve an observer argument — a single `Observer::class` or an array of
     * them — to a list of FQCNs.
     *
     * @return list<string>
     */
    private function observerRefs(Node\Expr $value, array $useMap, string $namespace): array
    {
        if ($value instanceof Node\Expr\Array_) {
            $refs = [];
            foreach ($value->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem) {
                    $ref = $this->classRef($item->value, $useMap, $namespace);
                    if ($ref !== null) {
                        $refs[] = $ref;
                    }
                }
            }

            return $refs;
        }

        $ref = $this->classRef($value, $useMap, $namespace);

        return $ref === null ? [] : [$ref];
    }

    /**
     * Resolve `Observer::class` or a string literal to an FQCN.
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
     * Group [model, observer] pairs into model => observers, de-duplicating an
     * observer wired by more than one mechanism.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<string, list<string>>
     */
    private function group(array $pairs): array
    {
        $map = [];
        foreach ($pairs as [$model, $observer]) {
            $map[$model] ??= [];
            if (! in_array($observer, $map[$model], true)) {
                $map[$model][] = $observer;
            }
        }

        return $map;
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
}
