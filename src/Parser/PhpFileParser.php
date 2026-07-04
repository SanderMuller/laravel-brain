<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Parser;

use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class PhpFileParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    public function parse(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            return ['ast' => null, 'useMap' => []];
        }

        return $this->parseCode($code);
    }

    /**
     * @return array{ast: Node\Stmt[]|null, useMap: array<string, string>}
     */
    public function parseCode(string $code): array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (Error) {
            return ['ast' => null, 'useMap' => []];
        }
        if ($ast === null) {
            return ['ast' => null, 'useMap' => []];
        }

        // One traversal: NameResolver annotates every Name with a resolvedName
        // attribute (additively — original nodes untouched), while the useMap
        // visitor collects the file's imports. Collect rather than throw, so a
        // semantically-invalid-but-parseable file (e.g. a duplicate use alias)
        // still scans instead of aborting.
        $useMapVisitor = new class extends NodeVisitorAbstract
        {
            /** @var array<string, string> */
            public array $useMap = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                        $this->useMap[$alias] = $use->name->toString();
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(new ErrorHandler\Collecting, [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ]));
        $traverser->addVisitor($useMapVisitor);
        $traverser->traverse($ast);

        return ['ast' => $ast, 'useMap' => $useMapVisitor->useMap];
    }

    /**
     * The fully-qualified name a `Name` node resolves to, following the file's
     * imports (including group-use), the current namespace, and leading-`\`
     * fully-qualified names.
     *
     * Returns null for a name the resolver leaves unresolved — the reserved
     * `self`/`static`/`parent` keywords (which the caller resolves against the
     * class context it already tracks), and any `Name` from an AST that was not
     * produced by {@see parse()} / {@see parseCode()} (so consumers can safely
     * fall back to their own resolution).
     */
    public static function resolvedName(?Node $name): ?string
    {
        if (! $name instanceof Node\Name) {
            return null;
        }
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            $fqcn = ltrim($resolved->toString(), '\\');

            // self/static/parent resolve to themselves — not a real FQCN, so
            // report them as unresolved and let the caller use its class context.
            return in_array(strtolower($fqcn), ['self', 'static', 'parent'], true) ? null : $fqcn;
        }

        return null;
    }
}
