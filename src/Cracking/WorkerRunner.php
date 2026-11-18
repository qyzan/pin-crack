<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Support\Json;
use PinCrack\Verify\VerifierFactory;

/**
 * A single worker: one contiguous range of the candidate list, progress
 * reported by writing a small JSON file.
 *
 * Coordination deliberately uses STATUS FILES, not pipes.
 *
 * Earlier versions used proc_open pipes with stream_select() and
 * stream_set_blocking(false). Neither works on proc_open pipes on Windows - a
 * long-standing PHP limitation. The symptoms were inter-process messages going
 * missing, one chunk hanging with no completion report, and a parent waiting
 * forever. It got worse with cheap hashes, because the race got tighter.
 *
 * Now each worker owns its own range and writes progress to a small JSON file
 * (write-then-rename, so it is never read half-written). The parent only reads
 * files. No pipes, no select, no protocol that can lose a message.
 *
 * A worker reacts to three things from the parent, all checked at the same
 * cadence as its progress reports: a stop request, a pause request, and the
 * parent's heartbeat going stale.
 */
final class WorkerRunner
{
    /**
     * How often a worker publishes its position and checks the control files.
     *
     * Short enough that a sub-second sweep still reports most of the work it
     * did - with a one-second interval, a run that ended in 400 ms reported
     * only the finding worker's count and made "tried" look far too low.
     */
    private const REPORT_EVERY_SECONDS = 0.25;

    /** How long to idle between checks while paused. */
    private const PAUSE_POLL_US = 500_000;

    public function run(WorkerJob $job): int
    {
        $verifier = VerifierFactory::create($job->hash, $job->verifierOptions);

        $reader = new CandidateReader($job->candidatePath, $job->recordLength, $job->indexPath);
        $reader->seek($job->from);

        $tried = 0;
        $lastReport = 0.0;

        $this->report($job->statusPath, $job->from, 0, false, null);

        for ($i = $job->from; $i < $job->to; $i++) {
            $candidate = $reader->next();
            if ($candidate === null) {
                break;
            }

            if ($candidate !== '') {
                $tried++;
                if ($verifier->verify($candidate)) {
                    $this->report($job->statusPath, $i + 1, $tried, true, $candidate, $i);
                    $reader->close();

                    return 0;
                }
            }

            $now = microtime(true);
            if ($now - $lastReport < self::REPORT_EVERY_SECONDS) {
                continue;
            }
            $lastReport = $now;

            $this->report($job->statusPath, $i + 1, $tried, false, null);

            // The parent is gone: stop rather than becoming an orphan that
            // hashes for hours with nobody collecting the result.
            if ($this->parentIsGone($job)) {
                $this->report($job->statusPath, $i + 1, $tried, false, null, -1, true, 'parent gone');
                $reader->close();

                return 0;
            }

            if ($this->stopRequested($job)) {
                $this->report($job->statusPath, $i + 1, $tried, false, null, -1, true);
                $reader->close();

                return 0;
            }

            // Throughput guard: idle until the parent lifts the pause. This is
            // what actually takes load off the CPU - the parent sleeping on its
            // own would leave every worker hashing at full tilt.
            if ($job->pausePath !== '' && $this->existsNow($job->pausePath)) {
                $this->report($job->statusPath, $i + 1, $tried, false, null, -1, false, 'paused');

                while ($this->existsNow($job->pausePath)) {
                    usleep(self::PAUSE_POLL_US);

                    if ($this->stopRequested($job) || $this->parentIsGone($job)) {
                        $this->report($job->statusPath, $i + 1, $tried, false, null, -1, true, 'stopped while paused');
                        $reader->close();

                        return 0;
                    }
                }

                $this->report($job->statusPath, $i + 1, $tried, false, null);
                $lastReport = microtime(true);
            }
        }

        $this->report($job->statusPath, $job->to, $tried, true, null);
        $reader->close();

        return 0;
    }

    /**
     * is_file() with the stat cache cleared for that one path first.
     *
     * PHP caches successful stat() results for the lifetime of the process, so
     * a plain is_file() loop can keep seeing a file that the parent already
     * deleted. For the PAUSE file that is the dangerous direction: a worker
     * would stay parked forever after the cooldown ended.
     */
    private function existsNow(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path);
    }

    private function stopRequested(WorkerJob $job): bool
    {
        return $job->stopPath !== '' && $this->existsNow($job->stopPath);
    }

    /**
     * True when the parent's heartbeat is stale or has vanished.
     *
     * Heartbeat supervision is opt-in: with no heartbeat path configured a
     * worker runs unsupervised, which is what the benchmark harness wants.
     */
    private function parentIsGone(WorkerJob $job): bool
    {
        if ($job->heartbeatPath === '' || $job->heartbeatTimeout <= 0) {
            return false;
        }

        clearstatcache(true, $job->heartbeatPath);
        $touched = @filemtime($job->heartbeatPath);

        if ($touched === false) {
            // The heartbeat file is gone, and so is whatever created it.
            return true;
        }

        return (time() - $touched) > $job->heartbeatTimeout;
    }

    private function report(
        string $path,
        int $pos,
        int $tried,
        bool $done,
        ?string $found,
        int $index = -1,
        bool $stopped = false,
        ?string $note = null
    ): void {
        Json::writeAtomic($path, [
            'pos' => $pos,
            'tried' => $tried,
            'done' => $done,
            'found' => $found,
            'index' => $index,
            'stopped' => $stopped,
            'note' => $note,
        ]);
    }
}
