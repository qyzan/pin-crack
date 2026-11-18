<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Support\Json;
use RuntimeException;

/**
 * One worker's assignment, handed over as a JSON file.
 *
 * The job goes in a file rather than on the command line so the target hash
 * does not show up in the machine's process list - on a shared or monitored
 * box, `ps` output is not a good place for client material.
 *
 * Three control files live alongside it, all polled by the worker at its
 * reporting cadence:
 *
 *   stopPath       exists  -> finish now, progress already reported
 *   pausePath      exists  -> stop hashing and idle until it disappears
 *   heartbeatPath  stale   -> the parent is gone, exit rather than orphan
 */
final class WorkerJob
{
    /** Seconds without a parent heartbeat before a worker assumes it is orphaned. */
    public const DEFAULT_HEARTBEAT_TIMEOUT = 30;

    /**
     * @param array<string, mixed> $verifierOptions
     */
    public function __construct(
        public readonly string $candidatePath,
        public readonly int $recordLength,
        public readonly ?string $indexPath,
        public readonly int $from,
        public readonly int $to,
        public readonly string $statusPath,
        public readonly string $stopPath,
        public readonly string $hash,
        public readonly array $verifierOptions = [],
        public readonly string $pausePath = '',
        public readonly string $heartbeatPath = '',
        public readonly int $heartbeatTimeout = self::DEFAULT_HEARTBEAT_TIMEOUT
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidate_path' => $this->candidatePath,
            'record_length' => $this->recordLength,
            'index_path' => $this->indexPath,
            'from' => $this->from,
            'to' => $this->to,
            'status_path' => $this->statusPath,
            'stop_path' => $this->stopPath,
            'pause_path' => $this->pausePath,
            'heartbeat_path' => $this->heartbeatPath,
            'heartbeat_timeout' => $this->heartbeatTimeout,
            'hash' => $this->hash,
            'verifier' => $this->verifierOptions,
        ];
    }

    public function writeTo(string $path): void
    {
        if (!Json::writeAtomic($path, $this->toArray())) {
            throw new RuntimeException("cannot write worker job to $path");
        }
    }

    public static function readFrom(string $path): self
    {
        $data = Json::readSafe($path);
        if ($data === null) {
            throw new RuntimeException("cannot read worker job from $path");
        }

        return new self(
            (string) ($data['candidate_path'] ?? ''),
            (int) ($data['record_length'] ?? 0),
            isset($data['index_path']) && is_string($data['index_path']) ? $data['index_path'] : null,
            (int) ($data['from'] ?? 0),
            (int) ($data['to'] ?? 0),
            (string) ($data['status_path'] ?? ''),
            (string) ($data['stop_path'] ?? ''),
            (string) ($data['hash'] ?? ''),
            is_array($data['verifier'] ?? null) ? $data['verifier'] : [],
            (string) ($data['pause_path'] ?? ''),
            (string) ($data['heartbeat_path'] ?? ''),
            (int) ($data['heartbeat_timeout'] ?? self::DEFAULT_HEARTBEAT_TIMEOUT)
        );
    }
}
