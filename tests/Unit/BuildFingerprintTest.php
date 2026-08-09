<?php

use LaraMint\LaravelBrain\Analysis\Incremental\BuildFingerprint;

function tmpProject(): string
{
    $dir = sys_get_temp_dir().'/brain-fp-'.bin2hex(random_bytes(6));
    mkdir($dir.'/app', 0o755, true);

    return $dir;
}

it('captures content hashes for php files under the roots', function () {
    $root = tmpProject();
    file_put_contents($root.'/app/A.php', "<?php\nclass A {}\n");
    file_put_contents($root.'/app/notes.txt', 'ignored');

    $fp = BuildFingerprint::capture($root);

    expect($fp->files)->toHaveKey($root.'/app/A.php');
    expect($fp->files)->not->toHaveKey($root.'/app/notes.txt'); // non-php ignored
});

it('detects added, modified and deleted files by content (not mtime)', function () {
    $root = tmpProject();
    file_put_contents($root.'/app/A.php', "<?php\nclass A {}\n");
    file_put_contents($root.'/app/B.php', "<?php\nclass B {}\n");
    $before = BuildFingerprint::capture($root);

    // Modify A's CONTENT while pinning mtime — mtime-keyed detection would miss this.
    $mtime = filemtime($root.'/app/A.php');
    file_put_contents($root.'/app/A.php', "<?php\nclass A { public function x() {} }\n");
    touch($root.'/app/A.php', $mtime);
    // Add C, delete B.
    file_put_contents($root.'/app/C.php', "<?php\nclass C {}\n");
    unlink($root.'/app/B.php');

    $after = BuildFingerprint::capture($root);
    $diff = $after->diff($before);

    expect($diff['modified'])->toContain($root.'/app/A.php');
    expect($diff['added'])->toContain($root.'/app/C.php');
    expect($diff['deleted'])->toContain($root.'/app/B.php');
    expect($after->equals($before))->toBeFalse();
});

it('reports no changes for an identical tree', function () {
    $root = tmpProject();
    file_put_contents($root.'/app/A.php', "<?php\nclass A {}\n");

    $a = BuildFingerprint::capture($root);
    $b = BuildFingerprint::capture($root);

    expect($a->equals($b))->toBeTrue();
    expect($a->diff($b))->toBe(['added' => [], 'modified' => [], 'deleted' => []]);
});
