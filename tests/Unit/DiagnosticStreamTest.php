<?php

use LaraMint\LaravelBrain\Commands\DiagnosticStream;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

it('sends a diagnostic to stderr when the command has a real console', function () {
    // The point of the class: `brain:export-context` writes an artifact to stdout, so a warning
    // written there lands inside the document — first line of the export, ahead of its heading.
    $console = new ConsoleOutput;

    expect(DiagnosticStream::for($console))->toBe($console->getErrorOutput())
        ->not->toBe($console);
});

it('falls back to the given output when there is no separate error stream', function () {
    // What Laravel hands a command under test. `getErrorOutput()` does not exist on one, so an
    // unguarded call is a fatal error rather than a graceful miss.
    $buffered = new BufferedOutput;

    expect(DiagnosticStream::for($buffered))->toBe($buffered);
});
