<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Commands;

/**
 * Turns PHP's memory-exhaustion fatal into something a person can act on.
 *
 * When the scan runs past `memory_limit`, PHP kills the process with exit code 255. On a
 * CLI with `display_errors` off nothing reaches the terminal at all: the progress line
 * stops mid-step and there is no message, no exit reason, and nothing in the log. The
 * scan looks like it stopped for no reason, and the one thing that would fix it — a
 * larger limit — is the one thing the output does not mention.
 *
 * The fatal is still recoverable at shutdown: {@see error_get_last()} holds it, and
 * writing a line needs only a little memory back, which is what the caller's reserve
 * buffer is for.
 */
final class MemoryExhaustionNotice
{
    /**
     * Bytes held back so there is room to build and print the message after the heap is full.
     */
    public const RESERVE_BYTES = 262144;

    /**
     * The message for a memory-exhaustion fatal, or null for anything else — a normal exit,
     * a parse error, an uncaught exception. Only the one condition this can explain is claimed.
     *
     * @param  array{type?: int, message?: string, file?: string, line?: int}|null  $lastError
     */
    public static function forLastError(?array $lastError, string $limitInEffect): ?string
    {
        if ($lastError === null || ($lastError['type'] ?? null) !== E_ERROR) {
            return null;
        }

        $message = (string) ($lastError['message'] ?? '');
        if (! str_contains($message, 'Allowed memory size of')) {
            return null;
        }

        return sprintf(
            'The scan ran out of memory at --memory-limit=%s. Raise it (%s), lift it entirely '
            .'(--memory-limit=-1), or set `memory_limit` in config/laravel-brain.php so every '
            .'scan of this project gets the larger value.',
            $limitInEffect,
            self::suggestion($limitInEffect),
        );
    }

    /**
     * A concrete next value to try: double what did not fit, so the advice is a command to
     * run rather than an invitation to guess.
     */
    private static function suggestion(string $limitInEffect): string
    {
        if (! preg_match('/^(\d+)([KMGT]?)$/i', trim($limitInEffect), $matches)) {
            return '--memory-limit=2048M';
        }

        return '--memory-limit='.((int) $matches[1] * 2).strtoupper($matches[2] ?: 'M');
    }
}
