<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Candidates\CandidateSet;
use PinCrack\Support\Console;
use PinCrack\Support\Format;
use PinCrack\Verify\VerifierFactory;

/**
 * Single-process sweep.
 *
 * Used when proc_open() is unavailable (hardened php.ini, some shared
 * hosting) and whenever one worker is asked for - which, for cheap hashes, is
 * the fastest configuration there is: a full 6-digit md5 keyspace finishes in
 * 1.35 s here against 1.84 s with twelve worker processes, because the
 * per-candidate cost is file and process overhead rather than hashing.
 *
 * The throughput guard works here too. It used to be ignored in this path
 * entirely; since this process *is* the worker, sleeping really does take the
 * load off.
 */
final class InlineCracker
{
    private const RATE_WINDOW_SECONDS = 90.0;
    private const CHECKPOINT_EVERY_SECONDS = 30.0;
    private const CHECK_EVERY_CANDIDATES = 16;

    public function __construct(private readonly Console $console)
    {
    }

    public function crack(CandidateSet $set, string $hash, CrackOptions $options): CrackResult
    {
        $verifier = VerifierFactory::create($hash, $options->verifierOptions);
        $reader = CandidateReader::fromSet($set);

        $total = $set->count;
        $start = 0;
        $priorTried = 0;
        $priorElapsed = 0.0;
        $resumed = false;

        $checkpoint = $options->checkpoint;
        if ($checkpoint !== null) {
            $state = $checkpoint->load();
            if ($state !== null) {
                if ($state['found'] !== null) {
                    return new CrackResult(
                        $state['found'],
                        $state['tried'],
                        $state['elapsed'],
                        $state['index'],
                        null,
                        true,
                        0,
                        true
                    );
                }
                // One process sweeps one range, so resume behind the furthest
                // shard that is still unfinished.
                $pending = ShardPlanner::pendingKeys($state['shards']);
                if ($pending !== []) {
                    $start = $state['shards'][$pending[0]]['pos'];
                }
                $priorTried = $state['tried'];
                $priorElapsed = $state['elapsed'];
                $resumed = $start > 0;

                if ($resumed) {
                    $this->console->info(sprintf(
                        "  resuming from checkpoint: %s candidates already tested\n",
                        Format::number($priorTried)
                    ));
                }
            }
        }

        $reader->seek($start);

        $guard = new ThroughputGuard($options->guardPercent, $options->cooldownSeconds);

        $t0 = microtime(true);
        $tried = 0;
        $lastDraw = 0.0;
        $lastCheckpoint = $t0;
        $stopped = null;
        $position = $start;
        /** @var list<array{0: float, 1: int}> $window */
        $window = [];

        for ($i = $start; $i < $total; $i++) {
            $candidate = $reader->next();
            if ($candidate === null) {
                break;
            }
            $position = $i + 1;

            if ($candidate === '') {
                continue;
            }
            $tried++;

            if ($verifier->verify($candidate)) {
                $reader->close();
                $this->console->clearLine();
                $elapsed = $priorElapsed + (microtime(true) - $t0);
                $checkpoint?->save(
                    [['from' => 0, 'to' => $total, 'pos' => $total]],
                    $priorTried + $tried,
                    $elapsed,
                    $candidate,
                    $i
                );

                return new CrackResult(
                    $candidate,
                    $priorTried + $tried,
                    $elapsed,
                    $i,
                    null,
                    true,
                    $guard->cooldowns(),
                    $resumed
                );
            }

            // Clock calls and file writes are expensive relative to a cheap
            // hash, so only look at the time every few candidates.
            if ($tried % self::CHECK_EVERY_CANDIDATES !== 0) {
                continue;
            }

            $now = microtime(true);

            if ($options->maxSeconds > 0 && ($now - $t0) >= $options->maxSeconds) {
                $stopped = 'time limit reached';
                break;
            }

            $window[] = [$now, $tried];
            while (count($window) > 2 && $now - $window[0][0] > self::RATE_WINDOW_SECONDS) {
                array_shift($window);
            }

            $windowRate = 0.0;
            if (count($window) >= 2) {
                $dt = $window[count($window) - 1][0] - $window[0][0];
                $dn = $window[count($window) - 1][1] - $window[0][1];
                if ($dt > 1) {
                    $windowRate = $dn / $dt;
                }
            }

            if ($guard->shouldPause($now, $windowRate, $now - $t0, $total - $position, $total)) {
                $this->console->clearLine();
                $this->console->info(sprintf(
                    "  [guard] %.1f H/s stayed below %d%% of the %.1f H/s peak"
                    . " - pausing %d s to cool down\n",
                    $windowRate,
                    $guard->percent(),
                    $guard->bestRate(),
                    $guard->cooldownSeconds()
                ));
                $checkpoint?->save(
                    [['from' => 0, 'to' => $total, 'pos' => $position]],
                    $priorTried + $tried,
                    $priorElapsed + ($now - $t0),
                    null,
                    -1
                );
                sleep($guard->cooldownSeconds());
                $guard->reset($windowRate);
                $window = [];
                $lastCheckpoint = microtime(true);
            }

            if ($checkpoint !== null && $now - $lastCheckpoint > self::CHECKPOINT_EVERY_SECONDS) {
                $checkpoint->save(
                    [['from' => 0, 'to' => $total, 'pos' => $position]],
                    $priorTried + $tried,
                    $priorElapsed + ($now - $t0),
                    null,
                    -1
                );
                $lastCheckpoint = $now;
            }

            if ($now - $lastDraw > 1.0) {
                $rate = $windowRate > 0 ? $windowRate : $tried / max($now - $t0, 1e-9);
                $this->draw($priorTried + $tried, $total, $priorElapsed + ($now - $t0), $rate);
                $lastDraw = $now;
            }
        }

        $reader->close();
        $this->console->clearLine();

        $elapsed = $priorElapsed + (microtime(true) - $t0);
        $checkpoint?->save(
            [['from' => 0, 'to' => $total, 'pos' => $position]],
            $priorTried + $tried,
            $elapsed,
            null,
            -1
        );

        return new CrackResult(
            null,
            $priorTried + $tried,
            $elapsed,
            -1,
            $stopped,
            $stopped === null && $position >= $total,
            $guard->cooldowns(),
            $resumed
        );
    }

    private function draw(int $tried, int $total, float $elapsed, float $rate): void
    {
        $fraction = $total > 0 ? $tried / $total : 0.0;
        $left = $rate > 0 ? ($total - $tried) / $rate : INF;

        $line = sprintf(
            '  %s %5.1f%%  %s/%s  %.1f H/s  elapsed %s  left ~%s  ETA %s',
            Format::progressBar($fraction, 20),
            $fraction * 100,
            Format::number($tried),
            Format::number($total),
            $rate,
            Format::duration($elapsed),
            Format::duration($left),
            Format::eta($left)
        );

        $this->console->status($this->console->isTty() ? "\r" . $line . '   ' : $line . "\n");
    }
}
