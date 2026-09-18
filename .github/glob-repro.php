<?php
// Standalone on purpose: SourceDirectories has no imports and no dependencies, so this needs no
// composer, no vendor/ and no Laravel — just the one file and the one call from the issue.
require __DIR__.'/../src/Analysis/SourceDirectories.php';

$root = sys_get_temp_dir().'/globrepro';
@mkdir($root.'/app', 0o777, true);   // an `app/` exists …
@rmdir($root.'/src');                 // … and `src/` does not — which is the shipped default

printf("  PHP %-7s %-5s  GLOB_BRACE=%-7s GLOB_ONLYDIR=%-7s ",
    PHP_VERSION,
    glob('/lib/ld-musl-*') ? 'musl' : 'glibc',
    defined('GLOB_BRACE') ? 'yes' : 'ABSENT',
    defined('GLOB_ONLYDIR') ? 'yes' : 'ABSENT');

try {
    $r = LaraMint\LaravelBrain\Analysis\SourceDirectories::resolve($root, ['app', 'src']);
    printf("-> resolve() OK %s\n", json_encode($r));
} catch (\Throwable $e) {
    printf("-> %s: %s (%s:%d)\n", get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
}
