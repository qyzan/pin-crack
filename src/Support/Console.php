<?php

declare(strict_types=1);

namespace PinCrack\Support;

/**
 * Output helpers.
 *
 * Results go to STDOUT and progress rendering goes to STDERR, so that
 * `pin-crack ... > results.txt` keeps a clean result file while the progress
 * display still reaches the terminal.
 */
final class Console
{
    private bool $quiet;
    private bool $tty;

    public function __construct(bool $quiet = false)
    {
        $this->quiet = $quiet;
        $this->tty = function_exists('stream_isatty') && @stream_isatty(STDERR);
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    public function isTty(): bool
    {
        return $this->tty;
    }

    /** Result output - always written, even in quiet mode. */
    public function out(string $s): void
    {
        fwrite(STDOUT, $s);
    }

    /** Informational line on STDOUT, suppressed in quiet mode. */
    public function info(string $s): void
    {
        if (!$this->quiet) {
            fwrite(STDOUT, $s);
        }
    }

    /** Progress / diagnostics on STDERR, suppressed in quiet mode. */
    public function status(string $s): void
    {
        if (!$this->quiet) {
            fwrite(STDERR, $s);
        }
    }

    public function err(string $s): void
    {
        fwrite(STDERR, $s);
    }

    /**
     * Redraw a block of lines in place.
     *
     * Every line is padded to a fixed width before being written. Without the
     * padding only the first line gets cleared, so the tail of a previous,
     * longer render stays glued to the end of lines 2..n - which shows up as
     * impossible clock values such as "ETA 13 Nov 23:07:126", where "126" is
     * leftover text rather than part of the time.
     *
     * @param list<string> $lines
     */
    public function redraw(array $lines, int $width = 104): void
    {
        if ($this->quiet || $lines === []) {
            return;
        }

        if (!$this->tty) {
            fwrite(STDERR, implode('  ', array_map('trim', $lines)) . "\n");

            return;
        }

        $buf = '';
        foreach ($lines as $line) {
            $buf .= str_pad($line, $width) . "\n";
        }
        // Move the cursor back up to the first line of the block.
        $buf .= sprintf("\033[%dA\r", count($lines));
        fwrite(STDERR, $buf);
    }

    public function clearBlock(int $lines, int $width = 110): void
    {
        if ($this->quiet || !$this->tty) {
            return;
        }
        fwrite(STDERR, str_repeat("\n", $lines) . str_repeat(' ', $width) . "\r");
    }

    public function clearLine(int $width = 110): void
    {
        if ($this->quiet || !$this->tty) {
            return;
        }
        fwrite(STDERR, "\r" . str_repeat(' ', $width) . "\r");
    }
}
