<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

class MiddlewareRegistry
{
    public function __construct(
        public array $global,
        public array $groups,
        public array $aliases,
    ) {}

    public function resolveAlias(string $alias): string
    {
        return $this->aliases[$alias] ?? $alias;
    }

    public function resolveGroup(string $group): array
    {
        return $this->groups[$group] ?? [];
    }

    /**
     * What one middleware name on a route actually runs: the classes, with their parameters.
     *
     * `->middleware('api')` is one name standing for a list, and reading it as a single guard is
     * how a route behind `api` — a group an application may well have put `auth` in — is taken for
     * an unguarded one. Expanding it is what lets a caller see the guard that is really there.
     *
     * Follows Laravel's own resolution order, from `Illuminate\Routing\MiddlewareNameResolver` —
     * named in prose rather than linked, because the style pass turns a `{@see}` into an import and
     * this package requires `illuminate/console` and `illuminate/support`, not `illuminate/routing`:
     *
     * - A group wins over an alias of the same name, and is matched on the whole name, so
     *   `web:something` is not the `web` group.
     * - A group's members are expanded in turn, so a group listing another group yields the inner
     *   group's members — which Laravel supports and applications use.
     * - Anything else is an alias lookup on the part before the first colon, with the parameters
     *   put back afterwards: `throttle:60,1` becomes `Illuminate\…\ThrottleRequests:60,1`.
     *
     * Where this parts company with the framework is the cycle. Laravel throws on a group that
     * lists itself directly and recurses forever on a longer loop; an application with either is
     * broken, but static analysis reads whatever is on disk, including source no one has run. A
     * name already expanded on this path is therefore dropped rather than followed, so a loop ends
     * with the members it did find instead of taking the scan down.
     *
     * @return list<string> resolved middleware, in the order they run; empty only for an empty group
     */
    public function expand(string $name): array
    {
        return $this->expandName($name, []);
    }

    /**
     * @param  array<string, true>  $seen  group names already expanded on this path
     * @return list<string>
     */
    private function expandName(string $name, array $seen): array
    {
        if (! isset($this->groups[$name])) {
            [$alias, $parameters] = array_pad(explode(':', $name, 2), 2, null);

            return [$this->resolveAlias($alias).($parameters !== null ? ':'.$parameters : '')];
        }

        if (isset($seen[$name])) {
            return [];
        }

        $seen[$name] = true;

        $resolved = [];
        foreach ($this->resolveGroup($name) as $member) {
            if (! is_string($member)) {
                continue;
            }

            foreach ($this->expandName($member, $seen) as $one) {
                $resolved[] = $one;
            }
        }

        return $resolved;
    }
}
