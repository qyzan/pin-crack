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
 * Parent process: partitions the candidate list into contiguous shards and
 * runs a pool of worker processes over them, tracking progress by polling
 * their status files.
 *
 * Shards are a property of the candidate list; the worker count only limits
 * how many run at once. That separation is what lets `--workers 8 --resume`
 * continue a sweep that was started with `--workers 12`.
 *
 * There are no pipes anywhere. Worker stdout/stderr go to a log file, so no
 * pipe can fill up and block, and there is no stream_select() - which does not
 * work on proc_open pipes on Windows. See WorkerRunner for the history.
 *
 * @phpstan-import-type Shard from ShardPlanner
 */
final class ParallelCracker
{
    private const POLL_INTERVAL_US = 400_000;
    private const PAUSE_POLL_US = 250_000;
    private const RATE_WINDOW_SECONDS = 90.0;
    private const CHECKPOINT_EVERY_SECONDS = 30.0;
    private const SETTLE_SECONDS = 10.0;
    private const HEARTBEAT_TIMEOUT = 30;

    public function __construct(
        private readonly string $entryPoint,
        private readonly Console $console
    ) {
    }

    public function crack(CandidateSet $set, string $hash, CrackOptions $options): CrackResult
    {
        $total = $set->count;
        $poolSize = max(1, $options->workers);
        $checkpoint = $options->checkpoint;

        /** @var list<Shard> $shards */
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
                    $this->console->info(sprintf(
                        "  resuming from checkpoint: %s candidates already tested, %s left"
                        . " across %d shards, %d at a time\n",
                        Format::number($priorTried),
                        Format::number(ShardPlanner::remaining($shards)),
                        count(ShardPlanner::pendingKeys($shards)),
                        $poolSize
                    ));
                }
            }
        }

        if ($shards === []) {
            $shards = ShardPlanner::split($total, $poolSize);
        }

        $queue = ShardPlanner::pendingKeys($shards);
        if ($queue === []) {
            return new CrackResult(null, $priorTried, $priorElapsed, -1, null, true, 0, true);
        }

        $dir = Filesystem::tempDir('pincrack_');
        $stopFile = $dir . DIRECTORY_SEPARATOR . 'STOP';
        $pauseFile = $dir . DIRECTORY_SEPARATOR . 'PAUSE';
        $heartbeatFile = $dir . DIRECTORY_SEPARATOR . 'HEARTBEAT';
        $logFile = $dir . DIRECTORY_SEPARATOR . 'worker.log';
        $devNull = Filesystem::devNull();

        // The first heartbeat must exist before any worker starts looking for
        // it, or a worker can decide it is already orphaned.
        @touch($heartbeatFile);

        /** @var array<int, resource> $running */
        $running = [];
        /** @var array<int, string> $statusFiles */
        $statusFiles = [];
        $spawnFailures = 0;
        $everStarted = 0;

        $spawn = function (int $key) use (
            &$running,
            &$statusFiles,
            &$spawnFailures,
            &$everStarted,
            &$shards,
            $dir,
            $set,
            $hash,
            $options,
            $stopFile,
            $pauseFile,
            $heartbeatFile,
            $logFile,
            $devNull
        ): void {
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
                $options->verifierOptions,
                $pauseFile,
                $heartbeatFile,
                self::HEARTBEAT_TIMEOUT
            ))->writeTo($jobFile);

            $command = [PHP_BINARY, $this->entryPoint, '--worker', '--job', $jobFile];
            $spec = [
                0 => ['file', $devNull, 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ];

            $process = @proc_open($command, $spec, $pipes);
            if (!is_resource($process)) {
                $spawnFailures++;

                return;
            }

            if ($options->priority !== Priority::NORMAL) {
                System::lowerPriority((int) proc_get_status($process)['pid'], $options->priority);
            }

            $running[$key] = $process;
            $everStarted++;
        };

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
            while (count($running) < $poolSize && $queue !== []) {
                $spawn((int) array_shift($queue));
            }

            if ($running === []) {
                if ($everStarted === 0) {
                    Filesystem::removeTree($dir);

                    throw new RuntimeException('no worker could be started');
                }
                if ($queue === []) {
                    break;
                }
                // Every spawn attempt failed but shards remain.
                $stopped = 'could not start a worker for the remaining shards';
                break;
            }

            usleep(self::POLL_INTERVAL_US);
            $now = microtime(true);
            @touch($heartbeatFile);

            $tried = $priorTried + $this->collectStatuses($statusFiles, $shards, $found, $foundIndex);

            if ($found !== null) {
                // Ask the other workers to stop and give them a moment to
                // publish their final counts, so the reported "tried" covers
                // the work every shard actually did rather than only the
                // shard that got the hit.
                @touch($stopFile);
                $deadline = microtime(true) + 1.0;
                while (microtime(true) < $deadline) {
                    usleep(100_000);
                    @touch($heartbeatFile);
                    if (!$this->anyRunning($running)) {
                        break;
                    }
                }
                $tried = $priorTried + $this->collectStatuses($statusFiles, $shards, $found, $foundIndex);

                break;
            }

            foreach ($running as $key => $process) {
                if (proc_get_status($process)['running']) {
                    continue;
                }

                proc_close($process);
                unset($running[$key]);

                $status = Json::readSafe($statusFiles[$key]);
                $cleanStop = is_array($status) && ($status['stopped'] ?? false) === true;
                if (!$cleanStop && $shards[$key]['pos'] < $shards[$key]['to']) {
                    $stopped = 'a worker exited before finishing its range';
                }
            }

            if ($stopped !== null) {
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
                $this->coolDown(
                    $guard,
                    $pauseFile,
                    $heartbeatFile,
                    $statusFiles,
                    $shards,
                    $found,
                    $foundIndex,
                    $tried,
                    $priorTried,
                    $windowRate,
                    $save,
                    $t0,
                    $options->maxSeconds
                );
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
                    count($running),
                    count($queue),
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
                    @touch($heartbeatFile);
                }
                $tried = $priorTried + $this->collectStatuses($statusFiles, $shards, $found, $foundIndex);

                break;
            }
        }

        // No pipes are attached, so terminating a worker is safe.
        @unlink($pauseFile);
        @touch($stopFile);
        foreach ($running as $process) {
            @proc_terminate($process);
        }

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            if (!$this->anyRunning($running)) {
                break;
            }
            usleep(100_000);
        }

        foreach ($running as $process) {
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
        if ($spawnFailures > 0) {
            $this->console->info(sprintf("  note: %d worker(s) could not be started\n", $spawnFailures));
        }

        $complete = $found !== null || ($stopped === null && ShardPlanner::isComplete($shards));

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
     * Read every status file, update shard positions and return the summed
     * candidate count.
     *
     * A hit is recorded but does NOT stop the sweep over the status files. An
     * earlier version broke out immediately, so shards after the finding one
     * were left out of the sum and "tried" came out too low. On a real sweep
     * the secret sat in shard 9 of 12 and the final report lost 154,820
     * candidates - printing 23.63 H/s where the true figure was 31.29 H/s.
     *
     * @param array<int, string> $statusFiles
     * @param list<Shard>        $shards
     */
    private function collectStatuses(
        array $statusFiles,
        array &$shards,
        ?string &$found,
        int &$foundIndex
    ): int {
        $sumTried = 0;

        foreach ($statusFiles as $key => $file) {
            if (!isset($shards[$key])) {
                continue;
            }

            $status = Json::readSafe($file);
            if ($status === null) {
                continue;
            }

            $shards[$key]['pos'] = max($shards[$key]['pos'], (int) ($status['pos'] ?? $shards[$key]['pos']));
            $sumTried += (int) ($status['tried'] ?? 0);

            if (($status['found'] ?? null) !== null && $found === null) {
                $found = (string) $status['found'];
                $foundIndex = (int) ($status['index'] ?? -1);
            }
        }

        return $sumTried;
    }

    /**
     * Actually take load off the CPU.
     *
     * The parent creates the PAUSE file, which every worker polls at its
     * reporting cadence and then idles on. An earlier version only called
     * sleep() in the parent: the message said "pausing 120 s to cool down"
     * while all N workers kept hashing at 100%, so the guard did nothing at
     * all. It also froze the display and skipped checkpoints for the whole
     * pause, and could overshoot --max-hours by a full cooldown.
     *
     * The heartbeat keeps being touched here, otherwise paused workers would
     * conclude the parent had died.
     *
     * @param array<int, string>            $statusFiles
     * @param list<Shard>                   $shards
     * @param callable(bool=): void         $save
     */
    private function coolDown(
        ThroughputGuard $guard,
        string $pauseFile,
        string $heartbeatFile,
        array $statusFiles,
        array &$shards,
        ?string &$found,
        int &$foundIndex,
        int &$tried,
        int $priorTried,
        float $windowRate,
        callable $save,
        float $t0,
        int $maxSeconds
    ): void {
        @touch($pauseFile);

        $this->console->clearLine();
        $this->console->info(sprintf(
            "  [guard] %.1f H/s stayed below %d%% of the %.1f H/s peak"
            . " - pausing %d workers for %d s to cool down\n",
            $windowRate,
            $guard->percent(),
            $guard->bestRate(),
            count($statusFiles),
            $guard->cooldownSeconds()
        ));

        $resumeAt = microtime(true) + $guard->cooldownSeconds();
        $lastSave = microtime(true);

        while (microtime(true) < $resumeAt) {
            usleep(self::PAUSE_POLL_US);
            @touch($heartbeatFile);

            // Keep the checkpoint current: a crash during a two-minute pause
            // should not cost more than any other moment of the run.
            if (microtime(true) - $lastSave > self::CHECKPOINT_EVERY_SECONDS) {
                $tried = $priorTried + $this->collectStatuses($statusFiles, $shards, $found, $foundIndex);
                $save();
                $lastSave = microtime(true);
            }

            // A pause must not swallow the time limit.
            if ($maxSeconds > 0 && (microtime(true) - $t0) >= $maxSeconds) {
                break;
            }
        }

        @unlink($pauseFile);
        $guard->reset($windowRate);
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
        int $running,
        int $queued,
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
                '  %d workers @ %s priority%s%s',
                $running,
                $priority,
                $queued > 0 ? sprintf('   %d shards queued', $queued) : '',
                $cooldowns > 0 ? "   cooldowns: $cooldowns" : ''
            ),
        ]);
    }
}
