<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class ConsoleCommandDefinition
{
    public function __construct(
        public string $signature,
        public string $description,
        public string $class,       // FQCN for class-based, '' for closures
        public string $file,
        public string $source,      // 'route' | 'class' | 'kernel'
    ) {}
}

class ScheduleEntry
{
    public function __construct(
        public string $type,        // 'command' | 'job' | 'call'
        public string $target,      // command signature or job FQCN
        public string $frequency,   // 'daily' | 'hourly' | etc.
        public string $file,
    ) {}
}

class ConsoleAnalyzer
{
    /** @var string[] Schedule methods that state a cadence. */
    public const FREQUENCY_METHODS = [
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFiveMinutes',
        'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly',
        'hourlyAt', 'daily', 'dailyAt', 'twiceDaily', 'weekly', 'weeklyOn',
        'monthly', 'monthlyOn', 'twiceMonthly', 'lastDayOfMonth', 'quarterly',
        'yearly', 'cron', 'timezone',
    ];

    private PhpFileParser $parser;

    /** @var string[] */
    private array $consoleRoutePaths;

    /** @var string[] */
    private array $classPaths;

    /** @var string[] */
    private array $kernelPaths;

    /**
     * @param  string[]  $consoleRoutePaths  Glob patterns for closure-command route files (basename must contain "console").
     * @param  string[]  $classPaths  Glob patterns for directories containing Command classes.
     * @param  string[]  $kernelPaths  Glob patterns pointing to Console Kernel file(s).
     */
    public function __construct(
        array $consoleRoutePaths = ['routes/*/*.php'],
        array $classPaths = ['app/Console/Commands/*/*.php'],
        array $kernelPaths = ['app/Console/Kernel.php'],
    ) {
        $this->parser = new PhpFileParser;
        $this->consoleRoutePaths = $consoleRoutePaths ?: ['routes/*/*.php'];
        $this->classPaths = $classPaths ?: ['app/Console/Commands/*/*.php'];
        $this->kernelPaths = $kernelPaths ?: ['app/Console/Kernel.php'];
    }

    /**
     * @return array{commands: ConsoleCommandDefinition[], schedule: ScheduleEntry[]}
     */
    public function analyze(string $projectRoot): array
    {
        $commands = [];
        $schedule = [];
        $root = rtrim($projectRoot, '/');

        // 1. Closure-based commands and schedule entries. Laravel's own skeleton keeps
        //    both in routes/console.php, but a schedule split out of it is conventionally
        //    routes/schedule.php — a file the "console" keyword alone never reaches.
        foreach ($this->consoleRoutePaths as $pattern) {
            $baseDir = $this->resolveBaseDir($root, $pattern);
            foreach ($this->findFilesContaining($baseDir, ['console', 'schedule']) as $file) {
                $result = $this->parseConsoleRouteFile($file);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // 2. Command classes
        foreach ($this->classPaths as $pattern) {
            $commandsDir = $this->resolveBaseDir($root, $pattern);
            if (is_dir($commandsDir)) {
                $commands = array_merge($commands, $this->scanCommandClasses($commandsDir));
            }
        }

        // 3. Kernel.php — $commands property + schedule() method
        foreach ($this->kernelPaths as $pattern) {
            foreach ($this->resolveKernelFiles($root, $pattern) as $kernelFile) {
                $result = $this->parseKernel($kernelFile);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // Deduplicate: class/route-sourced entries win over kernel entries.
        // Kernel.php usually re-lists classes already found in Commands/.
        // Index by signature only — one canonical entry per signature.
        $bySignature = [];
        $byFqcn = [];

        // Pass 1: index non-kernel commands (they carry the real signature + description)
        foreach ($commands as $cmd) {
            if ($cmd->source === 'kernel') {
                continue;
            }
            $bySignature[$cmd->signature] = $cmd;
            if ($cmd->class) {
                $byFqcn[$cmd->class] = $cmd;
            }
        }

        // Pass 2: add kernel entries only when not already covered
        foreach ($commands as $cmd) {
            if ($cmd->source !== 'kernel') {
                continue;
            }
            if (isset($byFqcn[$cmd->class]) || isset($byFqcn[$cmd->signature])) {
                continue;
            }
            if (isset($bySignature[$cmd->signature])) {
                continue;
            }
            $bySignature[$cmd->signature] = $cmd;
        }

        return ['commands' => array_values($bySignature), 'schedule' => $schedule];
    }

    // ── Console route file ────────────────────────────────────────────────────

    private function parseConsoleRouteFile(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            /** @var array<int, string> spl_object_id of a static call => frequency read off its chain */
            private array $chainFrequencies = [];

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
                // Frequency lives on the calls wrapped AROUND the registration
                // (`Schedule::command(...)->dailyAt(...)`), and a node cannot see its own
                // parents. Traversal is top-down, so the chain is read on the way in and
                // parked for the static call that arrives a few nodes later.
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->rememberChainFrequency($node);
                }

                if (! $node instanceof Node\Expr\StaticCall) {
                    return null;
                }
                if (! $node->class instanceof Node\Name) {
                    return null;
                }

                $class = $node->class->getLast();
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                // Artisan::command('signature', closure)
                if ($class === 'Artisan' && $method === 'command') {
                    $sig = $this->strArg($node->args[0] ?? null);
                    if ($sig !== null) {
                        $this->commands[] = new ConsoleCommandDefinition(
                            signature: $sig,
                            description: '',
                            class: '',
                            file: $this->file,
                            source: 'route',
                        );
                    }
                }

                // Schedule::command('sig')->daily(), and the job/call siblings
                if ($class === 'Schedule' && in_array($method, ['command', 'job', 'call'], true)) {
                    $target = $this->scheduleTarget($method, $node->args[0] ?? null);
                    if ($target !== null) {
                        $this->schedule[] = new ScheduleEntry(
                            type: $method,
                            target: $target,
                            frequency: $this->walkChainForFrequency($node),
                            file: $this->file,
                        );
                    }
                }

                return null;
            }

            /** What a scheduled entry points at: a signature, a job class, or a closure. */
            private function scheduleTarget(string $method, ?Node\Arg $arg): ?string
            {
                if ($method === 'call') {
                    return 'Closure';
                }

                $signature = $this->strArg($arg);
                if ($signature !== null) {
                    return $signature;
                }

                if ($arg?->value instanceof Node\Expr\ClassConstFetch
                    && $arg->value->class instanceof Node\Name
                    && $arg->value->name instanceof Node\Identifier
                    && $arg->value->name->toString() === 'class') {
                    // The parser preserves original names, so `Job::class` reads as the short
                    // name it was written with; the resolved name is on the attribute.
                    return PhpFileParser::resolvedName($arg->value->class)
                        ?? $arg->value->class->toString();
                }

                return null;
            }

            private function walkChainForFrequency(Node $node): string
            {
                return $this->chainFrequencies[spl_object_id($node)] ?? '';
            }

            /**
             * Read the first frequency method off a `Schedule::…()->frequency()->…` chain and
             * park it under the static call the chain is built on.
             */
            private function rememberChainFrequency(Node\Expr\MethodCall $node): void
            {
                $frequency = '';
                $current = $node;

                while ($current instanceof Node\Expr\MethodCall) {
                    $name = $current->name instanceof Node\Identifier ? $current->name->toString() : '';
                    if ($frequency === '' && in_array($name, ConsoleAnalyzer::FREQUENCY_METHODS, true)) {
                        $frequency = $name;
                    }
                    $current = $current->var;
                }

                if ($frequency === '' || ! $current instanceof Node\Expr\StaticCall) {
                    return;
                }

                $this->chainFrequencies[spl_object_id($current)] ??= $frequency;
            }

            private function strArg(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $val = $node instanceof Node\Arg ? $node->value : $node;

                return $val instanceof Node\Scalar\String_ ? $val->value : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return ['commands' => $visitor->commands, 'schedule' => $visitor->schedule];
    }

    // ── Command classes ───────────────────────────────────────────────────────

    private function scanCommandClasses(string $dir): array
    {
        $commands = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $parsed = $this->parser->parse($entry->getPathname());
            if (! $parsed || ! $parsed['ast']) {
                continue;
            }

            $cmd = $this->extractCommandDefinition($parsed['ast'], $entry->getPathname());
            if ($cmd !== null) {
                $commands[] = $cmd;
            }
        }

        return $commands;
    }

    private function extractCommandDefinition(array $ast, string $file): ?ConsoleCommandDefinition
    {
        $traverser = new NodeTraverser;
        $visitor = new class($file) extends NodeVisitorAbstract
        {
            public ?ConsoleCommandDefinition $result = null;

            private ?string $namespace = null;

            private ?string $className = null;

            private ?string $signature = null;

            private ?string $description = null;

            private ?string $attributeSignature = null;

            private ?string $attributeDescription = null;

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                }
                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                    $this->readCommandAttributes($node);
                }
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $name = $prop->name->toString();
                        // $name is the pre-signature spelling; it still names the command.
                        if (($name === 'signature' || $name === 'name') && $prop->default instanceof Node\Scalar\String_) {
                            $this->signature ??= $prop->default->value;
                        }
                        if ($name === 'description' && $prop->default instanceof Node\Scalar\String_) {
                            $this->description = $prop->default->value;
                        }
                    }
                }

                return null;
            }

            public function afterTraverse(array $nodes): ?int
            {
                // A property wins over an attribute: that is the precedence Laravel itself
                // applies when a command declares both.
                $signature = $this->signature ?? $this->attributeSignature;
                $description = $this->description ?? $this->attributeDescription;

                if ($this->className && $signature !== null) {
                    $fqcn = $this->namespace
                        ? $this->namespace.'\\'.$this->className
                        : $this->className;

                    $this->result = new ConsoleCommandDefinition(
                        signature: $signature,
                        description: $description ?? '',
                        class: $fqcn,
                        file: $this->file,
                        source: 'class',
                    );
                }

                return null;
            }

            /**
             * Laravel 12 declares a command's signature and description as class
             * attributes rather than properties, and Symfony's #[AsCommand] carries the
             * same two values. A command written either way has no $signature property
             * at all, so reading properties alone finds nothing.
             */
            private function readCommandAttributes(Node\Stmt\Class_ $node): void
            {
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        switch ($attribute->name->getLast()) {
                            case 'Signature':
                                $this->attributeSignature ??= $this->attributeArg($attribute->args, 'signature', 0);
                                break;
                            case 'Description':
                                $this->attributeDescription ??= $this->attributeArg($attribute->args, 'description', 0);
                                break;
                            case 'AsCommand':
                                $this->attributeSignature ??= $this->attributeArg($attribute->args, 'name', 0);
                                $this->attributeDescription ??= $this->attributeArg($attribute->args, 'description', 1);
                                break;
                        }
                    }
                }
            }

            /**
             * Read a string attribute argument given either by name or by position.
             *
             * @param  Node\Arg[]  $args
             */
            private function attributeArg(array $args, string $name, int $position): ?string
            {
                $index = 0;

                foreach ($args as $arg) {
                    $named = $arg->name instanceof Node\Identifier && $arg->name->toString() === $name;
                    $positional = $arg->name === null && $index === $position;

                    if (($named || $positional) && $arg->value instanceof Node\Scalar\String_) {
                        return $arg->value->value;
                    }

                    if ($arg->name === null) {
                        $index++;
                    }
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->result;
    }

    // ── Kernel.php ────────────────────────────────────────────────────────────

    private function parseKernel(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $useMap = $parsed['useMap'] ?? [];
        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file, $useMap) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            public function __construct(
                private string $file,
                private array $useMap,
            ) {}

            public function enterNode(Node $node): ?int
            {
                // protected $commands = [FooCommand::class, ...]
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() !== 'commands') {
                            continue;
                        }
                        if (! $prop->default instanceof Node\Expr\Array_) {
                            continue;
                        }

                        foreach ($prop->default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $fqcn = $this->resolveClassConst($item->value);
                            if ($fqcn) {
                                $this->commands[] = new ConsoleCommandDefinition(
                                    signature: $fqcn,
                                    description: '',
                                    class: $fqcn,
                                    file: $this->file,
                                    source: 'kernel',
                                );
                            }
                        }
                    }
                }

                // $schedule->command('sig')->daily()
                // $schedule->job(new MyJob)->hourly()
                // $schedule->call(function(){})->everyMinute()
                if ($node instanceof Node\Expr\MethodCall) {
                    $method = $node->name instanceof Node\Identifier
                        ? $node->name->toString()
                        : null;

                    if ($method === 'command' && ! empty($node->args)) {
                        $sig = $this->strArg($node->args[0]);
                        if ($sig) {
                            $this->schedule[] = new ScheduleEntry(
                                type: 'command',
                                target: $sig,
                                frequency: $this->chainFrequency($node),
                                file: $this->file,
                            );
                        }
                    }

                    if ($method === 'job' && ! empty($node->args)) {
                        $arg = $node->args[0]->value;
                        $target = '';
                        if ($arg instanceof Node\Expr\New_ && $arg->class instanceof Node\Name) {
                            $target = $this->resolveClass($arg->class->toString());
                        }
                        if ($target) {
                            $this->schedule[] = new ScheduleEntry(
                                type: 'job',
                                target: $target,
                                frequency: $this->chainFrequency($node),
                                file: $this->file,
                            );
                        }
                    }

                    if ($method === 'call') {
                        $this->schedule[] = new ScheduleEntry(
                            type: 'call',
                            target: 'Closure',
                            frequency: $this->chainFrequency($node),
                            file: $this->file,
                        );
                    }
                }

                return null;
            }

            /** Walk the method chain to find the first frequency-like method. */
            private function chainFrequency(Node\Expr\MethodCall $node): string
            {
                // The node itself may be wrapped by frequency calls further up;
                // we look at the var chain (the receiver of this call)
                $current = $node;
                while ($current instanceof Node\Expr\MethodCall) {
                    $m = $current->name instanceof Node\Identifier
                        ? $current->name->toString()
                        : '';
                    if (in_array($m, ConsoleAnalyzer::FREQUENCY_METHODS, true)) {
                        return $m;
                    }
                    $current = $current->var;
                }

                return '';
            }

            private function resolveClassConst(Node $node): string
            {
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'class') {
                    return $this->resolveClass($node->class->toString());
                }

                return '';
            }

            private function resolveClass(string $name): string
            {
                return $this->useMap[$name] ?? $name;
            }

            private function strArg(Node\Arg $arg): ?string
            {
                return $arg->value instanceof Node\Scalar\String_
                    ? $arg->value->value
                    : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return ['commands' => $visitor->commands, 'schedule' => $visitor->schedule];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  string[]  $keywords
     * @return string[]
     */
    private function findFilesContaining(string $dir, array $keywords): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $basename = strtolower($entry->getBasename());
            foreach ($keywords as $keyword) {
                if (str_contains($basename, $keyword)) {
                    $files[] = $entry->getPathname();
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * Resolves kernel file(s) from a pattern.
     * Patterns without wildcards are treated as literal paths.
     * Patterns with wildcards scan the resolved base dir for matching .php files.
     *
     * @return string[]
     */
    private function resolveKernelFiles(string $root, string $pattern): array
    {
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?') && ! str_contains($pattern, '[')) {
            $path = $root.'/'.ltrim($pattern, '/');

            return file_exists($path) ? [$path] : [];
        }

        $baseDir = $this->resolveBaseDir($root, $pattern);
        if (! is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function resolveBaseDir(string $root, string $pattern): string
    {
        $segments = explode('/', ltrim($pattern, '/'));
        $fixed = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '*') || str_contains($segment, '?') || str_contains($segment, '[')) {
                break;
            }
            $fixed[] = $segment;
        }

        if (! empty($fixed) && str_ends_with(end($fixed), '.php')) {
            array_pop($fixed);
        }

        $subPath = implode('/', $fixed);

        return $subPath !== '' ? $root.'/'.$subPath : $root;
    }
}
