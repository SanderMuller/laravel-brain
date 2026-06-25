<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Links dispatched events to the listeners that handle them.
 *
 * This pass discovers listeners by convention: a class under the configured
 * listener directories whose `handle()` type-hints an event in its first
 * parameter is treated as a listener for that event. It emits one
 * event → listener CallChainEdge per discovered pairing, so the graph can
 * answer "what runs when this event is dispatched".
 *
 * Other registration sources ($listen arrays, subscribers, attributes) are
 * intentionally out of scope here and handled as follow-ups.
 */
class ListenerAnalyzer
{
    private PhpFileParser $parser;

    /** @var string[] directories (relative to project root) to scan for listeners */
    private array $listenerPaths;

    /**
     * @param  string[]  $listenerPaths
     */
    public function __construct(array $listenerPaths = ['app/Listeners'])
    {
        $this->parser = new PhpFileParser;
        $this->listenerPaths = $listenerPaths !== [] ? $listenerPaths : ['app/Listeners'];
    }

    /**
     * @return CallChainEdge[] event → listener edges
     */
    public function analyze(string $projectRoot): array
    {
        return $this->discoverByConvention($projectRoot);
    }

    /**
     * @return CallChainEdge[]
     */
    private function discoverByConvention(string $projectRoot): array
    {
        $edges = [];

        foreach ($this->listenerPaths as $relativePath) {
            $basePath = rtrim($projectRoot, '/').'/'.ltrim($relativePath, '/');
            if (! is_dir($basePath)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }
                $pairing = $this->pairingFromFile($fileInfo->getPathname());
                if ($pairing !== null) {
                    [$eventFqcn, $listenerFqcn] = $pairing;
                    $edges[] = new CallChainEdge(
                        callerFqcn: $eventFqcn,
                        callerMethod: '__construct',
                        calleeFqcn: $listenerFqcn,
                        calleeMethod: 'handle',
                        type: 'listener',
                    );
                }
            }
        }

        return $edges;
    }

    /**
     * @return array{0: string, 1: string}|null [eventFqcn, listenerFqcn]
     */
    private function pairingFromFile(string $file): ?array
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }
        $useMap = $parsed['useMap'];

        $traverser = new NodeTraverser;
        $visitor = new class($useMap) extends NodeVisitorAbstract
        {
            public ?string $listenerFqcn = null;

            public ?string $eventFqcn = null;

            private array $useMap;

            private string $namespace = '';

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
                    $this->namespace = $node->name->toString();
                }
                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $this->listenerFqcn = $this->namespace !== ''
                        ? $this->namespace.'\\'.$node->name->toString()
                        : $node->name->toString();
                }
                if ($node instanceof Node\Stmt\ClassMethod
                    && in_array($node->name->toString(), ['handle', '__invoke'], true)) {
                    $param = $node->params[0] ?? null;
                    if ($param !== null && $param->type instanceof Node\Name) {
                        $this->eventFqcn = $this->resolve($param->type->toString());
                    }
                }

                return null;
            }

            private function resolve(string $name): string
            {
                if (isset($this->useMap[$name])) {
                    return $this->useMap[$name];
                }
                if (str_contains($name, '\\')) {
                    return ltrim($name, '\\');
                }

                return ($this->namespace !== '' ? $this->namespace.'\\' : '').$name;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        if ($visitor->listenerFqcn === null || $visitor->eventFqcn === null) {
            return null;
        }

        return [$visitor->eventFqcn, $visitor->listenerFqcn];
    }
}
