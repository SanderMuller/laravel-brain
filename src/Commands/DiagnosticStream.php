<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Commands;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The stream a diagnostic belongs on when the command's real output is an artifact.
 *
 * `brain:export-context` writes a document to stdout that is meant to be redirected into a file
 * or piped into something. Anything else written there lands INSIDE that document: a warning
 * became the first line of the export, ahead of its own heading, and with `--format=json` it made
 * the result unparseable. Diagnostics go to stderr so that stdout carries the artifact alone.
 *
 * The `instanceof` check is the load-bearing part. `OutputStyle::getOutput()` returns whatever the
 * command was given, and only a `ConsoleOutputInterface` has a separate error stream — a buffered
 * output, which is what Laravel hands a command under test, does not, and calling
 * `getErrorOutput()` on one is a fatal error rather than a graceful miss.
 *
 * `OutputStyle::getErrorOutput()` exists but is `protected`, which is why this asks the underlying
 * output rather than the style wrapping it.
 */
final class DiagnosticStream
{
    public static function for(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }
}
