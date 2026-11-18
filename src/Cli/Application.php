<?php

declare(strict_types=1);

namespace PinCrack\Cli;

use PinCrack\Candidates\CandidateBuilder;
use PinCrack\Candidates\CandidateSet;
use PinCrack\Candidates\Mask;
use PinCrack\Cracking\Benchmark;
use PinCrack\Cracking\Checkpoint;
use PinCrack\Cracking\CrackOptions;
use PinCrack\Cracking\CrackResult;
use PinCrack\Cracking\InlineCracker;
use PinCrack\Cracking\ParallelCracker;
use PinCrack\Cracking\WorkerJob;
use PinCrack\Cracking\WorkerRunner;
use PinCrack\Support\Console;
use PinCrack\Support\Filesystem;
use PinCrack\Support\Format;
use PinCrack\Support\System;
use PinCrack\Verify\HashIdentifier;
use PinCrack\Verify\VerifierFactory;
use PinCrack\Version;
use InvalidArgumentException;
use Throwable;

final class Application
{
    public const EXIT_RECOVERED = 0;
    public const EXIT_NOT_FOUND = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_INCOMPLETE = 3;
    public const EXIT_ERROR = 4;

    private const DEFAULT_CHECKPOINT = '.pin-crack-progress.json';

    /**
     * Worker cap for raw digests.
     *
     * Measured: a full 6-digit md5 keyspace takes 1.35 s with one worker and
     * with four, but 1.84 s with twelve. For cheap hashes the per-candidate
     * cost is file and process overhead rather than hashing, so extra
     * processes only add contention.
     */
    private const DIGEST_WORKER_CAP = 4;

    public function __construct(private readonly string $entryPoint)
    {
    }

    /**
     * @param list<string> $argv arguments without the script name
     */
    public function run(array $argv): int
    {
        try {
            $options = Options::parse($argv);
            $options->validate();
        } catch (UsageException $e) {
            fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

            return self::EXIT_USAGE;
        }

        if ($options->worker) {
            return (new WorkerRunner())->run(WorkerJob::readFrom((string) $options->job));
        }

        if ($options->help) {
            fwrite(STDOUT, Usage::text());

            return self::EXIT_RECOVERED;
        }

        if ($options->version) {
            fwrite(STDOUT, Version::line() . "\n");

            return self::EXIT_RECOVERED;
        }

        $console = new Console($options->quiet || $options->json);

        try {
            return $this->execute($options, $console);
        } catch (UsageException | InvalidArgumentException $e) {
            // The operator's inputs do not make sense - a bad mask, an
            // oversized source, an empty candidate list.
            fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

            return self::EXIT_USAGE;
        } catch (Throwable $e) {
            // Not a usage problem: a disk filling up or an unreadable file is
            // not the operator mistyping a flag, and a script branching on the
            // exit code should be able to tell the two apart.
            fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

            return self::EXIT_ERROR;
        }
    }

    private function execute(Options $options, Console $console): int
    {
        $builder = $this->candidateBuilder($options);

        if ($options->saveWordlist !== null) {
            $set = $builder->build($options->saveWordlist);
            $set->keepFiles();
            fwrite(STDOUT, sprintf(
                "wordlist written: %s\n  %s candidates  (%s)  %s on disk\n",
                $options->saveWordlist,
                Format::number($set->count),
                $set->label,
                Format::bytes($set->sizeOnDisk())
            ));

            return self::EXIT_RECOVERED;
        }

        $targets = TargetLoader::load($options);
        if ($targets === []) {
            throw new UsageException(
                'no hash given. Use --hash, --hash-file or stdin; --help for details'
            );
        }

        $cpuCount = System::cpuCount();

        if ($options->bench) {
            return $this->benchmark($options, $console, $targets[0], $cpuCount);
        }

        $checkpointPath = $this->checkpointPath($options);

        $set = $builder->build(Filesystem::tempFile('pincrack_wl_'));

        try {
            $workers = $this->resolveWorkers($options, $console, $targets[0], $cpuCount);
            $this->printHeader($options, $console, $set, $workers, $cpuCount, $checkpointPath, count($targets));

            $recovered = 0;
            $incomplete = false;
            /** @var list<array<string, mixed>> $report */
            $report = [];

            // --max-hours is a budget for the whole invocation, not per hash.
            // It used to be passed to each target in turn, so ten hashes with
            // --max-hours 8 could run for eighty hours.
            $budgetSeconds = (int) ($options->maxHours * 3600);
            $deadline = $budgetSeconds > 0 ? microtime(true) + $budgetSeconds : 0.0;

            foreach ($targets as $target) {
                $info = HashIdentifier::identify($target->hash);

                if ($deadline > 0.0 && microtime(true) >= $deadline) {
                    $console->out(sprintf(
                        "[%s] skipped - the --max-hours budget is spent\n\n",
                        $target->label
                    ));
                    $incomplete = true;
                    $report[] = [
                        'label' => $target->label,
                        'algorithm' => $info['label'],
                        'skipped' => true,
                        'stopped' => 'time budget spent',
                    ];
                    continue;
                }

                if (!VerifierFactory::isUsable($target->hash, $options->algo)) {
                    $console->out(sprintf(
                        "[%s] %s - skipped (pass --algo to force one)\n\n",
                        $target->label,
                        $info['label']
                    ));
                    $report[] = [
                        'label' => $target->label,
                        'algorithm' => $info['label'],
                        'skipped' => true,
                    ];
                    continue;
                }

                $console->info(sprintf("[%s] %s\n", $target->label, $info['label']));

                $checkpoint = (count($targets) === 1 && $checkpointPath !== null)
                    ? new Checkpoint(
                        $checkpointPath,
                        Checkpoint::signature($target->hash, $set->label, $set->count)
                    )
                    : null;

                if ($checkpoint !== null) {
                    $this->prepareCheckpoint($checkpoint, $options, $console);
                }

                $crackOptions = new CrackOptions(
                    workers: $workers,
                    priority: $options->effectivePriority(),
                    checkpoint: $checkpoint,
                    maxSeconds: $deadline > 0.0 ? max(1, (int) ceil($deadline - microtime(true))) : 0,
                    guardPercent: $options->guard,
                    cooldownSeconds: $options->cooldown,
                    verifierOptions: $options->verifierOptions()
                );

                $result = ($workers > 1 && function_exists('proc_open'))
                    ? (new ParallelCracker($this->entryPoint, $console))->crack($set, $target->hash, $crackOptions)
                    : (new InlineCracker($console))->crack($set, $target->hash, $crackOptions);

                if ($result->found()) {
                    $recovered++;
                }
                if (!$result->complete && !$result->found()) {
                    $incomplete = true;
                }

                $this->printResult($options, $console, $set, $target, $result, $checkpoint !== null);

                $report[] = [
                    'label' => $target->label,
                    'algorithm' => $info['label'],
                    'skipped' => false,
                ] + $result->toArray();

                if ($result->found() && $options->out !== null) {
                    @file_put_contents(
                        $options->out,
                        $target->label . ':' . (string) $result->secret . "\n",
                        FILE_APPEND
                    );
                }
            }

            if (!$options->json) {
                $console->info(str_repeat('-', 64) . "\n");
                $console->info(sprintf(
                    "done: %d of %d hashes recovered\n",
                    $recovered,
                    count($targets)
                ));
            } else {
                fwrite(STDOUT, json_encode([
                    'tool' => Version::NAME,
                    'version' => Version::NUMBER,
                    'source' => $set->label,
                    'candidates' => $set->count,
                    'workers' => $workers,
                    'recovered' => $recovered,
                    'targets' => $report,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }

            if ($recovered > 0) {
                return self::EXIT_RECOVERED;
            }

            return $incomplete ? self::EXIT_INCOMPLETE : self::EXIT_NOT_FOUND;
        } finally {
            $set->cleanup();
        }
    }

    private function candidateBuilder(Options $options): CandidateBuilder
    {
        if ($options->mask !== null) {
            $mask = new Mask($options->mask);

            return new CandidateBuilder(
                mode: CandidateBuilder::MODE_MASK,
                digits: $mask->length(),
                mask: $mask,
                include: $options->include,
                maxCandidates: $options->maxCandidates
            );
        }

        if ($options->full) {
            return new CandidateBuilder(
                mode: CandidateBuilder::MODE_FULL,
                digits: $options->digits,
                include: $options->include,
                maxCandidates: $options->maxCandidates
            );
        }

        if ($options->wordlist !== null) {
            return new CandidateBuilder(
                mode: CandidateBuilder::MODE_FILE,
                digits: $options->digits,
                wordlistPath: $options->wordlist,
                include: $options->include,
                maxCandidates: $options->maxCandidates
            );
        }

        return new CandidateBuilder(
            mode: CandidateBuilder::MODE_WORDLIST,
            digits: $options->digits,
            withDates: $options->dates,
            include: $options->include,
            maxCandidates: $options->maxCandidates
        );
    }

    private function checkpointPath(Options $options): ?string
    {
        if ($options->noCheckpoint) {
            return null;
        }
        if ($options->checkpoint !== null) {
            return $options->checkpoint;
        }

        return ($options->max || $options->full) ? self::DEFAULT_CHECKPOINT : null;
    }

    /**
     * How many workers to run when the operator did not say.
     *
     * Measured on a 12-thread hybrid CPU (2 P-cores + 8 E-cores), bcrypt
     * cost 12: 10 workers reached 15.81 H/s where 12 reached 14.02 H/s - the
     * old default of one worker per logical processor was measurably the worse
     * choice. Leaving two threads free is faster *and* keeps the machine
     * usable.
     */
    public static function defaultWorkers(int $cpuCount): int
    {
        return max(1, $cpuCount - 2);
    }

    private function resolveWorkers(Options $options, Console $console, Target $first, int $cpuCount): int
    {
        if ($options->workers !== null) {
            return $options->workers;
        }

        if ($options->max && VerifierFactory::isUsable($first->hash, $options->algo)) {
            $console->info("  --max: looking for the best worker count\n");

            return (new Benchmark($this->entryPoint, $console))->autoTuneWorkers(
                $first->hash,
                $cpuCount,
                $options->verifierOptions()
            );
        }

        if (HashIdentifier::identify($first->hash)['family'] === HashIdentifier::FAMILY_DIGEST) {
            $workers = max(1, min(self::DIGEST_WORKER_CAP, $cpuCount));
            $console->info(sprintf(
                "  note: raw digests gain nothing from many workers, using %d (override with --workers)\n",
                $workers
            ));

            return $workers;
        }

        return self::defaultWorkers($cpuCount);
    }

    /**
     * Decide what to do about an existing checkpoint file.
     *
     * A checkpoint that belongs to another job is somebody's unfinished work.
     * It used to be rejected silently and then overwritten by the fresh run,
     * so a single mistyped flag destroyed hours of progress without a word.
     * With --resume we now refuse and leave the file alone.
     */
    private function prepareCheckpoint(Checkpoint $checkpoint, Options $options, Console $console): void
    {
        $status = $checkpoint->status();

        if ($status === Checkpoint::STATUS_MISMATCHED) {
            if ($options->resume) {
                throw new UsageException(sprintf(
                    'the checkpoint at %s belongs to a different job - another hash, candidate source '
                    . 'or candidate count - so there is nothing here to resume. It has been left '
                    . 'untouched: delete it, or pass --checkpoint with another path.',
                    $checkpoint->path()
                ));
            }

            $console->info(sprintf(
                "  checkpoint %s is from a different job and will be overwritten\n",
                $checkpoint->path()
            ));
            $console->info("  (pass --checkpoint with another path to keep it)\n\n");
            $checkpoint->discard();

            return;
        }

        if ($status === Checkpoint::STATUS_USABLE && !$options->resume) {
            $console->info(sprintf("  a resumable checkpoint exists: %s\n", $checkpoint->path()));
            $console->info("  pass --resume to continue it; without --resume it is overwritten now\n\n");
            $checkpoint->discard();

            return;
        }

        if ($status === Checkpoint::STATUS_MISSING && $options->resume) {
            $console->info("  --resume given, but there is no checkpoint yet; starting from the beginning\n\n");
        }
    }

    private function printHeader(
        Options $options,
        Console $console,
        CandidateSet $set,
        int $workers,
        int $cpuCount,
        ?string $checkpointPath,
        int $targetCount
    ): void {
        $console->info(Version::line() . " - offline secret recovery\n");
        $console->info(str_repeat('-', 64) . "\n");
        $console->info(sprintf("  candidates : %s  (%s)\n", Format::number($set->count), $set->label));
        $console->info(sprintf(
            "  list file  : %s%s\n",
            Format::bytes($set->sizeOnDisk()),
            $set->isIndexed() ? ', indexed (mixed lengths)' : ', fixed records'
        ));
        $console->info(sprintf("  workers    : %d of %d logical processors\n", $workers, $cpuCount));
        $console->info(sprintf(
            "  priority   : %s%s\n",
            $options->effectivePriority(),
            $options->effectivePriority() !== 'normal' ? '  (machine stays responsive)' : ''
        ));
        $console->info(sprintf("  checkpoint : %s\n", $checkpointPath ?? 'off'));

        if ($options->maxHours > 0) {
            $console->info(sprintf("  time limit : %.1f h\n", $options->maxHours));
        }
        if ($options->guard > 0) {
            $console->info(sprintf(
                "  guard      : pause %d s when the rate stays below %d%% of the peak\n",
                $options->cooldown,
                $options->guard
            ));
        }

        $console->info(sprintf(
            "  targets    : %d %s\n",
            $targetCount,
            $targetCount === 1 ? 'hash' : 'hashes'
        ));

        if ($options->include !== []) {
            $console->info(sprintf("  include    : %s\n", implode(', ', $options->include)));
            $console->info("               (front-loaded - the result no longer represents\n");
            $console->info("                a realistic dictionary attack)\n");
        }

        $console->info(str_repeat('-', 64) . "\n\n");
    }

    private function printResult(
        Options $options,
        Console $console,
        CandidateSet $set,
        Target $target,
        CrackResult $result,
        bool $hasCheckpoint
    ): void {
        if ($options->json) {
            return;
        }

        if ($result->found()) {
            $console->out(sprintf("  >> RECOVERED : %s\n", (string) $result->secret));
            if ($result->index >= 0) {
                $console->out(sprintf(
                    "     position  : candidate %s of %s\n",
                    Format::number($result->index + 1),
                    Format::number($set->count)
                ));
            }
            $console->out(sprintf("     tried     : %s candidates\n", Format::number($result->tried)));
            $console->out(sprintf("     time      : %s\n", Format::duration($result->seconds)));
        } elseif ($result->stopped !== null) {
            $console->out(sprintf("  STOPPED: %s\n", $result->stopped));
            $console->out(sprintf(
                "     progress  : %s / %s (%.1f%%)\n",
                Format::number($result->tried),
                Format::number($set->count),
                $result->tried / max($set->count, 1) * 100
            ));
            $console->out(sprintf("     time      : %s\n", Format::duration($result->seconds)));
            if ($hasCheckpoint) {
                $console->out("     continue  : add --resume to the same command\n");
            }
        } else {
            $console->out("  not found in this candidate list\n");
            $console->out(sprintf(
                "     tried     : %s candidates in %s\n",
                Format::number($result->tried),
                Format::duration($result->seconds)
            ));
            if (!$options->full && $options->mask === null) {
                $console->out("     try       : --dates, or --full for the whole keyspace\n");
            }
        }

        if ($result->cooldowns > 0) {
            $console->info(sprintf("     cooldowns : %d (throughput guard fired)\n", $result->cooldowns));
        }
        if ($result->tried >= 100 && $result->seconds > 0) {
            $console->info(sprintf("     throughput: %.2f H/s\n", $result->rate()));
        }

        $console->out("\n");
    }

    private function benchmark(Options $options, Console $console, Target $target, int $cpuCount): int
    {
        if (!VerifierFactory::isUsable($target->hash, $options->algo)) {
            throw new UsageException('that hash cannot be used for a benchmark');
        }

        $info = HashIdentifier::identify($target->hash);
        $keyspace = $options->mask !== null
            ? (new Mask($options->mask))->count()
            : (int) (10 ** $options->digits);
        $workers = $options->workers ?? self::defaultWorkers($cpuCount);
        $benchmark = new Benchmark($this->entryPoint, $console);

        $out = static function (string $s): void {
            fwrite(STDOUT, $s);
        };

        $out("BENCHMARK\n" . str_repeat('-', 64) . "\n");
        $out(sprintf("  algorithm   : %s\n", $info['label']));
        $out(sprintf("  processors  : %d logical\n", $cpuCount));
        $out(sprintf("  keyspace    : %s\n", Format::number($keyspace)));
        $out(str_repeat('-', 64) . "\n");

        $console->status("  measuring one process...\r");
        $single = $benchmark->singleRate($target->hash, $options->verifierOptions());
        $console->clearLine(50);
        $out(sprintf(
            "  1 process   : %6.2f H/s  ->  %s for the full keyspace\n",
            $single,
            Format::duration($keyspace / max($single, 1e-9))
        ));

        if ($workers > 1) {
            $console->status("  measuring $workers workers (~20 s)...\r");
            $parallel = $benchmark->parallelRate($target->hash, $workers, 20, $options->verifierOptions());
            $console->clearLine(50);

            if ($parallel > 0) {
                $out(sprintf(
                    "  %-2d workers  : %6.2f H/s  ->  %s for the full keyspace   [BURST]\n",
                    $workers,
                    $parallel,
                    Format::duration($keyspace / $parallel)
                ));
                $out(sprintf(
                    "  efficiency  : %.0f%% of linear (%.2f H/s would be ideal)\n",
                    $parallel / max($single * $workers, 1e-9) * 100,
                    $single * $workers
                ));
                $sustained = $parallel * 0.4;
                $out(sprintf(
                    "  sustained (estimated 0.4x burst): %.2f H/s -> %s\n",
                    $sustained,
                    Format::duration($keyspace / max($sustained, 1e-9))
                ));
            }
        }

        $out("\n  The [BURST] line comes from a ~20 s sweep, so it still catches\n");
        $out("  turbo boost. A multi-hour sweep is MUCH slower: on one test\n");
        $out("  machine a 400 s sweep gave 8.67 H/s where the burst figure was\n");
        $out("  22.58 H/s. For numbers you put in a report, use the 'sustained'\n");
        $out("  line, or measure it yourself with --full run to completion.\n");
        $out("\n  GPUs are far faster: bcrypt cost 12 on one high-end GPU is on\n");
        $out("  the order of 7,000 H/s (~71 s on average for 6 digits). That GPU\n");
        $out("  figure is a published estimate, not something this tool measured.\n");

        return self::EXIT_RECOVERED;
    }
}
