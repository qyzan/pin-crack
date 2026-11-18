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
 */
final class WorkerRunner
{
    /**
     * How often a worker publishes its position and checks for a stop
     * request.
     *
     * Short enough that a sub-second sweep still reports most of the work it
     * did - with a one-second interval, a run that ended in 400 ms reported
     * only the finding worker's count and made "tried" look far too low.
     */
    private const REPORT_EVERY_SECONDS = 0.25;

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
            if ($now - $lastReport >= self::REPORT_EVERY_SECONDS) {
                $this->report($job->statusPath, $i + 1, $tried, false, null);
                $lastReport = $now;

                // Stop cleanly when the parent asks; progress is already saved.
                if ($job->stopPath !== '' && is_file($job->stopPath)) {
                    $this->report($job->statusPath, $i + 1, $tried, false, null, -1, true);
                    $reader->close();

                    return 0;
                }
            }
        }

        $this->report($job->statusPath, $job->to, $tried, true, null);
        $reader->close();

        return 0;
    }

    private function report(
        string $path,
        int $pos,
        int $tried,
        bool $done,
        ?string $found,
        int $index = -1,
        bool $stopped = false
    ): void {
        Json::writeAtomic($path, [
            'pos' => $pos,
            'tried' => $tried,
            'done' => $done,
            'found' => $found,
            'index' => $index,
            'stopped' => $stopped,
        ]);
    }
}
