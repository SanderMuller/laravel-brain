<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * Discovers the view-composition tree: which Blade view renders which other
 * view, via `@include` / `@includeIf` / `@extends` / `@component` / `@each`
 * targets and modern `<x-...>` component tags.
 *
 * Brain anchors a view to its rendering controller (`action-to-view`) but does
 * not descend into the views that view itself renders — nested components and
 * includes are captured only as inert node metadata, with no edge. So a change
 * to a nested partial reaches no entry point and reads as "no impact". This
 * analyzer produces the parent → child view map that GraphBuilder turns into
 * `view-to-view` edges.
 *
 * Only references that resolve to a real Blade file under the views directory
 * are linked, so a class-backed `<x-...>` with no view template, a namespaced
 * (`pkg::view`) target, or a dynamic (`$var`) name adds no edge. The parsing is
 * pure over the source ({@see referencedViewNames}) so it is testable without a
 * filesystem.
 */
class BladeViewAnalyzer
{
    private const BLADE_EXT = '.blade.php';

    /** Directives whose first string argument is a view name. */
    private const INCLUDE_PATTERN = '/@(?:include|includeIf|extends|component|each)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

    /** `@includeWhen`/`@includeUnless` take the condition first, the view as the second argument. */
    private const CONDITIONAL_INCLUDE_PATTERN = '/@include(?:When|Unless)\s*\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/';

    /** `@includeFirst(['a', 'b'])` — every listed view is a candidate. */
    private const INCLUDE_FIRST_PATTERN = '/@includeFirst\s*\(\s*\[([^\]]*)\]/';

    /** Component tags `<x-foo.bar ...>` / `<x-foo.bar/>` — the dots are path segments under `components/`. */
    private const COMPONENT_PATTERN = '/<\s*x-([A-Za-z0-9._:-]+)/';

    /** Tags that are not anonymous view components. */
    private const NON_VIEW_TAGS = ['slot', 'dynamic-component'];

    /**
     * Where Blade templates live in a default Laravel skeleton.
     *
     * @var string[]
     */
    public const DEFAULT_PATHS = ['resources/views'];

    /** @var string[] view roots, relative to the project root */
    private array $viewsPaths;

    /**
     * The view roots must stay in lockstep with how the graph builder resolves a view
     * to its file — {@see GraphBuilder::setViewPaths()}
     * takes the same list.
     *
     * @param  string[]  $viewsPaths  view roots, relative to the project root;
     *                                glob patterns are expanded
     */
    public function __construct(array $viewsPaths = self::DEFAULT_PATHS)
    {
        $this->viewsPaths = $viewsPaths !== [] ? $viewsPaths : self::DEFAULT_PATHS;
    }

    /**
     * @return array<string, list<string>> parent view name => child view names (each an existing Blade file)
     */
    public function analyze(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $viewsRoots = array_map(
            static fn (string $directory): string => $root.'/'.$directory,
            SourceDirectories::resolve($root, $this->viewsPaths),
        );

        if ($viewsRoots === []) {
            return [];
        }

        $map = [];

        foreach ($viewsRoots as $viewsRoot) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($viewsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if (! $fileInfo->isFile() || ! str_ends_with($fileInfo->getFilename(), self::BLADE_EXT)) {
                    continue;
                }
                $parent = $this->viewNameFromPath($fileInfo->getPathname(), $viewsRoot);
                // Vendor views are addressed as `pkg::view` (namespaced) and never match a
                // first-party seed, so scanning them only yields dead map entries.
                if ($parent === null || str_starts_with($parent, 'vendor.')) {
                    continue;
                }

                $children = [];
                foreach ($this->referencedViewNames((string) file_get_contents($fileInfo->getPathname())) as $candidate) {
                    // A template may include a view that lives under a different root — one
                    // package rendering another's partial — so every root is a candidate.
                    if ($this->viewFileExists($viewsRoots, $candidate) && $candidate !== $parent && ! in_array($candidate, $children, true)) {
                        $children[] = $candidate;
                    }
                }
                $children = $this->preferNonIndexComponent($children);
                if ($children !== []) {
                    $map[$parent] = array_values(array_unique(array_merge($map[$parent] ?? [], $children)));
                }
            }
        }

        return $map;
    }

    /**
     * Candidate view names referenced by one Blade source — both `@include`-family
     * targets and the view a `<x-...>` component resolves to (single-file and
     * folder-`index` forms are both offered; the caller keeps whichever exists).
     *
     * @return list<string>
     */
    public function referencedViewNames(string $content): array
    {
        $names = [];

        foreach ([self::INCLUDE_PATTERN, self::CONDITIONAL_INCLUDE_PATTERN] as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $raw) {
                    // A namespaced (`pkg::view`) or dynamic (`$var`) name can't be pinned to a file.
                    if (! str_contains($raw, '::') && ! str_contains($raw, '$')) {
                        $names[] = $raw;
                    }
                }
            }
        }

        if (preg_match_all(self::INCLUDE_FIRST_PATTERN, $content, $matches)) {
            foreach ($matches[1] as $list) {
                if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $items)) {
                    foreach ($items[1] as $raw) {
                        if (! str_contains($raw, '::') && ! str_contains($raw, '$')) {
                            $names[] = $raw;
                        }
                    }
                }
            }
        }

        if (preg_match_all(self::COMPONENT_PATTERN, $content, $matches)) {
            foreach ($matches[1] as $tag) {
                if (in_array($tag, self::NON_VIEW_TAGS, true) || str_contains($tag, '::')) {
                    continue;
                }
                $names[] = 'components.'.$tag;
                $names[] = 'components.'.$tag.'.index';
            }
        }

        return array_values(array_unique($names));
    }

    private function viewNameFromPath(string $absolutePath, string $viewsRoot): ?string
    {
        $prefix = rtrim($viewsRoot, '/').'/';
        if (! str_starts_with($absolutePath, $prefix)) {
            return null;
        }
        $relative = substr($absolutePath, strlen($prefix));
        if (! str_ends_with($relative, self::BLADE_EXT)) {
            return null;
        }
        $relative = substr($relative, 0, -strlen(self::BLADE_EXT));

        return str_replace('/', '.', $relative);
    }

    /**
     * @param  string[]  $viewsRoots  absolute view roots
     */
    private function viewFileExists(array $viewsRoots, string $viewName): bool
    {
        $rel = str_replace('.', '/', $viewName).self::BLADE_EXT;

        foreach ($viewsRoots as $viewsRoot) {
            $root = rtrim($viewsRoot, '/');

            // Mirror the graph builder's resolution: the views root, then its vendor/ overrides.
            if (is_file($root.'/'.$rel) || is_file($root.'/vendor/'.$rel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `<x-foo>` tag offers both `components.foo` and `components.foo.index`;
     * when both templates exist Laravel renders the non-index file, so drop the
     * redundant `.index` alternative to avoid a spurious edge.
     *
     * @param  list<string>  $children
     * @return list<string>
     */
    private function preferNonIndexComponent(array $children): array
    {
        return array_values(array_filter($children, static function (string $child) use ($children): bool {
            if (str_starts_with($child, 'components.') && str_ends_with($child, '.index')) {
                return ! in_array(substr($child, 0, -strlen('.index')), $children, true);
            }

            return true;
        }));
    }
}
