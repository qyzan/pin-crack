<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cracking\ThroughputGuard;
use PHPUnit\Framework\TestCase;

final class ThroughputGuardTest extends TestCase
{
    public function testDisabledGuardNeverPauses(): void
    {
        $guard = new ThroughputGuard(0, 120);

        self::assertFalse($guard->isEnabled());
        self::assertFalse($guard->shouldPause(1000.0, 1.0, 10_000.0, 900_000, 1_000_000));
    }

    public function testNothingHappensDuringWarmup(): void
    {
        $guard = new ThroughputGuard(50, 60, 300.0);

        self::assertFalse($guard->shouldPause(100.0, 100.0, 100.0, 900_000, 1_000_000));
        self::assertFalse($guard->shouldPause(120.0, 1.0, 120.0, 900_000, 1_000_000));
        self::assertSame(0, $guard->cooldowns());
    }

    public function testSustainedLowRateTriggersExactlyOnePause(): void
    {
        $guard = new ThroughputGuard(50, 60, 300.0);
        $now = 1000.0;

        // Establish a peak after the warm-up window.
        self::assertFalse($guard->shouldPause($now, 100.0, 400.0, 900_000, 1_000_000));
        self::assertSame(100.0, $guard->bestRate());

        // Rate collapses, but not for long enough yet.
        self::assertFalse($guard->shouldPause($now + 10, 20.0, 410.0, 900_000, 1_000_000));
        self::assertFalse($guard->shouldPause($now + 59, 20.0, 459.0, 900_000, 1_000_000));

        // Held low past the hold window (60 s after it first went slow).
        self::assertTrue($guard->shouldPause($now + 71, 20.0, 471.0, 900_000, 1_000_000));
        self::assertSame(1, $guard->cooldowns());
    }

    public function testRecoveryResetsTheTimer(): void
    {
        $guard = new ThroughputGuard(50, 60, 300.0);
        $now = 1000.0;

        $guard->shouldPause($now, 100.0, 400.0, 900_000, 1_000_000);
        $guard->shouldPause($now + 10, 20.0, 410.0, 900_000, 1_000_000);
        self::assertGreaterThan(0.0, $guard->slowFor($now + 20));

        // Back to full speed clears the "slow since" marker.
        $guard->shouldPause($now + 30, 100.0, 430.0, 900_000, 1_000_000);
        self::assertSame(0.0, $guard->slowFor($now + 30));

        self::assertFalse($guard->shouldPause($now + 120, 20.0, 520.0, 900_000, 1_000_000));
    }

    public function testNoPauseWhenAlmostDone(): void
    {
        $guard = new ThroughputGuard(50, 60, 300.0);
        $now = 1000.0;

        $guard->shouldPause($now, 100.0, 400.0, 900_000, 1_000_000);

        // Only 1% of the keyspace left: pausing two minutes helps nobody.
        self::assertFalse($guard->shouldPause($now + 200, 5.0, 600.0, 10_000, 1_000_000));
    }

    public function testHoldWindowIsNeverShorterThanAMinute(): void
    {
        $guard = new ThroughputGuard(50, 5, 300.0);
        $now = 1000.0;

        $guard->shouldPause($now, 100.0, 400.0, 900_000, 1_000_000);
        $guard->shouldPause($now + 1, 10.0, 401.0, 900_000, 1_000_000);

        self::assertFalse($guard->shouldPause($now + 30, 10.0, 430.0, 900_000, 1_000_000));
        self::assertTrue($guard->shouldPause($now + 62, 10.0, 462.0, 900_000, 1_000_000));
    }
}
