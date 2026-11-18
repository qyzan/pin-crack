<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Candidates\CandidateSet;
use RuntimeException;

/**
 * Sequential reader over a CandidateSet that can start at an arbitrary
 * record number.
 *
 * A worker seeks once and then reads forward, so the index file - when there
 * is one - is touched exactly once per worker.
 */
final class CandidateReader
{
    /** @var resource */
    private $handle;

    public function __construct(string $path, private readonly int $recordLength, private readonly ?string $indexPath)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("cannot read candidates from $path");
        }
        $this->handle = $handle;
    }

    public static function fromSet(CandidateSet $set): self
    {
        return new self($set->path, $set->recordLength, $set->indexPath);
    }

    public function seek(int $record): void
    {
        if ($record <= 0) {
            rewind($this->handle);

            return;
        }

        if ($this->recordLength > 0) {
            fseek($this->handle, $record * $this->recordLength);

            return;
        }

        if ($this->indexPath === null) {
            throw new RuntimeException('variable-length candidate file has no index');
        }

        $index = @fopen($this->indexPath, 'rb');
        if ($index === false) {
            throw new RuntimeException("cannot read candidate index from $this->indexPath");
        }
        fseek($index, $record * 8);
        $packed = fread($index, 8);
        fclose($index);

        if ($packed === false || strlen($packed) !== 8) {
            throw new RuntimeException('candidate index is shorter than the requested record');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('P', $packed);
        fseek($this->handle, $unpacked[1]);
    }

    public function next(): ?string
    {
        $line = fgets($this->handle);
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
