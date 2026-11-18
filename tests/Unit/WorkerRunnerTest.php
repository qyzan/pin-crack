<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cracking\WorkerJob;
use PinCrack\Cracking\WorkerRunner;
use PinCrack\Support\Json;
use PHPUnit\Framework\TestCase;

final class WorkerRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testJobRoundTripsEveryControlFile(): void
    {
        $job = new WorkerJob(
            candidatePath: 'c.txt',
            recordLength: 7,
            indexPath: 'c.txt.idx',
            from: 10,
            to: 20,
            statusPath: 's.json',
            stopPath: 'STOP',
            hash: '$2y$04$x',
            verifierOptions: ['algo' => 'bcrypt'],
            pausePath: 'PAUSE',
            heartbeatPath: 'HEARTBEAT',
            heartbeatTimeout: 15
        );

        $path = $this->temp('job');
        $job->writeTo($path);
        $read = WorkerJob::readFrom($path);

        self::assertSame('c.txt.idx', $read->indexPath);
        self::assertSame(10, $read->from);
        self::assertSame(20, $read->to);
        self::assertSame('PAUSE', $read->pausePath);
        self::assertSame('HEARTBEAT', $read->heartbeatPath);
        self::assertSame(15, $read->heartbeatTimeout);
        self::assertSame(['algo' => 'bcrypt'], $read->verifierOptions);
    }

    public function testWorkerFinishesItsRangeAndReportsDone(): void
    {
        $status = $this->runWorker(120, heartbeatAgeSeconds: 0);

        self::assertTrue($status['done']);
        self::assertNull($status['found']);
        self::assertSame(120, $status['pos']);
        self::assertSame(120, $status['tried']);
    }

    public function testWorkerReportsTheHitWithItsIndex(): void
    {
        // Candidate 40 of the generated range is the secret.
        $status = $this->runWorker(120, heartbeatAgeSeconds: 0, secretIndex: 40);

        self::assertTrue($status['done']);
        self::assertSame('000040', $status['found']);
        self::assertSame(40, $status['index']);
    }

    /**
     * The orphan fix: with the parent's heartbeat stale, a worker must stop
     * instead of hashing its whole range with nobody left to collect the
     * result. Before this, killing the parent left every worker running -
     * measured: 4 of 4 still burning CPU four seconds later.
     */
    public function testWorkerStopsWhenTheParentHeartbeatIsStale(): void
    {
        $status = $this->runWorker(4000, heartbeatAgeSeconds: 120);

        self::assertFalse($status['done'], 'the range must not be reported as finished');
        self::assertTrue($status['stopped']);
        self::assertSame('parent gone', $status['note']);
        self::assertLessThan(4000, $status['pos'], 'it should have given up early');
    }

    public function testWorkerStopsWhenTheHeartbeatFileIsGoneAltogether(): void
    {
        $status = $this->runWorker(4000, heartbeatAgeSeconds: null);

        self::assertTrue($status['stopped']);
        self::assertSame('parent gone', $status['note']);
    }

    public function testWorkerRunsUnsupervisedWhenNoHeartbeatIsConfigured(): void
    {
        $status = $this->runWorker(60, heartbeatAgeSeconds: null, superviseParent: false);

        self::assertTrue($status['done']);
        self::assertSame(60, $status['tried']);
    }

    public function testStopFileEndsTheRangeCleanly(): void
    {
        $status = $this->runWorker(4000, heartbeatAgeSeconds: 0, stopUpFront: true);

        self::assertFalse($status['done']);
        self::assertTrue($status['stopped']);
        self::assertLessThan(4000, $status['pos']);
    }

    /**
     * @return array{pos: int, tried: int, done: bool, found: string|null, index: int, stopped: bool, note: string|null}
     */
    private function runWorker(
        int $count,
        ?int $heartbeatAgeSeconds,
        ?int $secretIndex = null,
        bool $superviseParent = true,
        bool $stopUpFront = false
    ): array {
        $candidates = $this->temp('cand');
        $handle = fopen($candidates, 'wb');
        self::assertIsResource($handle);
        for ($i = 0; $i < $count; $i++) {
            fwrite($handle, sprintf('%06d', $i) . "\n");
        }
        fclose($handle);

        // Cost 4 keeps the suite fast while still being slow enough that the
        // worker reaches its 0.25 s control-file check before finishing a
        // long range.
        $secret = sprintf('%06d', $secretIndex ?? 999999);
        $hash = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]);

        $statusPath = $this->temp('status');
        $stopPath = $this->temp('stop');
        @unlink($stopPath);
        if ($stopUpFront) {
            touch($stopPath);
        }

        $heartbeatPath = '';
        if ($superviseParent) {
            $heartbeatPath = $this->temp('beat');
            if ($heartbeatAgeSeconds === null) {
                @unlink($heartbeatPath);
            } else {
                touch($heartbeatPath, time() - $heartbeatAgeSeconds);
            }
        }

        (new WorkerRunner())->run(new WorkerJob(
            candidatePath: $candidates,
            recordLength: 7,
            indexPath: null,
            from: 0,
            to: $count,
            statusPath: $statusPath,
            stopPath: $stopPath,
            hash: $hash,
            verifierOptions: [],
            pausePath: '',
            heartbeatPath: $heartbeatPath,
            heartbeatTimeout: 30
        ));

        $status = Json::readSafe($statusPath);
        self::assertIsArray($status);

        /** @var array{pos: int, tried: int, done: bool, found: string|null, index: int, stopped: bool, note: string|null} $status */
        return $status;
    }

    private function temp(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pcw_' . $prefix . '_');
        self::assertIsString($path);
        $this->paths[] = $path;
        $this->paths[] = $path . '.tmp';

        return $path;
    }
}
