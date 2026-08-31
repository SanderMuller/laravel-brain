<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Extracts enum cases, interface methods, and other structural members from PHP files
 * for graph inspection (sidebar / AI context).
 */
class PhpStructureInspector
{
    private PhpFileParser $parser;

    /**
     * Per-file memo: GraphBuilder inspects the same file once per node it builds from it.
     *
     * @var array<string, array{kind: string, members: list<array<string, mixed>>}|null>
     */
    private array $inspectCache = [];

    /**
     * The same memo for {@see payloadKeys()}: GraphBuilder asks once per resource node, and a
     * resource composing a sibling makes it ask about the same file again.
     *
     * @var array<string, list<array{key: string, value: string}>>
     */
    private array $payloadKeysCache = [];

    private ?PrettyPrinter $printer = null;

    public function __construct(?PhpFileParser $parser = null)
    {
        $this->parser = $parser ?? new PhpFileParser;
    }

    /**
     * @return array{kind: string, members: list<array<string, mixed>>}|null
     */
    public function inspectFile(string $file): ?array
    {
        if (array_key_exists($file, $this->inspectCache)) {
            return $this->inspectCache[$file];
        }

        return $this->inspectCache[$file] = $this->inspectFileUncached($file);
    }

    /**
     * @return array{kind: string, members: list<array<string, mixed>>}|null
     */
    private function inspectFileUncached(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }

        $traverser = new NodeTraverser;
        $visitor = new class extends NodeVisitorAbstract
        {
            public ?array $result = null;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Enum_) {
                    $this->result = self::extractEnum($node);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Stmt\Interface_) {
                    $this->result = self::extractInterface($node);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Stmt\Trait_) {
                    $this->result = self::extractTrait($node);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Stmt\Class_
                    && $node->isAbstract()
                    && $node->name instanceof Node\Identifier) {
                    $this->result = self::extractAbstractClass($node);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }

            /**
             * @return array{kind: 'enum', members: list<array<string, mixed>>}
             */
            private static function extractEnum(Node\Stmt\Enum_ $node): array
            {
                $members = [];
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\EnumCase) {
                        $row = [
                            'kind' => 'case',
                            'name' => $stmt->name->toString(),
                        ];
                        if ($stmt->expr instanceof Node\Scalar\String_) {
                            $row['value'] = $stmt->expr->value;
                        } elseif ($stmt->expr instanceof Node\Scalar\Int_) {
                            $row['value'] = $stmt->expr->value;
                        }
                        $members[] = $row;
                    } elseif ($stmt instanceof Node\Stmt\ClassMethod) {
                        $members[] = [
                            'kind' => 'method',
                            'name' => $stmt->name->toString(),
                            'static' => $stmt->isStatic(),
                        ];
                    }
                }

                return ['kind' => 'enum', 'members' => $members];
            }

            /**
             * @return array{kind: 'interface', members: list<array<string, mixed>>}
             */
            private static function extractInterface(Node\Stmt\Interface_ $node): array
            {
                $members = [];
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        $members[] = [
                            'kind' => 'method',
                            'name' => $stmt->name->toString(),
                        ];
                    }
                }

                return ['kind' => 'interface', 'members' => $members];
            }

            /**
             * @return array{kind: 'trait', members: list<array<string, mixed>>}
             */
            private static function extractTrait(Node\Stmt\Trait_ $node): array
            {
                $members = [];
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod) {
                        $name = $stmt->name->toString();
                        if (str_starts_with($name, '__')) {
                            continue;
                        }
                        $members[] = [
                            'kind' => 'method',
                            'name' => $name,
                            'static' => $stmt->isStatic(),
                        ];
                    }
                }

                return ['kind' => 'trait', 'members' => $members];
            }

            /**
             * @return array{kind: 'abstract_class', members: list<array<string, mixed>>}
             */
            private static function extractAbstractClass(Node\Stmt\Class_ $node): array
            {
                $members = [];
                foreach ($node->stmts as $stmt) {
                    if (! $stmt instanceof Node\Stmt\ClassMethod) {
                        continue;
                    }
                    $name = $stmt->name->toString();
                    if (str_starts_with($name, '__')) {
                        continue;
                    }
                    $vis = $stmt->isPrivate() ? 'private' : ($stmt->isProtected() ? 'protected' : 'public');
                    if ($vis === 'private') {
                        continue;
                    }
                    $members[] = [
                        'kind' => 'method',
                        'name' => $name,
                        'static' => $stmt->isStatic(),
                        'abstract' => $stmt->isAbstract(),
                        'visibility' => $vis,
                    ];
                }

                return ['kind' => 'abstract_class', 'members' => $members];
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return $visitor->result;
    }

    /**
     * Public + non-magic methods on a class (for mailables, notifications, etc.).
     *
     * @return list<array{name: string, static: bool, visibility: string}>
     */
    /**
     * The keys a resource's `toArray()` writes into the payload, in the order it writes them, each
     * with the expression that fills it.
     *
     * This is the last hop of a request and the only one whose shape a consumer of the API sees.
     * The graph already knows a route reaches a resource; what the response carries was left inside
     * the file, so a reader asking what an endpoint returns had to open it.
     *
     * A literal key is given as it is written; anything else is printed as the expression it is, so
     * a key built from a constant reads as `Article::FIELD_SLUG` rather than being dropped. A spread
     * (`...$this->meta()`) is one row keyed `...`, because the payload has more in it than the rows
     * above and saying so is the honest answer. A list item with no key is `*`, the same stand-in
     * {@see ValidationRulesExtractor} uses for one.
     *
     * Empty for a `toArray()` that returns anything but an array literal — `parent::toArray()`, an
     * array built up over several statements, a merge. Those are payloads this cannot enumerate, and
     * an empty list says nothing about them rather than claiming a payload with no keys.
     *
     * @return list<array{key: string, value: string}>
     */
    public function payloadKeys(string $file): array
    {
        if (array_key_exists($file, $this->payloadKeysCache)) {
            return $this->payloadKeysCache[$file];
        }

        return $this->payloadKeysCache[$file] = $this->payloadKeysUncached($file);
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function payloadKeysUncached(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $method = $this->findMethod($parsed['ast'], 'toArray');
        if ($method === null || $method->isAbstract()) {
            return [];
        }

        $rows = [];
        foreach ($this->returnedArrays($method->stmts ?? []) as $array) {
            foreach ($array->items as $item) {
                if (! $item instanceof Node\Expr\ArrayItem) {
                    continue;
                }

                $rows[] = [
                    'key' => $this->payloadKeyName($item),
                    'value' => $this->oneLine($this->printer()->prettyPrintExpr($item->value)),
                ];
            }
        }

        return $rows;
    }

    private function payloadKeyName(Node\Expr\ArrayItem $item): string
    {
        if ($item->unpack) {
            return '...';
        }

        if ($item->key === null) {
            return '*';
        }

        return $item->key instanceof Node\Scalar\String_
            ? $item->key->value
            : $this->oneLine($this->printer()->prettyPrintExpr($item->key));
    }

    /**
     * Every array literal the statements return, including from inside a conditional — a resource
     * that returns one shape to an editor and another to everyone else has two payloads, and both
     * are worth reading.
     *
     * @param  Node\Stmt[]  $stmts
     * @return list<Node\Expr\Array_>
     */
    private function returnedArrays(array $stmts): array
    {
        $arrays = [];

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                $arrays[] = $stmt->expr;

                continue;
            }

            foreach ($this->nestedStatementLists($stmt) as $inner) {
                foreach ($this->returnedArrays($inner) as $array) {
                    $arrays[] = $array;
                }
            }
        }

        return $arrays;
    }

    /**
     * @return list<Node\Stmt[]>
     */
    private function nestedStatementLists(Node\Stmt $stmt): array
    {
        if ($stmt instanceof Node\Stmt\If_) {
            $lists = [$stmt->stmts];
            foreach ($stmt->elseifs as $elseif) {
                $lists[] = $elseif->stmts;
            }
            if ($stmt->else !== null) {
                $lists[] = $stmt->else->stmts;
            }

            return $lists;
        }

        if ($stmt instanceof Node\Stmt\TryCatch) {
            $lists = [$stmt->stmts];
            foreach ($stmt->catches as $catch) {
                $lists[] = $catch->stmts;
            }
            if ($stmt->finally !== null) {
                $lists[] = $stmt->finally->stmts;
            }

            return $lists;
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            $lists = [];
            foreach ($stmt->cases as $case) {
                $lists[] = $case->stmts;
            }

            return $lists;
        }

        return [];
    }

    /**
     * A holder rather than a by-reference property, the same way
     * {@see ValidationRulesExtractor::findRulesMethod()} does it: a promoted by-ref property reads
     * to static analysis as written and never read, since the read happens through the reference.
     *
     * @param  Node\Stmt[]  $ast
     */
    private function findMethod(array $ast, string $name): ?Node\Stmt\ClassMethod
    {
        $holder = new \stdClass;
        $holder->found = null;

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($holder, $name) extends NodeVisitorAbstract
        {
            public function __construct(private \stdClass $holder, private string $name) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === $this->name) {
                    $this->holder->found = $node;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });
        $traverser->traverse($ast);

        /** @var Node\Stmt\ClassMethod|null */
        return $holder->found;
    }

    /** A printed expression as one row: a multi-line value would break the list it is rendered in. */
    private function oneLine(string $code): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $code));
    }

    private function printer(): PrettyPrinter
    {
        return $this->printer ??= new PrettyPrinter;
    }

    public function listClassMethods(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $out = [];
        $traverser = new NodeTraverser;
        $visitor = new class($out) extends NodeVisitorAbstract
        {
            /**
             * @param  list<array{name: string, static: bool, visibility: string}>  $out
             */
            // @phpstan-ignore-next-line property.onlyWritten
            public function __construct(private array &$out) {}

            public function enterNode(Node $node): ?int
            {
                if (! $node instanceof Node\Stmt\Class_) {
                    return null;
                }
                foreach ($node->stmts as $stmt) {
                    if (! $stmt instanceof Node\Stmt\ClassMethod) {
                        continue;
                    }
                    $name = $stmt->name->toString();
                    if (str_starts_with($name, '__')) {
                        continue;
                    }
                    $vis = $stmt->isPrivate() ? 'private' : ($stmt->isProtected() ? 'protected' : 'public');
                    if ($vis === 'private') {
                        continue;
                    }
                    $this->out[] = [
                        'name' => $name,
                        'static' => $stmt->isStatic(),
                        'visibility' => $vis,
                    ];
                }

                return NodeVisitor::STOP_TRAVERSAL;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return $out;
    }

    public function fileDeclaresEnumOrInterface(string $file): bool
    {
        $info = $this->inspectFile($file);

        return $info !== null && in_array($info['kind'], ['enum', 'interface'], true);
    }
}
