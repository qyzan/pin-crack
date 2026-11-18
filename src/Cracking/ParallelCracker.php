<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Candidates\CandidateSet;
use PinCrack\Support\Console;
use PinCrack\Support\Filesystem;
use PinCrack\Support\Format;
use PinCrack\Support\Json;
use PinCrack\Support\Priority;
use PinCrack\Support\System;
use RuntimeException;

/**
 * Parent process: partitions the candidate list into contiguous ranges, starts
 * one worker per range and tracks progress by polling their status files.
 *
 * There are no pipes anywhere. Worker stdout/stderr go to a log file, so no
 * pipe can fill up and block, and there is no stream_select() - which does not
 * work on proc_open pipes on Windows. See WorkerRunner for the history.
 */
final class ParallelCracker
{
    private const POLL_INTERVAL_US = 400_000;
    private const RATE_WINDOW_SECONDS = 90.0;
    private const CHECKPOINT_EVERY_SECONDS = 30.0;
    private const SETTLE_SECONDS = 10.0;

    public function __construct(
        private readonly string $entryPoint,
        private readonly Console $console
    ) {
    }

    public function crack(CandidateSet $set, string $hash, CrackOptions $options): CrackResult
    {
        $total = $set->count;
        $workers = max(1, $options->workers);
        $checkpoint = $options->checkpoint;

        /** @var list<array{from: int, to: int, pos: int}> $shards */
        $shards = [];
        $priorTried = 0;
        $priorElapsed = 0.0;
        $resumed = false;

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
                $shards = $state['shards'];
                $priorTried = $state['tried'];
                $priorElapsed = $state['elapsed'];
                $resumed = $shards !== [];

                if ($resumed) {
                    $remaining = 0;
                    foreach ($shards as $shard) {
                        $remaining += max(0, $shard['to'] - $shard['pos']);
                    }
                    $this->console->info(sprintf(
                        "  resuming from checkpoint: %s candidates already tested, %s left\n",
                        Format::number($priorTried),
                        Format::number($remaining)
                    ));
                }
            }
        }

        if ($shards === []) {
            $perWorker = (int) ceil($total / $workers);
            for ($i = 0; $i < $workers; $i++) {
                $from = $i * $perWorker;
                $to = min($total, $from + $perWorker);
                if ($from >= $to) {
                    break;
                }
                $shards[] = ['from' => $from, 'to' => $to, 'pos' => $from];
            }
        }

        $pending = [];
        foreach ($shards as $key => $shard) {
            if ($shard['pos'] < $shard['to']) {
                $pending[$key] = true;
            }
        }

        if ($pending === []) {
            return new CrackResult(null, $priorTried, $priorElapsed, -1, null, true, 0, true);
        }

        $dir = Filesystem::tempDir('pincrack_');
        $stopFile = $dir . DIRECTORY_SEPARATOR . 'STOP';
        $logFile = $dir . DIRECTORY_SEPARATOR . 'worker.log';
        $devNull = Filesystem::devNull();

        /** @var array<int, resource> $procs */
        $procs = [];
        /** @var array<int, string> $statusFiles */
        $statusFiles = [];

        foreach (array_keys($pending) as $key) {
            $statusFiles[$key] = $dir . DIRECTORY_SEPARATOR . "w$key.json";
            $jobFile = $dir . DIRECTORY_SEPARATOR . "job$key.json";

            (new WorkerJob(
                $set->path,
                $set->recordLength,
                $set->indexPath,
                $shards[$key]['pos'],
                $shards[$key]['to'],
                $statusFiles[$key],
                $stopFile,
                $hash,
                $options->verifierOptions
            ))->writeTo($jobFile);

            $command = [PHP_BINARY, $this->entryPoint, '--worker', '--job', $jobFile];
            $spec = [
                0 => ['file', $devNull, 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ];

            $process = @proc_open($command, $spec, $pipes);
            if (!is_resource($process)) {
                continue;
            }

            if ($options->priority !== Priority::NORMAL) {
                System::lowerPriority((int) proc_get_status($process)['pid'], $options->priority);
            }
            $procs[$key] = $process;
        }

        if ($procs === []) {
            Filesystem::removeTree($dir);

            throw new RuntimeException('no worker could be started');
        }

        $guard = new ThroughputGuard($options->guardPercent, $options->cooldownSeconds);

        $t0 = microtime(true);
        $found = null;
        $foundIndex = -1;
        $stopped = null;
        $tried = $priorTried;
        $lastCheckpoint = $t0;
        $lastDraw = 0.0;
        /** @var list<array{0: float, 1: int}> $window */
        $window = [];

        $save = function (bool $complete = false) use (&$shards, &$tried, &$found, &$foundIndex, $checkpoint, $priorElapsed, $t0): void {
            if ($checkpoint === null) {
                return;
            }
            if ($complete) {
                foreach ($shards as $key => $shard) {
                    $shards[$key]['pos'] = $shard['to'];
                }
            }
            $checkpoint->save($shards, $tried, $priorElapsed + (microtime(true) - $t0), $found, $foundIndex);
        };

        while (true) {
            usleep(self::POLL_INTERVAL_US);
            $now = microtime(true);

            $sumTried = 0;
            $allDone = true;

            foreach ($statusFiles as $key => $file) {
                $status = Json::readSafe($file);
                if ($status === null) {
                    $allDone = false;
                    continue;
                }

                $shards[$key]['pos'] = max($shards[$key]['pos'], (int) ($status['pos'] ?? $shards[$key]['pos']));
                $sumTried += (int) ($status['tried'] ?? 0);

                // Record the hit, but do NOT break out of this loop here.
                //
                // An earlier version broke immediately, so shards after the
                // finding one were left out of the sum and "tried" came out
                // too low. On a real sweep the secret sat in shard 9 of 12 and
                // the final report lost 154,820 candidates (3 shards) -
                // printing 23.63 H/s where the true figure was 31.29 H/s.
                if (($status['found'] ?? null) !== null && $found === null) {
                    $found = (string) $status['found'];
                    $foundIndex = (int) ($status['index'] ?? -1);
                }

                if (($status['done'] ?? false) !== true) {
                    $allDone = false;
                }
            }

            $tried = $priorTried + $sumTried;

            if ($found !== null) {
                // Ask the other workers to stop and give them a moment to
                // publish their final counts, so the reported "tried" covers
                // the work every shard actually did rather than only the
                // shard that got the hit.
                @touch($stopFile);
                $deadline = microtime(true) + 1.0;
                while (microtime(true) < $deadline) {
                    usleep(100_000);

                    if (!$this->anyRunning($procs)) {
                        break;
                    }
                }

                $sumTried = 0;
                foreach ($statusFiles as $key => $file) {
                    $status = Json::readSafe($file);
                    if ($status === null) {
                        continue;
                    }
                    $shards[$key]['pos'] = max($shards[$key]['pos'], (int) ($status['pos'] ?? 0));
                    $sumTried += (int) ($status['tried'] ?? 0);
                }
                $tried = $priorTried + $sumTried;

                break;
            }

            $anyAlive = $this->anyRunning($procs);

            if ($allDone) {
                break;
            }

            if (!$anyAlive) {
                // Every worker has exited but some range is unfinished: do not
                // wait forever, report what actually happened.
                foreach ($shards as $shard) {
                    if ($shard['pos'] < $shard['to']) {
                        $stopped = 'a worker exited before finishing its range';
                        break;
                    }
                }
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

            $elapsed = $priorElapsed + ($now - $t0);
            $averageRate = ($now - $t0) > 0 ? ($tried - $priorTried) / ($now - $t0) : 0.0;
            $rate = $windowRate > 0 ? $windowRate : $averageRate;

            if ($guard->shouldPause($now, $windowRate, $now - $t0, $total - $tried, $total)) {
                $this->console->clearLine();
                $this->console->info(sprintf(
                    "  [guard] %.1f H/s stayed below %d%% of the %.1f H/s peak for %d s"
                    . " - pausing %d s to cool down\n",
                    $windowRate,
                    $guard->percent(),
                    $guard->bestRate(),
                    (int) $guard->slowFor($now),
                    $guard->cooldownSeconds()
                ));
                sleep($guard->cooldownSeconds());
                $guard->reset($windowRate);
                $window = [];
            }

            if ($checkpoint !== null && $now - $lastCheckpoint > self::CHECKPOINT_EVERY_SECONDS) {
                $save();
                $lastCheckpoint = $now;
            }

            if ($now - $lastDraw > ($this->console->isTty() ? 1.0 : 30.0)) {
                $this->drawProgress(
                    $tried,
                    $total,
                    $elapsed,
                    $rate,
                    $averageRate,
                    ($now - $t0) >= self::SETTLE_SECONDS && $rate > 0,
                    count($procs),
                    $options->priority,
                    $guard->cooldowns()
                );
                $lastDraw = $now;
            }

            if ($options->maxSeconds > 0 && ($now - $t0) >= $options->maxSeconds) {
                $stopped = 'time limit reached';
                @touch($stopFile);

                // Give the workers a moment to write their final positions.
                $deadline = microtime(true) + 3.0;
                while (microtime(true) < $deadline) {
                    usleep(200_000);
                }
                foreach ($statusFiles as $key => $file) {
                    $status = Json::readSafe($file);
                    if ($status !== null) {
                        $shards[$key]['pos'] = max($shards[$key]['pos'], (int) ($status['pos'] ?? 0));
                    }
                }
                break;
            }
        }

        // No pipes are attached, so terminating a worker is safe.
        @touch($stopFile);
        foreach ($procs as $process) {
            @proc_terminate($process);
        }

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if (!$this->anyRunning($procs)) {
                break;
            }
            usleep(100_000);
        }

        foreach ($procs as $process) {
            @proc_close($process);
        }

        $elapsed = $priorElapsed + (microtime(true) - $t0);
        $save($found !== null);

        $log = is_file($logFile) ? trim((string) @file_get_contents($logFile)) : '';
        Filesystem::removeTree($dir);

        $this->console->clearBlock(4);

        if ($log !== '') {
            $this->console->info("  worker output:\n");
            foreach (array_slice(explode("\n", $log), 0, 5) as $line) {
                $this->console->info('    ' . $line . "\n");
            }
        }

        $complete = $found !== null;
        if (!$complete && $stopped === null) {
            $complete = true;
            foreach ($shards as $shard) {
                if ($shard['pos'] < $shard['to']) {
                    $complete = false;
                    break;
                }
            }
        }

        return new CrackResult(
            $found,
            $tried,
            $elapsed,
            $foundIndex,
            $stopped,
            $complete,
            $guard->cooldowns(),
            $resumed
        );
    }

    /**
     * @param array<int, resource> $procs
     */
    private function anyRunning(array $procs): bool
    {
        foreach ($procs as $process) {
            if (proc_get_status($process)['running']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rate and ETA are withheld until there is enough data behind them.
     *
     * The first few seconds divide a start-up burst by a very short interval,
     * which produces figures like "3557 H/s, 4.7 min left" for a job that is
     * really hours long. A missing ETA is better than a nonsense one.
     */
    private function drawProgress(
        int $tried,
        int $total,
        float $elapsed,
        float $rate,
        float $averageRate,
        bool $settled,
        int $workers,
        string $priority,
        int $cooldowns
    ): void {
        $fraction = $total > 0 ? $tried / $total : 0.0;
        $left = $settled ? ($total - $tried) / $rate : INF;

        $this->console->redraw([
            sprintf('  %s %5.1f%%', Format::progressBar($fraction), $fraction * 100),
            sprintf(
                '  %s / %s candidates   %s%s',
                Format::number($tried),
                Format::number($total),
                $settled ? sprintf('%.1f H/s', $rate) : 'measuring...',
                $settled ? sprintf(' (avg %.1f)', $averageRate) : ''
            ),
            sprintf(
                '  elapsed %s   left ~%s   ETA %s',
                Format::duration($elapsed),
                $settled ? Format::duration($left) : '?',
                $settled ? Format::eta($left) : '?'
            ),
            sprintf(
                '  %d workers @ %s priority%s',
                $workers,
                $priority,
                $cooldowns > 0 ? "   cooldowns: $cooldowns" : ''
            ),
        ]);
    }
}
