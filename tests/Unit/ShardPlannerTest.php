<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cracking\ShardPlanner;
use PHPUnit\Framework\TestCase;

final class ShardPlannerTest extends TestCase
{
    public function testSplitCoversTheWholeRangeExactlyOnce(): void
    {
        $shards = ShardPlanner::split(1000, 7);

        self::assertNotEmpty($shards);
        self::assertSame(0, $shards[0]['from']);
        self::assertSame(1000, $shards[count($shards) - 1]['to']);

        $covered = 0;
        $previousEnd = 0;
        foreach ($shards as $shard) {
            self::assertSame($previousEnd, $shard['from'], 'shards must be contiguous');
            self::assertSame($shard['from'], $shard['pos'], 'a fresh shard starts at its beginning');
            self::assertGreaterThan($shard['from'], $shard['to']);
            $covered += $shard['to'] - $shard['from'];
            $previousEnd = $shard['to'];
        }

        self::assertSame(1000, $covered);
    }

    public function testSplitNeverProducesEmptyShards(): void
    {
        $shards = ShardPlanner::split(3, 12);

        self::assertCount(3, $shards);
        foreach ($shards as $shard) {
            self::assertGreaterThan($shard['from'], $shard['to']);
        }
    }

    public function testDegenerateInputs(): void
    {
        self::assertSame([], ShardPlanner::split(0, 4));
        self::assertSame([], ShardPlanner::split(100, 0));
        self::assertCount(1, ShardPlanner::split(100, 1));
    }

    public function testPendingKeysSkipsFinishedShards(): void
    {
        $shards = [
            ['from' => 0, 'to' => 100, 'pos' => 100],   // done
            ['from' => 100, 'to' => 200, 'pos' => 150], // partly done
            ['from' => 200, 'to' => 300, 'pos' => 200], // untouched
        ];

        self::assertSame([1, 2], ShardPlanner::pendingKeys($shards));
    }

    public function testRemainingCountsOnlyUnfinishedWork(): void
    {
        $shards = [
            ['from' => 0, 'to' => 100, 'pos' => 100],
            ['from' => 100, 'to' => 200, 'pos' => 150],
            ['from' => 200, 'to' => 300, 'pos' => 200],
        ];

        self::assertSame(150, ShardPlanner::remaining($shards));
    }

    public function testRemainingIgnoresAPositionPastTheEnd(): void
    {
        $shards = [['from' => 0, 'to' => 100, 'pos' => 120]];

        self::assertSame(0, ShardPlanner::remaining($shards));
    }

    public function testIsComplete(): void
    {
        self::assertTrue(ShardPlanner::isComplete([
            ['from' => 0, 'to' => 100, 'pos' => 100],
            ['from' => 100, 'to' => 200, 'pos' => 200],
        ]));

        self::assertFalse(ShardPlanner::isComplete([
            ['from' => 0, 'to' => 100, 'pos' => 100],
            ['from' => 100, 'to' => 200, 'pos' => 199],
        ]));

        self::assertTrue(ShardPlanner::isComplete([]));
    }

    /**
     * The point of separating shards from the worker count: a plan made for
     * one pool size stays valid for another.
     */
    public function testAPlanStaysValidAtAnyPoolSize(): void
    {
        $shards = ShardPlanner::split(1000, 12);
        self::assertCount(12, $shards);

        $shards[0]['pos'] = 40;                      // part way through
        $shards[5]['pos'] = $shards[5]['to'];        // finished

        $pending = ShardPlanner::pendingKeys($shards);

        self::assertNotContains(5, $pending);
        self::assertContains(0, $pending);
        self::assertCount(11, $pending);

        // 1000 total, minus the 40 done in shard 0, minus all 84 of shard 5.
        self::assertSame(1000 - 40 - 84, ShardPlanner::remaining($shards));
    }
}
