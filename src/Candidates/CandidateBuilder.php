<?php

declare(strict_types=1);

namespace PinCrack\Candidates;

use InvalidArgumentException;
use PinCrack\Support\Format;
use RuntimeException;

/**
 * Turns a candidate-source choice into a CandidateSet on disk.
 */
final class CandidateBuilder
{
    public const MODE_WORDLIST = 'wordlist';
    public const MODE_FULL = 'full';
    public const MODE_MASK = 'mask';
    public const MODE_FILE = 'file';

    /**
     * @param list<string> $include candidates forced to the front of the list
     */
    public function __construct(
        private readonly string $mode = self::MODE_WORDLIST,
        private readonly int $digits = 6,
        private readonly ?Mask $mask = null,
        private readonly ?string $wordlistPath = null,
        private readonly bool $withDates = false,
        private readonly array $include = [],
        private readonly int $maxCandidates = 50_000_000
    ) {
        if ($mode === self::MODE_MASK && $mask === null) {
            throw new InvalidArgumentException('mask mode needs a mask');
        }
        if ($mode === self::MODE_FILE && ($wordlistPath === null || !is_file($wordlistPath))) {
            throw new InvalidArgumentException('wordlist file not found: ' . (string) $wordlistPath);
        }
    }

    /** Candidate count when it is known up front, null when it is not. */
    public function knownCount(): ?int
    {
        return match ($this->mode) {
            self::MODE_FULL => (int) (10 ** $this->digits),
            self::MODE_MASK => $this->mask?->count(),
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this->mode) {
            self::MODE_FULL => sprintf('full %d-digit keyspace', $this->digits),
            self::MODE_MASK => sprintf('mask %s', (string) $this->mask?->mask()),
            self::MODE_FILE => sprintf('wordlist file %s', (string) $this->wordlistPath),
            default => 'built-in common wordlist' . ($this->withDates ? ' + date patterns' : ''),
        };
    }

    public function build(string $path): CandidateSet
    {
        $known = $this->knownCount();
        if ($known !== null && $this->maxCandidates > 0 && $known > $this->maxCandidates) {
            throw new RuntimeException(sprintf(
                'that source expands to %s candidates, above the --max-candidates limit of %s. '
                . 'Raise the limit if you have the disk space and the time.',
                Format::number($known),
                Format::number($this->maxCandidates)
            ));
        }

        $writer = new CandidateWriter($path);

        // Front-loaded candidates are written first and excluded from the
        // generated list, so the position reported for a hit stays meaningful.
        $front = [];
        foreach ($this->include as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($front[$candidate])) {
                continue;
            }
            $front[$candidate] = true;
            $writer->add($candidate);
        }

        match ($this->mode) {
            self::MODE_FULL => $this->writeFull($writer, $front),
            self::MODE_MASK => $this->writeMask($writer, $front),
            self::MODE_FILE => $this->writeFile($writer, $front),
            default => $this->writeBuiltin($writer, $front),
        };

        $set = $writer->finish($this->label());

        if ($set->count === 0) {
            $set->cleanup();
            throw new RuntimeException(
                'the candidate list came out empty - check --digits, --mask or your wordlist'
            );
        }

        return $set;
    }

    /** @param array<string, true> $front */
    private function writeFull(CandidateWriter $writer, array $front): void
    {
        $total = (int) (10 ** $this->digits);
        $format = '%0' . $this->digits . 'd';
        for ($i = 0; $i < $total; $i++) {
            $candidate = sprintf($format, $i);
            if (isset($front[$candidate])) {
                continue;
            }
            $writer->add($candidate);
        }
    }

    /** @param array<string, true> $front */
    private function writeMask(CandidateWriter $writer, array $front): void
    {
        $mask = $this->mask;
        if ($mask === null) {
            return;
        }
        foreach ($mask->each() as $candidate) {
            if (isset($front[$candidate])) {
                continue;
            }
            $writer->add($candidate);
        }
    }

    /**
     * A user-supplied wordlist REPLACES the built-in list rather than adding
     * to it, so that `--wordlist` sweeps are reproducible.
     *
     * Lines are read streaming - the file may be larger than memory - and are
     * deduplicated only against the front-loaded candidates. Deduplicating a
     * 14-million-line wordlist in memory would cost more than re-testing the
     * handful of duplicates it contains.
     *
     * @param array<string, true> $front
     */
    private function writeFile(CandidateWriter $writer, array $front): void
    {
        $handle = @fopen((string) $this->wordlistPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('cannot read wordlist: ' . (string) $this->wordlistPath);
        }

        while (($line = fgets($handle)) !== false) {
            $candidate = rtrim($line, "\r\n");
            if ($candidate === '' || isset($front[$candidate])) {
                continue;
            }
            $writer->add($candidate);
        }

        fclose($handle);
    }

    /** @param array<string, true> $front */
    private function writeBuiltin(CandidateWriter $writer, array $front): void
    {
        foreach (Wordlist::build($this->digits, $this->withDates) as $candidate) {
            if (isset($front[$candidate])) {
                continue;
            }
            $writer->add($candidate);
        }
    }
}
