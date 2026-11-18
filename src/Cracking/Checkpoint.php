<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Support\Json;

/**
 * Resumable progress for long sweeps.
 *
 * A checkpoint is only accepted when its signature matches the current job.
 * The signature covers the target hash and the candidate source, so resuming
 * a different job silently - and reporting coverage that never happened - is
 * not possible.
 *
 * The signature deliberately does NOT cover the worker count. It used to, and
 * that was a bug: shards are a property of the candidate list, not of how many
 * processes happen to chew through them, so `--workers 8 --resume` after a
 * `--workers 12` run rejected the checkpoint. Worse, the rejected file was
 * then overwritten by the fresh run, destroying hours of progress with no
 * message at all. Mismatches are now reported and refused instead of
 * overwritten - see status().
 *
 * @phpstan-import-type Shard from ShardPlanner
 */
final class Checkpoint
{
    public const VERSION = 5;

    public const STATUS_MISSING = 'missing';
    public const STATUS_MISMATCHED = 'mismatched';
    public const STATUS_USABLE = 'usable';

    public function __construct(
        private readonly string $path,
        private readonly string $signature
    ) {
    }

    /**
     * Identifies the job, not the run. Hash plus candidate source plus
     * candidate count: anything that changes which candidates get tested.
     */
    public static function signature(string $hash, string $sourceLabel, int $total): string
    {
        return substr(
            hash('sha256', implode('|', [$hash, $sourceLabel, (string) $total])),
            0,
            16
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Missing, belonging to a different job, or usable here.
     *
     * Callers must distinguish the last two: a mismatched checkpoint is
     * somebody's unfinished work and must never be silently clobbered.
     */
    public function status(): string
    {
        if (!$this->exists()) {
            return self::STATUS_MISSING;
        }

        $data = Json::readSafe($this->path);
        if ($data === null) {
            // Unreadable or truncated: treat as a mismatch rather than as
            // absent, so the operator is told instead of losing the file.
            return self::STATUS_MISMATCHED;
        }

        $matches = ($data['ckpt_ver'] ?? 0) === self::VERSION
            && ($data['sig'] ?? '') === $this->signature;

        return $matches ? self::STATUS_USABLE : self::STATUS_MISMATCHED;
    }

    /**
     * @return array{
     *     shards: list<Shard>,
     *     tried: int,
     *     elapsed: float,
     *     found: string|null,
     *     index: int
     * }|null
     */
    public function load(): ?array
    {
        if ($this->status() !== self::STATUS_USABLE) {
            return null;
        }

        $data = Json::readSafe($this->path);
        if ($data === null) {
            return null;
        }

        $shards = [];
        foreach ((array) ($data['shards'] ?? []) as $shard) {
            if (!is_array($shard)) {
                continue;
            }
            $shards[] = [
                'from' => (int) ($shard['from'] ?? 0),
                'to' => (int) ($shard['to'] ?? 0),
                'pos' => (int) ($shard['pos'] ?? 0),
            ];
        }

        $found = $data['found'] ?? null;

        return [
            'shards' => $shards,
            'tried' => (int) ($data['tried'] ?? 0),
            'elapsed' => (float) ($data['elapsed'] ?? 0),
            'found' => is_string($found) ? $found : null,
            'index' => (int) ($data['index'] ?? -1),
        ];
    }

    /**
     * @param list<Shard> $shards
     */
    public function save(array $shards, int $tried, float $elapsed, ?string $found, int $index): void
    {
        Json::writeAtomic($this->path, [
            'ckpt_ver' => self::VERSION,
            'sig' => $this->signature,
            'shards' => $shards,
            'tried' => $tried,
            'elapsed' => $elapsed,
            'found' => $found,
            'index' => $index,
            'updated' => date('c'),
        ]);
    }

    public function discard(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
