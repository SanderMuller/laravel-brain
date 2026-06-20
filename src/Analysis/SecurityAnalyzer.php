<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Security Surface Analyzer
 *
 * Classifies every route by its authentication exposure level and detects
 * common security issues by statically analyzing controller source code.
 *
 * Exposure levels:
 *   public  — no auth/guest/admin middleware at all
 *   guest   — guarded by the `guest` middleware (redirect when authenticated)
 *   authed  — guarded by auth / auth:sanctum / jwt / passport / etc.
 *   admin   — guarded by can:*, role:*, permission:*, ability:*, admin, gate:*
 *
 * Detected issues:
 *   MASS_ASSIGNMENT   — create/fill called with $request->all()
 *   UNVALIDATED_INPUT — $request->all() used with no validate() or FormRequest
 *   MISSING_THROTTLE  — sensitive URI (login/register/…) missing throttle middleware
 *   PUBLIC_WRITE      — mutating HTTP method (POST/PUT/PATCH/DELETE) with no auth
 */
class SecurityAnalyzer
{
    // ── Middleware pattern matchers ──────────────────────────────────────────

    /**
     * Middleware aliases / FQCN prefixes that imply authentication is required.
     * Matched as prefix (case-insensitive) so `auth:sanctum` and
     * `Illuminate\Auth\Middleware\Authenticate` both match.
     *
     * `signed` and `ValidateSignature` are included because Laravel's signed
     * URL middleware verifies a cryptographic HMAC of the URL — that's
     * authentication of the request, even though it isn't tied to a user.
     */
    private const AUTH_PATTERNS = [
        'auth',
        'sanctum',
        'jwt',
        'passport',
        'verified',
        'signed',
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Routing\\Middleware\\ValidateSignature',
    ];

    /** Middleware that redirects authenticated users away (login/register pages). */
    private const GUEST_PATTERNS = [
        'guest',
        'Illuminate\\Auth\\Middleware\\RedirectIfAuthenticated',
    ];

    /**
     * Middleware that imply elevated / admin-level authorization.
     * Matched as prefix so `can:edit-posts` and `role:admin` both match.
     */
    private const ADMIN_PATTERNS = [
        'can:',
        'role:',
        'permission:',
        'ability:',
        'gate:',
        'admin',
        'Illuminate\\Auth\\Middleware\\Authorize',
    ];

    /** Middleware that provides rate-limiting. */
    private const THROTTLE_PATTERNS = [
        'throttle',
        'Illuminate\\Routing\\Middleware\\ThrottleRequests',
    ];

    /**
     * URI keywords that identify sensitive authentication/account endpoints
     * which should always be rate-limited.
     */
    private const SENSITIVE_URI_KEYWORDS = [
        'login', 'logout', 'register', 'password', 'reset', 'forgot',
        'verify', '2fa', 'otp', 'token', 'auth', 'signup', 'signin',
    ];

    /** Variable name substrings that suggest HTML/rich-text user content. */
    private const HIGH_RISK_VAR_PATTERNS = [
        'html', 'body', 'content', 'description', 'text', 'message',
        'comment', 'note', 'remark', 'bio', 'about', 'summary',
        'input', 'data', 'raw', 'unsafe', 'user', 'post', 'output',
    ];

    // ── State ────────────────────────────────────────────────────────────────

    private PhpFileParser $parser;

    /** Bounded parse-result cache (file => parsed). LRU-evicts at 150 entries. */
    private array $parseCache = [];

    /** Project root set at the start of analyze() so all sub-methods can read it. */
    private string $projectRoot = '';

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $externalByFile = null;

    /**
     * Effective auth-middleware patterns (defaults + $extraAuthPatterns),
     * pre-computed so classifyExposure() doesn't re-merge per route.
     *
     * @var list<string>
     */
    private array $authPatterns;

    /**
     * Effective throttle-middleware patterns (defaults + $extraThrottlePatterns).
     *
     * @var list<string>
     */
    private array $throttlePatterns;

    /**
     * @param  list<string>  $extraAuthPatterns  User-configured extra auth middleware
     *                                           (from `laravel-brain.security.auth_middleware`).
     * @param  list<string>  $extraThrottlePatterns  User-configured extra throttle middleware
     *                                               (from `laravel-brain.security.throttle_middleware`).
     * @param  list<string>  $trustedRouteNames  Route-name glob patterns whose routes
     *                                           authenticate outside of Laravel's middleware system —
     *                                           webhooks with HMAC verification, token-in-URL flows,
     *                                           custom api-key middleware. PUBLIC_WRITE is
     *                                           suppressed for matching routes.
     * @param  list<string>  $trustedRouteUris  Route-URI glob patterns, same semantics as
     *                                          $trustedRouteNames. Matched against the route URI
     *                                          with the leading slash stripped.
     */
    public function __construct(
        private array $extraAuthPatterns = [],
        private array $extraThrottlePatterns = [],
        private array $trustedRouteNames = [],
        private array $trustedRouteUris = [],
    ) {
        $this->parser = new PhpFileParser;
        $this->authPatterns = array_values(array_unique(
            array_merge(self::AUTH_PATTERNS, $this->extraAuthPatterns),
        ));
        $this->throttlePatterns = array_values(array_unique(
            array_merge(self::THROTTLE_PATTERNS, $this->extraThrottlePatterns),
        ));
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Analyse every route and return a map of:
     *   routeId => ['exposure' => string, 'issues' => array[], 'riskLevel' => string]
     *
     * @param  RouteDefinition[]  $routes
     * @param  array<string, ControllerDefinition>  $controllers
     * @return array<string, array>
     */
    /**
     * @param  array<string, list<array<string, mixed>>>|null  $externalByFile
     *                                                                          When provided, source-level findings come from the external
     *                                                                          scanner instead of Brain's built-in AST scan (hybrid mode).
     */
    public function analyze(
        array $routes,
        MiddlewareRegistry $middlewareRegistry,
        array $controllers,
        string $projectRoot,
        ?array $externalByFile = null,
    ): array {
        $this->projectRoot = $projectRoot;
        $this->externalByFile = $externalByFile;
        $results = [];

        foreach ($routes as $route) {
            $routeId = "route::{$route->method}::{$route->uri}";
            $resolvedMw = $this->resolveMiddlewares($route->middlewares, $middlewareRegistry);

            $exposure = $this->classifyExposure($resolvedMw);
            $issues = [];

            $isTrusted = $this->isTrustedRoute($route);
            $hasThrottleMiddleware = $this->hasMiddlewareMatching($resolvedMw, $this->throttlePatterns);
            $controllerDef = ($route->controller !== '' && $route->controller !== 'Closure')
                ? ($controllers[$route->controller] ?? null)
                : null;

            // ── 1. Missing throttle on sensitive endpoints ───────────────────
            if (in_array($route->method, ['POST', 'PUT', 'PATCH'], true)
                && $this->isSensitiveUri($route->uri)
                && ! $hasThrottleMiddleware
                && ! $this->hasInCodeThrottle($route, $controllerDef)
            ) {
                $issues[] = (new SecurityIssue(
                    type: 'MISSING_THROTTLE',
                    severity: 'high',
                    message: "Sensitive endpoint `{$route->uri}` has no rate-limiting — add `throttle:` middleware to prevent brute-force attacks.",
                ))->toArray();
            }

            // ── 2. Unauthenticated write operations ─────────────────────────
            if (in_array($route->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && $exposure === 'public'
                && ! $isTrusted
            ) {
                $severity = $route->method === 'DELETE' ? 'high' : 'medium';
                $issues[] = (new SecurityIssue(
                    type: 'PUBLIC_WRITE',
                    severity: $severity,
                    message: "Mutating `{$route->method} {$route->uri}` requires no authentication — anyone can call this endpoint.",
                ))->toArray();
            }

            // ── 3. Controller / closure source scan ──────────────────────────
            // Hybrid mode: when the external scanner ran, source-level
            // findings come from it; otherwise fall back to the built-in
            // AST scan. Route-policy + exposure logic above is unchanged.
            if ($route->controller !== '' && $route->controller !== 'Closure') {
                $controllerDef = $controllers[$route->controller] ?? null;
                if ($controllerDef !== null && is_file($controllerDef->file)) {
                    if ($this->externalByFile !== null) {
                        $issues = array_merge(
                            $issues,
                            $this->externalIssuesForMethod($controllerDef->file, $route->action),
                        );
                    } else {
                        $issues = array_merge($issues, $this->scanControllerMethod(
                            $controllerDef->file,
                            $route->action,
                            $controllerDef->useMap,
                        ));
                    }
                }
            } elseif ($route->closureNode !== null) {
                if ($this->externalByFile !== null) {
                    $issues = array_merge(
                        $issues,
                        $this->externalIssuesInRange(
                            $route->file,
                            $route->closureNode->getStartLine(),
                            $route->closureNode->getEndLine(),
                        ),
                    );
                } else {
                    $useMap = $route->closureUseMap ?? [];
                    $closureIssues = $this->scanAstNode(
                        $route->closureNode,
                        $useMap,
                        hasFormRequest: false,
                        file: null,
                    );
                    $xssIssues = $this->scanForXss($route->closureNode, $useMap, null);
                    $issues = array_merge($issues, $closureIssues, $xssIssues);
                }
            }

            // Deduplicate by (type, message) to avoid double-counting
            $issues = $this->deduplicateIssues($issues);

            $results[$routeId] = [
                'exposure' => $exposure,
                'riskLevel' => $this->computeRiskLevel($issues),
                'issues' => $issues,
            ];
        }

        return $results;
    }

    // ── Exposure classification ───────────────────────────────────────────────

    private function classifyExposure(array $middlewares): string
    {
        // Admin check first (superset of authed)
        foreach ($middlewares as $mw) {
            if ($this->middlewareMatches($mw, self::ADMIN_PATTERNS)) {
                return 'admin';
            }
        }
        // Auth check
        foreach ($middlewares as $mw) {
            if ($this->middlewareMatches($mw, $this->authPatterns)) {
                return 'authed';
            }
        }
        // Guest check
        foreach ($middlewares as $mw) {
            if ($this->middlewareMatches($mw, self::GUEST_PATTERNS)) {
                return 'guest';
            }
        }

        return 'public';
    }

    private function isSensitiveUri(string $uri): bool
    {
        $lower = strtolower($uri);
        foreach (self::SENSITIVE_URI_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A route is "trusted" when the user has declared (via config) that its
     * authentication is enforced outside of Laravel's middleware system —
     * typically a webhook with HMAC verification in the controller, or an
     * endpoint authenticated by a token in the URL.
     *
     * When trusted, PUBLIC_WRITE is suppressed for the route.
     */
    private function isTrustedRoute(RouteDefinition $route): bool
    {
        if ($route->name !== '') {
            foreach ($this->trustedRouteNames as $pattern) {
                if ($this->globMatches($pattern, $route->name)) {
                    return true;
                }
            }
        }
        $uri = ltrim($route->uri, '/');
        foreach ($this->trustedRouteUris as $pattern) {
            if ($this->globMatches(ltrim($pattern, '/'), $uri)) {
                return true;
            }
        }

        return false;
    }

    private function globMatches(string $pattern, string $subject): bool
    {
        // Match 'webhooks.*' against 'webhooks.stripe' and 'webhooks/*' against
        // 'webhooks/stripe/123'. fnmatch supports both * and ? without the
        // regex caveats.
        return fnmatch($pattern, $subject, FNM_CASEFOLD);
    }

    /**
     * Detect rate-limiting performed in PHP instead of via middleware:
     *   • RateLimiter::tooManyAttempts / ::hit / ::for / ::attempt / ::availableIn
     *     in the controller method or any FormRequest used by it.
     *   • Use of the Illuminate\Foundation\Auth\ThrottlesLogins trait on the
     *     controller class (Laravel's classic Breeze/Fortify pattern).
     */
    private function hasInCodeThrottle(RouteDefinition $route, ?ControllerDefinition $controllerDef): bool
    {
        // Closure routes: scan the closure body for the same patterns.
        if ($controllerDef === null) {
            if ($route->closureNode === null) {
                return false;
            }

            return $this->astHasRateLimiterCall($route->closureNode);
        }

        if (! is_file($controllerDef->file)) {
            return false;
        }

        $parsed = $this->cachedParse($controllerDef->file);
        if ($parsed['ast'] === null) {
            return false;
        }

        if ($this->astUsesThrottlesLoginsTrait($parsed['ast'], $parsed['useMap'] ?? [])) {
            return true;
        }

        $methodNode = $this->findClassMethod($parsed['ast'], $route->action);
        if ($methodNode === null) {
            return false;
        }

        if ($this->astHasRateLimiterCall($methodNode)) {
            return true;
        }

        // Walk into any FormRequest type-hinted parameters and scan them too.
        $useMap = array_merge($controllerDef->useMap, $parsed['useMap'] ?? []);
        foreach ($this->resolveFormRequestFiles($methodNode, $useMap) as $requestFile) {
            $requestParsed = $this->cachedParse($requestFile);
            if ($requestParsed['ast'] !== null && $this->astHasRateLimiterCall($requestParsed['ast'])) {
                return true;
            }
        }

        return false;
    }

    private function astHasRateLimiterCall($node): bool
    {
        $found = false;
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($found) extends NodeVisitorAbstract
        {
            public function __construct(public bool &$found) {}

            public function enterNode(Node $node): ?int
            {
                if ($this->found) {
                    return NodeTraverser::STOP_TRAVERSAL;
                }

                // RateLimiter::tooManyAttempts(...) / ::hit(...) / ::for(...) / ::attempt(...) / ::availableIn(...)
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), [
                        'tooManyAttempts', 'hit', 'for', 'attempt', 'availableIn', 'clear',
                    ], true)
                    && $node->class instanceof Node\Name
                    && str_contains(strtolower($node->class->toString()), 'ratelimiter')
                ) {
                    $this->found = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                // $this->limiter()->tooManyAttempts(...), $r->hitRateLimit(...), etc.
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['tooManyAttempts', 'hitRateLimit'], true)
                ) {
                    $this->found = true;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });
        $traverser->traverse(is_array($node) ? $node : [$node]);

        return $found;
    }

    /**
     * Detect ThrottlesLogins (Laravel <=8 / Fortify) used on a controller class.
     *
     * @param  Node\Stmt[]  $ast
     * @param  array<string, string>  $useMap
     */
    private function astUsesThrottlesLoginsTrait(array $ast, array $useMap): bool
    {
        $found = false;
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($found, $useMap) extends NodeVisitorAbstract
        {
            public function __construct(public bool &$found, private array $useMap) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\TraitUse) {
                    foreach ($node->traits as $trait) {
                        $name = $trait->toString();
                        $fqcn = $this->useMap[$name] ?? $name;
                        if (str_contains($fqcn, 'ThrottlesLogins')) {
                            $this->found = true;

                            return NodeTraverser::STOP_TRAVERSAL;
                        }
                    }
                }

                return null;
            }
        });
        $traverser->traverse($ast);

        return $found;
    }

    /**
     * Resolve every FormRequest typed parameter of a controller method to the
     * absolute file path of its class declaration. Used so the throttle scan
     * can follow `ensureIsNotRateLimited()` in a LoginRequest etc.
     *
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    private function resolveFormRequestFiles(Node\Stmt\ClassMethod $method, array $useMap): array
    {
        $files = [];
        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }
            $typeName = $this->extractTypeName($param->type);
            if ($typeName === null) {
                continue;
            }
            $fqcn = $useMap[$typeName] ?? $typeName;
            if (! str_ends_with($fqcn, 'Request') && ! str_contains($fqcn, 'FormRequest')) {
                continue;
            }
            $file = $this->locateClassFile($fqcn);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Best-effort file lookup for a FQCN inside the project. Walks the
     * conventional PSR-4 `App\...` mapping plus a Composer-classmap fallback.
     */
    private function locateClassFile(string $fqcn): ?string
    {
        if ($this->projectRoot === '') {
            return null;
        }

        if (class_exists($fqcn, false) || class_exists($fqcn)) {
            try {
                $file = (new \ReflectionClass($fqcn))->getFileName();
                if (is_string($file) && is_file($file)) {
                    return $file;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // PSR-4 convention: App\Http\Requests\LoginRequest → app/Http/Requests/LoginRequest.php
        $candidate = rtrim($this->projectRoot, '/').'/'.str_replace(['App\\', '\\'], ['app/', '/'], ltrim($fqcn, '\\')).'.php';
        if (is_file($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function hasMiddlewareMatching(array $middlewares, array $patterns): bool
    {
        foreach ($middlewares as $mw) {
            if ($this->middlewareMatches($mw, $patterns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive prefix match against any of the given patterns.
     * A trailing colon in the pattern means it must appear in the middleware string.
     */
    private function middlewareMatches(string $middleware, array $patterns): bool
    {
        $lower = strtolower($middleware);
        foreach ($patterns as $pattern) {
            $p = strtolower($pattern);
            if (str_ends_with($p, ':')) {
                // Pattern like 'can:' — must appear somewhere (prefix match sufficient)
                if (str_starts_with($lower, $p) || $lower === rtrim($p, ':')) {
                    return true;
                }
            } else {
                // Exact match OR FQCN prefix
                if ($lower === $p || str_starts_with($lower, $p.':') || str_starts_with($lower, $p.'\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Expand middleware group aliases (auth, guest, etc.) using the registry.
     */
    private function resolveMiddlewares(array $middlewares, MiddlewareRegistry $registry): array
    {
        $resolved = [];
        foreach ($middlewares as $mw) {
            [$alias, $params] = array_pad(explode(':', $mw, 2), 2, null);
            $fqcn = $registry->resolveAlias($alias);
            $resolved[] = $params !== null ? "{$fqcn}:{$params}" : $fqcn;
        }

        return array_unique($resolved);
    }

    // ── Controller source scanning ────────────────────────────────────────────

    /**
     * @param  array<string, string>  $useMap
     * @return array[]
     */
    private function scanControllerMethod(string $file, string $action, array $useMap): array
    {
        $parsed = $this->cachedParse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $methodNode = $this->findClassMethod($parsed['ast'], $action);
        if ($methodNode === null) {
            return [];
        }

        $mergedUseMap = array_merge($useMap, $parsed['useMap'] ?? []);
        $hasFormRequest = $this->methodHasFormRequest($methodNode, $mergedUseMap);

        $massAssignIssues = $this->scanAstNode($methodNode, $mergedUseMap, $hasFormRequest, $file);
        $xssIssues = $this->scanForXss($methodNode, $mergedUseMap, $file);

        return array_merge($massAssignIssues, $xssIssues);
    }

    /**
     * Pull external-scanner findings scoped to a controller action's line
     * range (parsing only to locate the method's start/end lines).
     *
     * @return array[]
     */
    private function externalIssuesForMethod(string $file, string $action): array
    {
        $parsed = $this->cachedParse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $methodNode = $this->findClassMethod($parsed['ast'], $action);
        if ($methodNode === null) {
            return [];
        }

        return $this->externalIssuesInRange($file, $methodNode->getStartLine(), $methodNode->getEndLine());
    }

    /**
     * Convert external-scanner findings within [startLine, endLine] of a
     * file into Brain's SecurityIssue array shape.
     *
     * @return array[]
     */
    private function externalIssuesInRange(string $file, int $startLine, int $endLine): array
    {
        $real = realpath($file) ?: $file;
        $findings = $this->externalByFile[$real] ?? null;
        if ($findings === null) {
            return [];
        }

        $issues = [];
        foreach ($findings as $f) {
            $line = $f['line'] ?? null;
            if ($line !== null && ($line < $startLine || $line > $endLine)) {
                continue;
            }
            $issues[] = (new SecurityIssue(
                type: (string) $f['type'],
                severity: (string) $f['severity'],
                message: (string) $f['message'],
                file: $file,
                line: $line !== null ? (int) $line : null,
            ))->toArray();
        }

        return $issues;
    }

    /**
     * Parse with a bounded LRU cache so large codebases don't OOM.
     */
    private function cachedParse(string $file): array
    {
        if (isset($this->parseCache[$file])) {
            // Move to end (LRU)
            $hit = $this->parseCache[$file];
            unset($this->parseCache[$file]);
            $this->parseCache[$file] = $hit;

            return $hit;
        }

        if (count($this->parseCache) >= 150) {
            reset($this->parseCache);
            unset($this->parseCache[key($this->parseCache)]);
        }

        $parsed = $this->parser->parse($file);
        $this->parseCache[$file] = $parsed;

        return $parsed;
    }

    /** @param Node\Stmt[] $ast */
    private function findClassMethod(array $ast, string $methodName): ?Node\Stmt\ClassMethod
    {
        $found = null;
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($found, $methodName) extends NodeVisitorAbstract
        {
            public function __construct(
                public ?Node\Stmt\ClassMethod &$found,
                private string $name,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\ClassMethod
                    && $node->name->toString() === $this->name
                ) {
                    $this->found = $node;

                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        });
        $traverser->traverse($ast);

        return $found;
    }

    /**
     * Returns true when the method has a parameter type-hinted as a Form Request
     * (any class whose short or full name ends with "Request" or contains "FormRequest").
     *
     * @param  array<string, string>  $useMap
     */
    private function methodHasFormRequest(Node\Stmt\ClassMethod $method, array $useMap): bool
    {
        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }
            $typeName = $this->extractTypeName($param->type);
            if ($typeName === null) {
                continue;
            }
            $fqcn = $useMap[$typeName] ?? $typeName;
            if (str_ends_with($fqcn, 'Request') || str_contains($fqcn, 'FormRequest')) {
                return true;
            }
        }

        return false;
    }

    private function extractTypeName(Node $type): ?string
    {
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        return null;
    }

    /**
     * Returns true when `$node` is a method call of the form `$req->all()` or
     * `$req->input()` whose receiver is known to be an HTTP Request — either
     * a parameter typed as `Illuminate\Http\Request` (or any subclass /
     * FormRequest) in the enclosing function/method, or the `request()`
     * global helper.
     *
     * Static so the anonymous visitors inside scanAstNode() can call it
     * without needing a reference to the SecurityAnalyzer instance.
     *
     * @param  array<string, true>  $requestVars  variable names known to hold a Request
     */
    public static function isRequestAllCall(Node $node, array $requestVars): bool
    {
        if (! ($node instanceof Node\Expr\MethodCall)
            || ! ($node->name instanceof Node\Identifier)
        ) {
            return false;
        }
        $method = $node->name->toString();
        if (! in_array($method, ['all', 'input'], true)) {
            return false;
        }
        if ($method === 'input' && count($node->args) !== 0) {
            return false;
        }

        $var = $node->var;
        if ($var instanceof Node\Expr\Variable
            && is_string($var->name)
            && isset($requestVars[$var->name])
        ) {
            return true;
        }
        if ($var instanceof Node\Expr\FuncCall
            && $var->name instanceof Node\Name
            && $var->name->toString() === 'request'
            && count($var->args) === 0
        ) {
            return true;
        }

        return false;
    }

    /**
     * Find every parameter in the enclosing function/method whose type-hint
     * looks like an HTTP Request (FQCN resolves to Illuminate\Http\Request
     * or matches the Request/FormRequest convention) and return their names.
     *
     * @param  array<string, string>  $useMap
     * @return array<string, true>
     */
    private function collectRequestParamNames(Node $node, array $useMap): array
    {
        $names = [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($names, $useMap) extends NodeVisitorAbstract
        {
            public function __construct(public array &$names, private array $useMap) {}

            public function enterNode(Node $node): ?int
            {
                if (! ($node instanceof Node\FunctionLike)) {
                    return null;
                }
                foreach ($node->getParams() as $param) {
                    if ($param->type === null || ! ($param->var instanceof Node\Expr\Variable)) {
                        continue;
                    }
                    $varName = is_string($param->var->name) ? $param->var->name : null;
                    if ($varName === null) {
                        continue;
                    }
                    foreach ($this->extractTypeNames($param->type) as $typeName) {
                        $fqcn = $this->useMap[$typeName] ?? $typeName;
                        if ($this->looksLikeRequestType($fqcn)) {
                            $this->names[$varName] = true;
                            break;
                        }
                    }
                }

                return null;
            }

            /** @return list<string> */
            private function extractTypeNames(Node $type): array
            {
                if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
                    return [$type->toString()];
                }
                if ($type instanceof Node\NullableType) {
                    return $this->extractTypeNames($type->type);
                }
                if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    $names = [];
                    foreach ($type->types as $sub) {
                        foreach ($this->extractTypeNames($sub) as $n) {
                            $names[] = $n;
                        }
                    }

                    return $names;
                }

                return [];
            }

            private function looksLikeRequestType(string $fqcn): bool
            {
                $fqcn = ltrim($fqcn, '\\');
                if (str_contains($fqcn, 'Illuminate\\Http\\Request')) {
                    return true;
                }
                if (str_contains($fqcn, 'FormRequest')) {
                    return true;
                }
                if (str_contains($fqcn, '\\Http\\Requests\\') || str_ends_with($fqcn, 'Request')) {
                    return true;
                }

                return false;
            }
        });
        $traverser->traverse([$node]);

        return $names;
    }

    /**
     * Walk any AST node and collect security issues.
     *
     * Two-pass approach:
     *   Pass 1 — detect mass-assignment patterns and whether validation exists.
     *   Pass 2 — if no validation was found, flag any remaining $request->all() calls.
     *
     * @param  array<string, string>  $useMap
     * @return array[]
     */
    private function scanAstNode(
        Node $node,
        array $useMap,
        bool $hasFormRequest,
        ?string $file,
    ): array {
        $issues = [];
        $hasValidation = $hasFormRequest;
        $requestVars = $this->collectRequestParamNames($node, $useMap);

        // ── Pass 1: mass-assignment + validation detection ───────────────────
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($issues, $hasValidation, $useMap, $file, $requestVars) extends NodeVisitorAbstract
        {
            public function __construct(
                public array &$issues,
                public bool &$hasValidation,
                private array $useMap,
                private ?string $file,
                private array $requestVars,
            ) {}

            public function enterNode(Node $node): ?int
            {
                // ── Detect validation calls ──────────────────────────────────
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['validate', 'validateWithBag', 'validated'], true)
                ) {
                    $this->hasValidation = true;
                }

                // Validator::make(...)
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'make'
                ) {
                    $cls = $node->class instanceof Node\Name ? $node->class->toString() : '';
                    $resolved = $this->useMap[$cls] ?? $cls;
                    if (str_contains($resolved, 'Validator')) {
                        $this->hasValidation = true;
                    }
                }

                // ── Detect static mass-assignment: Model::create($request->all()) ─
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), [
                        'create', 'forceCreate', 'firstOrCreate', 'updateOrCreate',
                        'firstOrNew', 'createOrFirst',
                    ], true)
                ) {
                    foreach ($node->args as $arg) {
                        if (SecurityAnalyzer::isRequestAllCall($arg->value, $this->requestVars)) {
                            $this->issues[] = (new SecurityIssue(
                                type: 'MASS_ASSIGNMENT',
                                severity: 'critical',
                                message: 'Mass-assignment risk: `'.$node->name->toString().'($request->all())` passes all request input directly to the model without allow-listing.',
                                file: $this->file,
                                line: $node->getStartLine(),
                            ))->toArray();
                        }
                    }
                }

                // ── Detect instance mass-assignment: $model->fill($request->all()) ─
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['fill', 'forceFill'], true)
                ) {
                    foreach ($node->args as $arg) {
                        if (SecurityAnalyzer::isRequestAllCall($arg->value, $this->requestVars)) {
                            $this->issues[] = (new SecurityIssue(
                                type: 'MASS_ASSIGNMENT',
                                severity: 'critical',
                                message: 'Mass-assignment risk: `->'.$node->name->toString().'($request->all())` passes all request input directly to the model without allow-listing.',
                                file: $this->file,
                                line: $node->getStartLine(),
                            ))->toArray();
                        }
                    }
                }

                return null;
            }
        });
        $traverser->traverse([$node]);

        // ── Pass 2: unvalidated $request->all() ─────────────────────────────
        if (! $hasValidation) {
            $unvalidatedTraverser = new NodeTraverser;
            $unvalidatedTraverser->addVisitor(
                new class($issues, $file, $requestVars) extends NodeVisitorAbstract
                {
                    public function __construct(
                        public array &$issues,
                        private ?string $file,
                        private array $requestVars,
                    ) {}

                    public function enterNode(Node $node): ?int
                    {
                        if (SecurityAnalyzer::isRequestAllCall($node, $this->requestVars)) {
                            $this->issues[] = (new SecurityIssue(
                                type: 'UNVALIDATED_INPUT',
                                severity: 'high',
                                message: 'Unvalidated input: `$request->all()` is used without `validate()`, a FormRequest, or a Validator — raw user data may reach the application.',
                                file: $this->file,
                                line: $node->getStartLine(),
                            ))->toArray();
                        }

                        return null;
                    }
                }
            );
            $unvalidatedTraverser->traverse([$node]);
        }

        return $issues;
    }

    // ── XSS Detection ─────────────────────────────────────────────────────────

    /**
     * Entry point for all XSS checks on a single AST node (method or closure).
     *
     * Three layers:
     *   1. Taint tracking  — collect variables assigned from $request->* input.
     *   2. PHP-level scan  — echo/print of tainted data, html_entity_decode(), etc.
     *   3. Blade scan      — find view() calls, resolve the blade file, detect {!! !!}.
     *
     * @param  array<string, string>  $useMap
     * @return array[]
     */
    private function scanForXss(Node $node, array $useMap, ?string $file): array
    {
        // Step 1 – Taint: variable names whose value originates from request input
        $taintedVars = $this->collectTaintedVars($node);

        // Step 2 – PHP-level XSS patterns
        $issues = $this->scanPhpXss($node, $taintedVars, $file);

        // Step 3 – Blade template scan
        if ($this->projectRoot !== '') {
            foreach ($this->extractViewNames($node) as $viewName) {
                $viewFile = $this->resolveViewFile($viewName);
                if ($viewFile !== null) {
                    $bladeIssues = $this->scanBladeFile($viewFile, $taintedVars);
                    $issues = array_merge($issues, $bladeIssues);
                }
            }
        }

        return $issues;
    }

    // ── Taint tracking ────────────────────────────────────────────────────────

    /**
     * Collect every variable that receives its value directly from a request
     * input call (`$request->input(...)`, `request()->get(...)`, etc.).
     *
     * Returns a map of  varName => true.
     *
     * @return array<string, true>
     */
    private function collectTaintedVars(Node $node): array
    {
        $tainted = [];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($tainted) extends NodeVisitorAbstract
        {
            /** @param array<string, true> $tainted */
            public function __construct(public array &$tainted) {}

            public function enterNode(Node $node): ?int
            {
                // $var = $request->input(...) or $var = request()->get(...)
                if ($node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\Variable
                    && is_string($node->var->name)
                    && $this->isDirectRequestInput($node->expr)
                ) {
                    $this->tainted[$node->var->name] = true;
                }

                return null;
            }

            /**
             * Returns true for:
             *   $request->input(...)   $request->get(...)   request()->query(...)  etc.
             */
            private function isDirectRequestInput(Node $node): bool
            {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), [
                        'input', 'get', 'query', 'post', 'string', 'str',
                        'integer', 'float', 'boolean', 'collect', 'only',
                    ], true);
            }
        });

        $traverser->traverse([$node]);

        return $tainted;
    }

    // ── PHP-level XSS scanner ─────────────────────────────────────────────────

    /**
     * Walk the AST looking for:
     *   • echo / print of request input or tainted variables
     *   • html_entity_decode() / htmlspecialchars_decode() called on user input
     *   • response()->make($tainted) — raw HTTP body with user content
     *
     * @param  array<string, true>  $taintedVars
     * @return array[]
     */
    private function scanPhpXss(Node $node, array $taintedVars, ?string $file): array
    {
        $issues = [];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($issues, $taintedVars, $file) extends NodeVisitorAbstract
        {
            /**
             * @param  array<string, true>  $taintedVars
             */
            public function __construct(
                public array &$issues,
                private array $taintedVars,
                private ?string $file,
            ) {}

            public function enterNode(Node $node): ?int
            {
                // ── echo $userInput; ─────────────────────────────────────────
                if ($node instanceof Node\Stmt\Echo_) {
                    foreach ($node->exprs as $expr) {
                        if ($this->isTainted($expr)) {
                            $this->issues[] = (new SecurityIssue(
                                type: 'XSS_DIRECT_OUTPUT',
                                severity: 'critical',
                                message: '`echo` outputs user-controlled data directly — wrap with `htmlspecialchars($val, ENT_QUOTES)` or Laravel\'s `e()` helper before rendering to HTML.',
                                file: $this->file,
                                line: $node->getStartLine(),
                            ))->toArray();
                            break; // one issue per echo statement
                        }
                    }
                }

                // ── print $userInput; ────────────────────────────────────────
                if ($node instanceof Node\Expr\Print_ && $this->isTainted($node->expr)) {
                    $this->issues[] = (new SecurityIssue(
                        type: 'XSS_DIRECT_OUTPUT',
                        severity: 'critical',
                        message: '`print` outputs user-controlled data directly — wrap with `htmlspecialchars($val, ENT_QUOTES)` or `e()` before rendering to HTML.',
                        file: $this->file,
                        line: $node->getStartLine(),
                    ))->toArray();
                }

                // ── html_entity_decode($userInput) / htmlspecialchars_decode($userInput) ─
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array($node->name->toString(), ['html_entity_decode', 'htmlspecialchars_decode'], true)
                ) {
                    $firstArg = $node->args[0] ?? null;
                    if ($firstArg instanceof Node\Arg && $this->isTainted($firstArg->value)) {
                        $fn = $node->name->toString();
                        $this->issues[] = (new SecurityIssue(
                            type: 'XSS_HTML_DECODE',
                            severity: 'high',
                            message: "`{$fn}()` called on user-controlled input — this reverses HTML encoding and creates a direct XSS vector if the result is rendered in a browser.",
                            file: $this->file,
                            line: $node->getStartLine(),
                        ))->toArray();
                    }
                }

                // ── response()->make($userInput) / Response::make($userInput) ─
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['make', 'setContent'], true)
                ) {
                    $firstArg = $node->args[0] ?? null;
                    if ($firstArg instanceof Node\Arg && $this->isTainted($firstArg->value)) {
                        $this->issues[] = (new SecurityIssue(
                            type: 'XSS_DIRECT_OUTPUT',
                            severity: 'high',
                            message: 'Raw HTTP response body set from user-controlled input — ensure the correct `Content-Type` header is set and the content is properly encoded.',
                            file: $this->file,
                            line: $node->getStartLine(),
                        ))->toArray();
                    }
                }

                return null;
            }

            /**
             * Returns true when `$node` carries user-controlled data, either as:
             *   • a direct $request->input() / request()->get() call
             *   • a variable that was assigned from request input
             *   • a string interpolation containing a tainted variable
             */
            private function isTainted(Node $node): bool
            {
                // Direct request input call: $request->input() or request()->get()
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), [
                        'input', 'get', 'query', 'post', 'string', 'str',
                        'integer', 'float', 'boolean', 'collect', 'only',
                    ], true)
                ) {
                    return true;
                }

                // Variable that was tainted by a prior assignment
                if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                    return isset($this->taintedVars[$node->name]);
                }

                // String interpolation: "Hello $name {$title}"
                if ($node instanceof Node\Scalar\Encapsed) {
                    foreach ($node->parts as $part) {
                        if ($this->isTainted($part)) {
                            return true;
                        }
                    }
                }

                // Concatenation: $safe . $tainted
                if ($node instanceof Node\Expr\BinaryOp\Concat) {
                    return $this->isTainted($node->left) || $this->isTainted($node->right);
                }

                return false;
            }
        });

        $traverser->traverse([$node]);

        return $issues;
    }

    // ── View resolution & Blade scanning ─────────────────────────────────────

    /**
     * Find every `view('name', ...)` / `View::make('name', ...)` call in the AST
     * and return the view name strings.
     *
     * @return string[]
     */
    private function extractViewNames(Node $node): array
    {
        $names = [];

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($names) extends NodeVisitorAbstract
        {
            /** @param string[] $names */
            public function __construct(public array &$names) {}

            public function enterNode(Node $node): ?int
            {
                // view('name', ...) helper function
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && $node->name->toString() === 'view'
                    && ! empty($node->args)
                    && $node->args[0] instanceof Node\Arg
                    && $node->args[0]->value instanceof Node\Scalar\String_
                ) {
                    $this->names[] = $node->args[0]->value->value;
                }

                // View::make('name', ...) facade
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'make'
                    && $node->class instanceof Node\Name
                    && str_contains($node->class->toString(), 'View')
                    && ! empty($node->args)
                    && $node->args[0] instanceof Node\Arg
                    && $node->args[0]->value instanceof Node\Scalar\String_
                ) {
                    $this->names[] = $node->args[0]->value->value;
                }

                // response()->view('name', ...) method chain
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'view'
                    && ! empty($node->args)
                    && $node->args[0] instanceof Node\Arg
                    && $node->args[0]->value instanceof Node\Scalar\String_
                ) {
                    $this->names[] = $node->args[0]->value->value;
                }

                return null;
            }
        });

        $traverser->traverse([$node]);

        return array_unique($names);
    }

    /**
     * Resolve a dot-notation Blade view name to an absolute file path.
     * Returns null when no file exists at any expected location.
     *
     * Checks both:
     *   resources/views/{path}.blade.php   (standard)
     *   resources/views/{path}.php         (plain PHP views)
     */
    private function resolveViewFile(string $viewName): ?string
    {
        $relative = str_replace('.', '/', $viewName);
        $base = rtrim($this->projectRoot, '/').'/resources/views/'.$relative;

        foreach (['.blade.php', '.php'] as $ext) {
            if (is_file($base.$ext)) {
                return $base.$ext;
            }
        }

        return null;
    }

    /**
     * Scan a Blade template for `{!! expr !!}` (unescaped raw output).
     *
     * Every occurrence is flagged. Severity is elevated to HIGH when the
     * expression matches patterns that suggest user-supplied content
     * (description, body, html, comment, content, etc.).
     *
     * @param  array<string, true>  $taintedVars  variable names from taint tracking
     * @return array[]
     */
    private function scanBladeFile(string $file, array $taintedVars): array
    {
        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }

        $issues = [];

        // {!! expr !!} — the `s` flag lets `.` match newlines for multi-line exprs
        preg_match_all('/\{!!\s*(.*?)\s*!!\}/s', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $idx => [, $byteOffset]) {
            $expr = trim((string) $matches[1][$idx][0]);
            $lineNumber = substr_count(substr($content, 0, $byteOffset), "\n") + 1;
            $severity = $this->bladeExprSeverity($expr, $taintedVars);

            $short = mb_strlen($expr) > 60 ? mb_substr($expr, 0, 57).'...' : $expr;

            $issues[] = (new SecurityIssue(
                type: 'XSS_BLADE_UNESCAPED',
                severity: $severity,
                message: "Blade `{!! {$short} !!}` renders HTML without escaping — use `{{ }}` for auto-escaped output. Only use `{!! !!}` for explicitly trusted content (e.g. a sanitised rich-text field).",
                file: $file,
                line: $lineNumber,
            ))->toArray();
        }

        return $issues;
    }

    /**
     * Determine the severity of a Blade `{!! expr !!}` expression.
     *
     * HIGH  — expression references a pattern that strongly suggests raw user content.
     * MEDIUM — general unescaped output (could be intentional trusted HTML).
     *
     * @param  array<string, true>  $taintedVars
     */
    private function bladeExprSeverity(string $expr, array $taintedVars): string
    {
        $lower = strtolower($expr);

        // Check if a known-tainted variable name appears in the expression
        foreach (array_keys($taintedVars) as $varName) {
            if (str_contains($lower, strtolower($varName))) {
                return 'high';
            }
        }

        // Check against known high-risk content patterns
        foreach (self::HIGH_RISK_VAR_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'high';
            }
        }

        return 'medium';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function deduplicateIssues(array $issues): array
    {
        $seen = [];
        $clean = [];
        foreach ($issues as $issue) {
            $key = $issue['type'].':'.$issue['message'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $clean[] = $issue;
            }
        }

        return $clean;
    }

    private function computeRiskLevel(array $issues): string
    {
        $highest = 'none';
        $order = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        foreach ($issues as $issue) {
            $sev = $issue['severity'] ?? 'low';
            if (($order[$sev] ?? 0) > ($order[$highest] ?? 0)) {
                $highest = $sev;
            }
        }

        return $highest;
    }
}
