<?php

declare(strict_types=1);

namespace PinCrack\Candidates;

/**
 * A materialised candidate list on disk, plus everything a worker needs to
 * seek straight to record N.
 *
 * Candidates are never held in memory as an array: a PHP array of one million
 * strings blows past the default 128 MB memory_limit, and the whole point of
 * the file is that several processes can read disjoint slices of it.
 *
 * Two seek strategies:
 *   - fixed records   (recordLength > 0): offset = n * recordLength
 *   - indexed records (recordLength = 0): offset read from a side-car file of
 *     64-bit little-endian offsets, one per record
 *
 * Fixed records cost nothing extra, so the index is only built when the list
 * actually contains mixed-length candidates - a user-supplied wordlist, say.
 */
final class CandidateSet
{
    public function __construct(
        public readonly string $path,
        public readonly int $count,
        public readonly int $recordLength,
        public readonly ?string $indexPath,
        public readonly string $label,
        private bool $temporary = true
    ) {
    }

    public function isIndexed(): bool
    {
        return $this->recordLength === 0;
    }

    public function sizeOnDisk(): int
    {
        $size = (int) (@filesize($this->path) ?: 0);
        if ($this->indexPath !== null) {
            $size += (int) (@filesize($this->indexPath) ?: 0);
        }

        return $size;
    }

    public function keepFiles(): void
    {
        $this->temporary = false;
    }

    public function cleanup(): void
    {
        if (!$this->temporary) {
            return;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        if ($this->indexPath !== null && is_file($this->indexPath)) {
            @unlink($this->indexPath);
        }
    }
}
