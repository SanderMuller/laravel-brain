<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Statically extracts key → rule expressions from a Form Request (or any class) rules() method.
 *
 * @phpstan-type RuleRow array{field: string, rules: string}
 */
final class ValidationRulesExtractor
{
    private PhpFileParser $parser;

    private PrettyPrinter $printer;

    /**
     * Whether a file declares a non-abstract rules(), keyed by path + mtime + size.
     *
     * The answer is asked once per graph edge that could reach a Form Request, and the same file
     * is reached from many edges: measured over three applications, 1,116 / 604 / 3,936 calls
     * concerned 345 / 142 / 370 distinct files. The parse itself is already shared, but the
     * traversal that looks for rules() was repeated every time — and when the answer is "no", as
     * it usually is, nothing stops it early and it walks the whole file.
     *
     * @var array<string, bool>
     */
    private array $hasRulesMemo = [];

    /**
     * Entry cap, enforced the way {@see PhpFileParser} enforces its own: insertion-ordered
     * eviction of the oldest quarter. A single build cannot come near it — the most any of the
     * three applications measured asks about is 370 distinct files. It bounds an extractor that
     * outlives one build, where every edit mints a key and the superseded entry would otherwise
     * stay for the life of the process.
     */
    private const MEMO_MAX = 8000;

    public function __construct(?PhpFileParser $parser = null)
    {
        $this->parser = $parser ?? new PhpFileParser;
        $this->printer = new PrettyPrinter;
    }

    public function hasNonAbstractRulesMethod(string $file): bool
    {
        $stat = @stat($file);
        if ($stat === false || ! is_file($file)) {
            return false;
        }

        // Mirrors PhpFileParser's key and its rule for files being edited this very second: a
        // file whose mtime is not yet in the past can change again without changing its key, so
        // it is answered from source and not remembered.
        $settled = $stat['mtime'] < time();
        $key = $file.':'.$stat['mtime'].':'.$stat['size'];
        if ($settled && isset($this->hasRulesMemo[$key])) {
            return $this->hasRulesMemo[$key];
        }

        $verdict = $this->computeHasNonAbstractRulesMethod($file);
        if (! $settled) {
            return $verdict;
        }

        $this->evictIfFull();

        return $this->hasRulesMemo[$key] = $verdict;
    }

    /** Drop the oldest quarter in one pass, rather than one entry per insert from here on. */
    private function evictIfFull(): void
    {
        if (count($this->hasRulesMemo) < self::MEMO_MAX) {
            return;
        }

        $evict = intdiv(self::MEMO_MAX, 4);
        foreach (array_keys($this->hasRulesMemo) as $key) {
            unset($this->hasRulesMemo[$key]);
            if (--$evict <= 0) {
                return;
            }
        }
    }

    private function computeHasNonAbstractRulesMethod(string $file): bool
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return false;
        }

        $method = $this->findRulesMethod($parsed['ast']);

        return $method !== null && ! $method->isAbstract();
    }

    /**
     * @return list<RuleRow>
     */
    public function extractFromFile(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $method = $this->findRulesMethod($parsed['ast']);
        if ($method === null || $method->isAbstract()) {
            return [];
        }

        $returns = $this->collectReturnExpressions($method->stmts ?? []);
        if ($returns === []) {
            return [];
        }

        $rows = [];
        foreach ($returns as $expr) {
            if ($expr instanceof Expr\Array_) {
                foreach ($expr->items as $item) {
                    if (! $item instanceof Expr\ArrayItem || $item->unpack) {
                        continue;
                    }
                    $field = $item->key === null
                        ? '*'
                        : trim($this->printer->prettyPrintExpr($item->key));
                    $rules = $this->describeRulesValue($item->value);
                    $rows[] = ['field' => $field, 'rules' => $rules];
                }
            } else {
                $rows[] = [
                    'field' => '*',
                    'rules' => trim($this->printer->prettyPrintExpr($expr)),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  Stmt[]  $ast
     */
    private function findRulesMethod(array $ast): ?Stmt\ClassMethod
    {
        $holder = new \stdClass;
        $holder->found = null;

        $traverser = new NodeTraverser;
        $visitor = new class($holder) extends NodeVisitorAbstract
        {
            public function __construct(private \stdClass $holder) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\ClassMethod && $node->name->toString() === 'rules') {
                    $this->holder->found = $node;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        /** @var Stmt\ClassMethod|null */
        return $holder->found;
    }

    /**
     * @param  Stmt[]  $stmts
     * @return Expr[]
     */
    private function collectReturnExpressions(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            $out = array_merge($out, $this->collectReturnExpressionsFromStmt($stmt));
        }

        return $out;
    }

    /**
     * @return Expr[]
     */
    private function collectReturnExpressionsFromStmt(Stmt $stmt): array
    {
        if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
            return [$stmt->expr];
        }

        if ($stmt instanceof Stmt\If_) {
            $inner = $this->collectReturnExpressions($stmt->stmts);
            foreach ($stmt->elseifs as $elseif) {
                $inner = array_merge($inner, $this->collectReturnExpressions($elseif->stmts));
            }
            if ($stmt->else instanceof Stmt\Else_) {
                $inner = array_merge($inner, $this->collectReturnExpressions($stmt->else->stmts));
            }

            return $inner;
        }

        if ($stmt instanceof Stmt\Switch_) {
            $inner = [];
            foreach ($stmt->cases as $case) {
                $inner = array_merge($inner, $this->collectReturnExpressions($case->stmts));
            }

            return $inner;
        }

        if ($stmt instanceof Stmt\TryCatch) {
            $inner = $this->collectReturnExpressions($stmt->stmts);
            foreach ($stmt->catches as $catch) {
                $inner = array_merge($inner, $this->collectReturnExpressions($catch->stmts));
            }
            if ($stmt->finally instanceof Stmt\Finally_) {
                $inner = array_merge($inner, $this->collectReturnExpressions($stmt->finally->stmts));
            }

            return $inner;
        }

        if ($stmt instanceof Stmt\Foreach_) {
            return $this->collectReturnExpressions($stmt->stmts);
        }

        if ($stmt instanceof Stmt\For_) {
            return $this->collectReturnExpressions($stmt->stmts);
        }

        if ($stmt instanceof Stmt\While_) {
            return $this->collectReturnExpressions($stmt->stmts);
        }

        if ($stmt instanceof Stmt\Do_) {
            return $this->collectReturnExpressions($stmt->stmts);
        }

        return [];
    }

    private function describeRulesValue(Expr $expr): string
    {
        if ($expr instanceof Expr\Array_) {
            $parts = [];
            foreach ($expr->items as $item) {
                if (! $item instanceof Expr\ArrayItem || $item->unpack) {
                    continue;
                }
                $parts[] = trim($this->printer->prettyPrintExpr($item->value));
            }

            return implode(', ', $parts);
        }

        return trim($this->printer->prettyPrintExpr($expr));
    }
}
