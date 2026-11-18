<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Candidates\CandidateWriter;
use PinCrack\Support\Console;
use PinCrack\Support\Filesystem;
use PinCrack\Support\Priority;
use PinCrack\Verify\VerifierFactory;

/**
 * Throughput measurement and worker-count auto-tuning.
 */
final class Benchmark
{
    public function __construct(
        private readonly string $entryPoint,
        private readonly Console $console
    ) {
    }

    /**
     * Single-process rate.
     *
     * @param array<string, mixed> $verifierOptions
     */
    public function singleRate(string $hash, array $verifierOptions = [], int $samples = 15): float
    {
        $verifier = VerifierFactory::create($hash, $verifierOptions);

        $start = microtime(true);
        for ($i = 0; $i < $samples; $i++) {
            $verifier->verify(sprintf('%06d', $i));
        }

        return $samples / max(microtime(true) - $start, 1e-9);
    }

    /**
     * Real parallel throughput.
     *
     * Candidates are long random numbers rather than plausible PINs: the hash
     * cost is identical so the timing is valid, but a match is impossible, so
     * the measured sweep always runs to its time limit instead of ending early
     * on a lucky hit.
     *
     * IMPORTANT: this is a BURST rate. A multi-hour sweep is slower, because
     * sustained all-core clocks drop.
     *
     * @param array<string, mixed> $verifierOptions
     */
    public function parallelRate(string $hash, int $workers, int $seconds = 20, array $verifierOptions = []): float
    {
        $path = Filesystem::tempFile('pinbench_');
        $writer = new CandidateWriter($path);

        $count = max($workers * 6, 300);
        for ($i = 0; $i < $count; $i++) {
            $writer->add(sprintf('%019d', random_int(1_000_000, PHP_INT_MAX)));
        }
        $set = $writer->finish('benchmark');

        $start = microtime(true);
        $result = (new ParallelCracker($this->entryPoint, new Console(true)))->crack(
            $set,
            $hash,
            new CrackOptions(
                workers: $workers,
                priority: Priority::NORMAL,
                checkpoint: null,
                maxSeconds: $seconds,
                guardPercent: 0,
                cooldownSeconds: 0,
                verifierOptions: $verifierOptions
            )
        );
        $elapsed = microtime(true) - $start;
        $set->cleanup();

        return $elapsed > 0 ? $result->tried / $elapsed : 0.0;
    }

    /**
     * Pick a worker count by measuring instead of assuming.
     *
     * More workers than physical cores often helps less than expected, and on
     * some laptops actively hurts once the package hits its power limit.
     *
     * @param array<string, mixed> $verifierOptions
     */
    public function autoTuneWorkers(string $hash, int $cpuCount, array $verifierOptions = [], int $seconds = 15): int
    {
        $candidates = array_values(array_unique(array_filter([
            max(2, intdiv($cpuCount, 2)),
            max(2, (int) round($cpuCount * 0.75)),
            $cpuCount,
        ])));

        $best = $cpuCount;
        $bestRate = 0.0;

        foreach ($candidates as $workers) {
            $this->console->status(sprintf("  auto-tune: trying %d workers...\r", $workers));
            $rate = $this->parallelRate($hash, $workers, $seconds, $verifierOptions);
            $this->console->clearLine(60);
            $this->console->info(sprintf("  auto-tune: %2d workers -> %6.2f H/s\n", $workers, $rate));

            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = $workers;
            }
        }

        $this->console->info(sprintf("  auto-tune: chose %d workers (%.2f H/s burst)\n\n", $best, $bestRate));

        return $best;
    }
}
