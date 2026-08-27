<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class MiddlewareAnalyzer
{
    private PhpFileParser $parser;

    public function __construct()
    {
        $this->parser = new PhpFileParser;
    }

    public function analyze(string $projectRoot): MiddlewareRegistry
    {
        $kernelPath = $projectRoot.'/app/Http/Kernel.php';
        $bootstrapPath = $projectRoot.'/bootstrap/app.php';

        if (file_exists($kernelPath)) {
            return $this->analyzeLaravel10($kernelPath);
        }

        if (file_exists($bootstrapPath)) {
            return $this->analyzeLaravel11($bootstrapPath);
        }

        return new MiddlewareRegistry([], [], []);
    }

    private function analyzeLaravel10(string $kernelPath): MiddlewareRegistry
    {
        $parsed = $this->parser->parse($kernelPath);
        if ($parsed['ast'] === null) {
            return new MiddlewareRegistry([], [], []);
        }

        $traverser = new NodeTraverser;
        $visitor = new class($parsed['useMap']) extends NodeVisitorAbstract
        {
            public array $global = [];

            public array $groups = [];

            public array $aliases = [];

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
                    $name = $prop->name->toString();
                    $default = $prop->default;

                    if ($name === 'middleware' && $default instanceof Node\Expr\Array_) {
                        $this->global = $this->extractStringArray($default);
                    } elseif ($name === 'middlewareGroups' && $default instanceof Node\Expr\Array_) {
                        foreach ($default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                            if ($key && $item->value instanceof Node\Expr\Array_) {
                                $this->groups[$key] = $this->extractStringArray($item->value);
                            }
                        }
                    } elseif (in_array($name, ['middlewareAliases', 'routeMiddleware'], true) && $default instanceof Node\Expr\Array_) {
                        foreach ($default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                            $value = $this->extractClassString($item->value);
                            if ($key && $value) {
                                $this->aliases[$key] = $value;
                            }
                        }
                    }
                }

                return null;
            }

            private function extractStringArray(Node\Expr\Array_ $array): array
            {
                $result = [];
                foreach ($array->items as $item) {
                    if (! $item) {
                        continue;
                    }
                    $value = $this->extractClassString($item->value);
                    if ($value) {
                        $result[] = $value;
                    }
                }

                return $result;
            }

            private function extractClassString(Node $node): ?string
            {
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return new MiddlewareRegistry($visitor->global, $visitor->groups, $visitor->aliases);
    }

    private function analyzeLaravel11(string $bootstrapPath): MiddlewareRegistry
    {
        $parsed = $this->parser->parse($bootstrapPath);
        if ($parsed['ast'] === null) {
            return new MiddlewareRegistry([], [], []);
        }

        $traverser = new NodeTraverser;
        $visitor = new class($parsed['useMap']) extends NodeVisitorAbstract
        {
            public array $groups = [];

            public array $aliases = [];

            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Expr\MethodCall) {
                    return null;
                }
                $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                if (in_array($methodName, ['api', 'web'], true)) {
                    $this->groups[$methodName] = $this->extractAppendList($node);
                } elseif ($methodName === 'group') {
                    // `$middleware->group('admin', [...])` — how an application declares a group
                    // of its own. The framework's own `web` and `api` are modified through the
                    // methods above; every other group is born here.
                    $this->declareGroup($node);
                } elseif (in_array($methodName, ['appendToGroup', 'prependToGroup'], true)) {
                    $this->addToGroup($node, $methodName === 'prependToGroup');
                } elseif ($methodName === 'alias') {
                    $this->extractAliases($node);
                }

                return null;
            }

            /** `group(string $group, array $middleware)` — the whole membership, replacing any of it read so far. */
            private function declareGroup(Node\Expr\MethodCall $node): void
            {
                $name = $this->groupNameArg($node);
                if ($name === null || ! ($node->args[1] ?? null) instanceof Node\Arg) {
                    return;
                }

                $members = $node->args[1]->value;
                if ($members instanceof Node\Expr\Array_) {
                    $this->groups[$name] = $this->extractClassArray($members);
                }
            }

            /**
             * `appendToGroup(string $group, array|string $middleware)` and its prepend twin. Laravel
             * wraps a bare string, so `appendToGroup('web', Guard::class)` is one member, not none.
             */
            private function addToGroup(Node\Expr\MethodCall $node, bool $prepend): void
            {
                $name = $this->groupNameArg($node);
                if ($name === null || ! ($node->args[1] ?? null) instanceof Node\Arg) {
                    return;
                }

                $value = $node->args[1]->value;
                $members = $value instanceof Node\Expr\Array_
                    ? $this->extractClassArray($value)
                    : array_filter([$this->extractClassString($value)]);

                if ($members === []) {
                    return;
                }

                $existing = $this->groups[$name] ?? [];
                $this->groups[$name] = $prepend
                    ? array_merge($members, $existing)
                    : array_merge($existing, $members);
            }

            /**
             * The group name a `group`-family call names, or null when it is not a literal — a
             * variable or a constant is a name this cannot read, and guessing one would attribute
             * middleware to a group that does not exist.
             */
            private function groupNameArg(Node\Expr\MethodCall $node): ?string
            {
                // First-class callable syntax puts a VariadicPlaceholder here, which has no `value`.
                if (! ($node->args[0] ?? null) instanceof Node\Arg) {
                    return null;
                }

                $name = $node->args[0]->value;

                return $name instanceof Node\Scalar\String_ && $name->value !== '' ? $name->value : null;
            }

            private function extractAppendList(Node\Expr\MethodCall $node): array
            {
                foreach ($node->args as $arg) {
                    if ($arg->name instanceof Node\Identifier && $arg->name->toString() === 'append') {
                        if ($arg->value instanceof Node\Expr\Array_) {
                            return $this->extractClassArray($arg->value);
                        }
                    }
                }

                return [];
            }

            private function extractAliases(Node\Expr\MethodCall $node): void
            {
                // First-class callable syntax `$middleware->alias(...)` puts a
                // VariadicPlaceholder (no `->value`) in args[0]. Reading it would
                // raise a warning that Laravel's HandleExceptions turns into an
                // ErrorException, killing the scan — so bail unless args[0] is a
                // real Node\Arg. Matches the guard used across MethodTracer /
                // FlowExtractor.
                if (count($node->args) === 0 || ! $node->args[0] instanceof Node\Arg) {
                    return;
                }

                // Form A: `$middleware->alias('key', Class::class)`
                if (count($node->args) >= 2 && $node->args[0]->value instanceof Node\Scalar\String_) {
                    $alias = $node->args[0]->value->value;
                    $class = $this->extractClassString($node->args[1]->value);
                    if ($alias !== '' && $class !== null) {
                        $this->aliases[$alias] = $class;
                    }

                    return;
                }

                // Form B: `$middleware->alias(['key' => Class::class, ...])`
                // This is the form Laravel's docs and the bootstrap/app.php
                // skeleton use, so most real apps register custom aliases this way.
                if ($node->args[0]->value instanceof Node\Expr\Array_) {
                    foreach ($node->args[0]->value->items as $item) {
                        if (! $item || ! ($item->key instanceof Node\Scalar\String_)) {
                            continue;
                        }
                        $class = $this->extractClassString($item->value);
                        if ($class !== null) {
                            $this->aliases[$item->key->value] = $class;
                        }
                    }
                }
            }

            private function extractClassArray(Node\Expr\Array_ $array): array
            {
                $result = [];
                foreach ($array->items as $item) {
                    if (! $item) {
                        continue;
                    }
                    $value = $this->extractClassString($item->value);
                    if ($value) {
                        $result[] = $value;
                    }
                }

                return $result;
            }

            private function extractClassString(Node $node): ?string
            {
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return new MiddlewareRegistry([], $visitor->groups, $visitor->aliases);
    }
}
