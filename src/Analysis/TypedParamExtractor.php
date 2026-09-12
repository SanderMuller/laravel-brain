<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;

/**
 * The param-walking loop shared by every place that reads a method's signature to learn what it
 * needs injected — a constructor, a controller action, or (once merged in by the caller) any
 * other entry point. Type resolution itself stays with the caller: a controller resolves a type
 * name through its own useMap in one pass, while MethodTracer resolves the AST's own attached
 * name first and substitutes through useMap in a later pass — the two are not interchangeable.
 */
class TypedParamExtractor
{
    /**
     * @param  Node\Param[]  $params
     * @param  \Closure(Node): (string|null)  $resolveType
     * @return array<string, string> varName => resolved type name
     */
    public static function extract(array $params, \Closure $resolveType): array
    {
        $deps = [];

        foreach ($params as $param) {
            if (! $param instanceof Node\Param || $param->type === null) {
                continue;
            }

            $varName = $param->var instanceof Node\Expr\Variable ? $param->var->name : null;
            if (! is_string($varName)) {
                continue;
            }

            $typeName = $resolveType($param->type);
            if ($typeName) {
                $deps[$varName] = $typeName;
            }
        }

        return $deps;
    }
}
