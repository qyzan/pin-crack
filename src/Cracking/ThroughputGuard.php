<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

/**
 * Pauses the sweep when sustained throughput collapses.
 *
 * On a laptop a long all-core run eventually hits thermal or power limits and
 * the rate falls off. Pausing for a while usually recovers more total work
 * than grinding on at a fraction of the original speed, and it keeps the
 * machine from sitting at its thermal ceiling for hours.
 *
 * The trigger is TIME-BASED, not iteration-based. An earlier version counted
 * loop iterations; with the parent loop spinning every 20 ms, "40 iterations"
 * was under a second, so the guard slept for two minutes on ordinary rate
 * jitter. The rate now has to stay low for a sustained period, and only after
 * the run has had time to settle.
 */
final class ThroughputGuard
{
    private float $bestRate = 0.0;
    private ?float $slowSince = null;
    private int $cooldowns = 0;

    public function __construct(
        private readonly int $percent = 50,
        private readonly int $cooldownSeconds = 120,
        private readonly float $warmupSeconds = 300.0,
        private readonly float $minRemainingFraction = 0.02
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->percent > 0;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    public function cooldowns(): int
    {
        return $this->cooldowns;
    }

    public function bestRate(): float
    {
        return $this->bestRate;
    }

    public function slowFor(float $now): float
    {
        return $this->slowSince === null ? 0.0 : $now - $this->slowSince;
    }

    public function reset(float $rate): void
    {
        $this->slowSince = null;
        $this->bestRate = $rate;
    }

    /**
     * Returns true when the caller should pause for cooldownSeconds().
     *
     * The guard stays quiet during the warm-up window, when the window rate
     * is not yet meaningful, and when there is almost no work left - pausing
     * two minutes before the end helps nobody.
     */
    public function shouldPause(float $now, float $windowRate, float $elapsed, int $remaining, int $total): bool
    {
        $holdFor = max(60.0, (float) $this->cooldownSeconds);

        $applicable = $this->percent > 0
            && $windowRate > 0
            && $elapsed > $this->warmupSeconds
            && $total > 0
            && $remaining > $total * $this->minRemainingFraction;

        if (!$applicable) {
            $this->slowSince = null;

            return false;
        }

        if ($windowRate > $this->bestRate) {
            $this->bestRate = $windowRate;
        }

        if ($this->bestRate > 0 && $windowRate < $this->bestRate * ($this->percent / 100)) {
            $this->slowSince ??= $now;
        } else {
            $this->slowSince = null;
        }

        if ($this->slowSince !== null && ($now - $this->slowSince) >= $holdFor) {
            $this->cooldowns++;

            return true;
        }

        return false;
    }

    public function percent(): int
    {
        return $this->percent;
    }
}
