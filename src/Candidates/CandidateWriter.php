<?php

declare(strict_types=1);

namespace PinCrack\Candidates;

use RuntimeException;

/**
 * Streams candidates to disk and decides which seek strategy the resulting
 * file supports. See CandidateSet for why that matters.
 */
final class CandidateWriter
{
    private const FLUSH_AT = 1 << 20;

    /** @var resource */
    private $handle;

    /** @var resource|null */
    private $index = null;

    private string $buffer = '';
    private string $indexBuffer = '';
    private string $indexPath;

    private int $count = 0;
    private int $offset = 0;
    private int $fixedLength = 0;
    private bool $indexed = false;

    public function __construct(private readonly string $path)
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("cannot write candidates to $path");
        }
        $this->handle = $handle;
        $this->indexPath = $path . '.idx';
    }

    public function count(): int
    {
        return $this->count;
    }

    public function add(string $candidate): void
    {
        $length = strlen($candidate);

        if ($this->count === 0) {
            $this->fixedLength = $length;
        } elseif (!$this->indexed && $length !== $this->fixedLength) {
            $this->startIndex();
        }

        if ($this->indexed) {
            $this->indexBuffer .= pack('P', $this->offset);
            if (strlen($this->indexBuffer) >= self::FLUSH_AT) {
                $this->flushIndex();
            }
        }

        $this->buffer .= $candidate . "\n";
        $this->offset += $length + 1;
        $this->count++;

        if (strlen($this->buffer) >= self::FLUSH_AT) {
            $this->flush();
        }
    }

    public function finish(string $label): CandidateSet
    {
        $this->flush();
        fclose($this->handle);

        if ($this->indexed) {
            $this->flushIndex();
            if (is_resource($this->index)) {
                fclose($this->index);
            }
        }

        return new CandidateSet(
            $this->path,
            $this->count,
            $this->indexed ? 0 : $this->fixedLength + 1,
            $this->indexed ? $this->indexPath : null,
            $label
        );
    }

    /**
     * Switch to index mode once a candidate of a different length shows up.
     *
     * Everything written so far had one uniform length, so its offsets are
     * arithmetic and can be backfilled without re-reading the data file.
     */
    private function startIndex(): void
    {
        $index = @fopen($this->indexPath, 'wb');
        if ($index === false) {
            throw new RuntimeException("cannot write candidate index to $this->indexPath");
        }
        $this->index = $index;
        $this->indexed = true;

        $record = $this->fixedLength + 1;
        $buf = '';
        for ($i = 0; $i < $this->count; $i++) {
            $buf .= pack('P', $i * $record);
            if (strlen($buf) >= self::FLUSH_AT) {
                fwrite($index, $buf);
                $buf = '';
            }
        }
        if ($buf !== '') {
            fwrite($index, $buf);
        }
    }

    private function flush(): void
    {
        if ($this->buffer !== '') {
            fwrite($this->handle, $this->buffer);
            $this->buffer = '';
        }
    }

    private function flushIndex(): void
    {
        if ($this->indexBuffer !== '' && is_resource($this->index)) {
            fwrite($this->index, $this->indexBuffer);
            $this->indexBuffer = '';
        }
    }
}
