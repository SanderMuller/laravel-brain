<?php

use LaraMint\LaravelBrain\Commands\MemoryExhaustionNotice;

it('explains a memory-exhaustion fatal and names the setting that fixes it', function () {
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 1073741824 bytes exhausted (tried to allocate 20480 bytes)',
        'file' => '/app/vendor/nikic/php-parser/lib/PhpParser/Lexer.php',
        'line' => 120,
    ], '1024M');

    expect($notice)->toContain('--memory-limit=1024M')
        ->toContain('--memory-limit=-1')
        ->toContain('config/laravel-brain.php');
});

it('suggests double what did not fit, so the advice is a command rather than a guess', function () {
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 1073741824 bytes exhausted',
    ], '1024M');

    expect($notice)->toContain('--memory-limit=2048M');
});

it('keeps the unit it was given when doubling', function () {
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 2147483648 bytes exhausted',
    ], '2G');

    expect($notice)->toContain('--memory-limit=4G');
});

it('falls back to a concrete suggestion when the limit is not a size', function () {
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 1073741824 bytes exhausted',
    ], '-1');

    expect($notice)->toContain('--memory-limit=2048M');
});

it('says nothing when the process ended normally', function () {
    expect(MemoryExhaustionNotice::forLastError(null, '1024M'))->toBeNull();
});

it('says nothing for a fatal it cannot explain', function () {
    // Claiming a memory problem for an unrelated crash would send the reader the wrong way.
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_ERROR,
        'message' => 'Uncaught TypeError: bad argument',
    ], '1024M');

    expect($notice)->toBeNull();
});

it('says nothing for a warning that merely mentions memory', function () {
    $notice = MemoryExhaustionNotice::forLastError([
        'type' => E_WARNING,
        'message' => 'Allowed memory size of 1073741824 bytes exhausted',
    ], '1024M');

    expect($notice)->toBeNull();
});
