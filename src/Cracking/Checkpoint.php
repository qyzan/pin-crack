<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Support\Json;

/**
 * Resumable progress for long sweeps.
 *
 * A checkpoint is only accepted when its signature matches the current job.
 * The signature covers the target hash, the candidate source and the shard
 * layout, so resuming a different job silently - and reporting coverage that
 * never happened - is not possible.
 */
final class Checkpoint
{
    public const VERSION = 4;

    public function __construct(
        private readonly string $path,
        private readonly string $signature
    ) {
    }

    public static function signature(string $hash, string $sourceLabel, int $total, int $workers): string
    {
        return substr(
            hash('sha256', implode('|', [$hash, $sourceLabel, (string) $total, (string) $workers])),
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
     * @return array{
     *     shards: list<array{from: int, to: int, pos: int}>,
     *     tried: int,
     *     elapsed: float,
     *     found: string|null,
     *     index: int
     * }|null
     */
    public function load(): ?array
    {
        $data = Json::readSafe($this->path);
        if ($data === null) {
            return null;
        }
        if (($data['ckpt_ver'] ?? 0) !== self::VERSION || ($data['sig'] ?? '') !== $this->signature) {
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
     * @param list<array{from: int, to: int, pos: int}> $shards
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
