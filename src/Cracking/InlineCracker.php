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
 * hosting) or when the operator explicitly asks for one worker. Slower, but
 * it has no moving parts.
 */
final class InlineCracker
{
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
                $start = $state['shards'][0]['pos'] ?? 0;
                $priorTried = $state['tried'];
                $priorElapsed = $state['elapsed'];
                $resumed = $start > 0;
            }
        }

        $reader->seek($start);

        $t0 = microtime(true);
        $tried = 0;
        $lastDraw = 0.0;
        $lastCheckpoint = $t0;
        $stopped = null;
        $position = $start;

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
                $checkpoint?->save(
                    [['from' => 0, 'to' => $total, 'pos' => $total]],
                    $priorTried + $tried,
                    $priorElapsed + (microtime(true) - $t0),
                    $candidate,
                    $i
                );

                return new CrackResult(
                    $candidate,
                    $priorTried + $tried,
                    $priorElapsed + (microtime(true) - $t0),
                    $i,
                    null,
                    true,
                    0,
                    $resumed
                );
            }

            $now = microtime(true);

            if ($options->maxSeconds > 0 && ($now - $t0) >= $options->maxSeconds) {
                $stopped = 'time limit reached';
                break;
            }

            if ($checkpoint !== null && $now - $lastCheckpoint > 30) {
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
                $this->draw($priorTried + $tried, $total, $priorElapsed + ($now - $t0), $tried / max($now - $t0, 1e-9));
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
            0,
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
