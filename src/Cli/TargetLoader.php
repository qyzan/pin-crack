<?php

declare(strict_types=1);

namespace PinCrack\Cli;

/**
 * Collects target hashes from --hash, --hash-file or stdin.
 *
 * A hash file holds one hash per line, optionally as "label:hash" so results
 * can be matched back to accounts. Lines starting with '#' are comments, which
 * makes it convenient to keep the provenance of each hash next to it.
 */
final class TargetLoader
{
    /** @return list<Target> */
    public static function load(Options $options, bool $allowStdin = true): array
    {
        $targets = [];

        if ($options->hash !== null) {
            $targets[] = new Target('hash-1', trim($options->hash));
        }

        if ($options->hashFile !== null) {
            $lines = file($options->hashFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $n => $line) {
                $target = self::parseLine($line, $n + 1);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
        }

        if ($targets === [] && $allowStdin && !self::stdinIsTty()) {
            $raw = (string) stream_get_contents(STDIN);
            foreach (preg_split('/\R/', $raw) ?: [] as $n => $line) {
                $target = self::parseLine($line, $n + 1, 'stdin');
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
        }

        return $targets;
    }

    public static function parseLine(string $line, int $lineNumber, string $prefix = 'line'): ?Target
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // A crypt-format hash contains '$' but never ':', so a label is safe
        // to split off on the first colon that precedes one.
        if (preg_match('/^(.*?):(\$(?:2[abxy]?|argon2(?:i|d|id))\$.*)$/', $line, $m) === 1) {
            $label = trim($m[1]);

            return new Target($label !== '' ? $label : "$prefix-$lineNumber", trim($m[2]));
        }

        if (str_contains($line, ':')) {
            [$label, $rest] = explode(':', $line, 2);
            $label = trim($label);

            return new Target($label !== '' ? $label : "$prefix-$lineNumber", trim($rest));
        }

        return new Target("$prefix-$lineNumber", $line);
    }

    private static function stdinIsTty(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }
}
