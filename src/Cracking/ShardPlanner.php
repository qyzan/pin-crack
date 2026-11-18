<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

/**
 * Splits a candidate list into contiguous shards.
 *
 * The shard layout is deliberately independent of the worker count. Workers
 * are a pool that claims shards; how many run at once is a scheduling choice,
 * not part of the plan. That is what lets a sweep resume with a different
 * `--workers` value without re-testing anything.
 *
 * Measured note: on a hybrid CPU (2 P-cores + 8 E-cores) equal-sized shards
 * stay within 6.8% of each other over the same wall-clock window, because the
 * OS migrates threads between core types. Finer shards would buy back only
 * that ~7% tail while costing one process spawn each, so one shard per worker
 * remains the default.
 *
 * @phpstan-type Shard array{from: int, to: int, pos: int}
 */
final class ShardPlanner
{
    /**
     * Fresh split of [0, $total) into at most $parts contiguous shards.
     *
     * @return list<Shard>
     */
    public static function split(int $total, int $parts): array
    {
        if ($total <= 0 || $parts < 1) {
            return [];
        }

        $perPart = (int) ceil($total / $parts);
        $shards = [];

        for ($i = 0; $i < $parts; $i++) {
            $from = $i * $perPart;
            $to = min($total, $from + $perPart);
            if ($from >= $to) {
                break;
            }
            $shards[] = ['from' => $from, 'to' => $to, 'pos' => $from];
        }

        return $shards;
    }

    /**
     * Keys of the shards that still have work left, in order.
     *
     * @param list<Shard> $shards
     * @return list<int>
     */
    public static function pendingKeys(array $shards): array
    {
        $keys = [];
        foreach ($shards as $key => $shard) {
            if ($shard['pos'] < $shard['to']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Candidates still to test across every shard.
     *
     * @param list<Shard> $shards
     */
    public static function remaining(array $shards): int
    {
        $remaining = 0;
        foreach ($shards as $shard) {
            $remaining += max(0, $shard['to'] - $shard['pos']);
        }

        return $remaining;
    }

    /**
     * Whether every shard reached the end of its range.
     *
     * @param list<Shard> $shards
     */
    public static function isComplete(array $shards): bool
    {
        foreach ($shards as $shard) {
            if ($shard['pos'] < $shard['to']) {
                return false;
            }
        }

        return true;
    }
}
