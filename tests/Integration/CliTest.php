<?php

declare(strict_types=1);

namespace PinCrack\Tests\Integration;

use PinCrack\Support\Filesystem;
use PinCrack\Support\Json;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end runs of the real binary, including the multi-process path.
 *
 * Every hash here is generated inside the test at bcrypt cost 4 or as a bare
 * digest, so the suite stays fast and no hash material is committed.
 */
final class CliTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/pin-crack';

    /**
     * @param list<string> $args
     * @return array{output: string, code: int}
     */
    private function runCli(array $args): array
    {
        $command = array_merge([PHP_BINARY, self::BIN], $args);
        $quoted = implode(' ', array_map('escapeshellarg', $command));

        $output = [];
        $code = 0;
        exec($quoted . ' 2>&1', $output, $code);

        return ['output' => implode("\n", $output), 'code' => $code];
    }

    public function testHelpAndVersion(): void
    {
        $help = $this->runCli(['--help']);
        self::assertSame(0, $help['code']);
        self::assertStringContainsString('USAGE', $help['output']);
        self::assertStringContainsString('authorized security testing only', $help['output']);

        $version = $this->runCli(['--version']);
        self::assertSame(0, $version['code']);
        self::assertMatchesRegularExpression('/^pin-crack \d+\.\d+\.\d+$/', trim($version['output']));
    }

    public function testUnknownOptionExitsWithUsageCode(): void
    {
        $result = $this->runCli(['--definitely-not-an-option']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown option', $result['output']);
    }

    public function testNoHashExitsWithUsageCode(): void
    {
        $result = $this->runCli(['--digits', '4', '--quiet']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('no hash given', $result['output']);
    }

    public function testSingleProcessRecovery(): void
    {
        $hash = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->runCli(['--hash', $hash, '--digits', '4', '--workers', '1', '--quiet']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('RECOVERED : 1234', $result['output']);
    }

    public function testParallelRecoveryOverTheFullKeyspace(): void
    {
        $hash = password_hash('8371', PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->runCli([
            '--hash', $hash,
            '--digits', '4',
            '--full',
            '--workers', '4',
            '--no-checkpoint',
            '--quiet',
        ]);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('RECOVERED : 8371', $result['output']);
        self::assertStringContainsString('candidate 8,372 of 10,000', $result['output']);
    }

    public function testNotFoundExitsWithOne(): void
    {
        $hash = password_hash('9182736455', PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->runCli(['--hash', $hash, '--digits', '4', '--workers', '2', '--quiet']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('not found', $result['output']);
    }

    public function testMaskModeWithARawDigest(): void
    {
        $result = $this->runCli([
            '--hash', md5('Ab42'),
            '--mask', '?u?l?d?d',
            '--workers', '2',
            '--quiet',
        ]);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('RECOVERED : Ab42', $result['output']);
    }

    public function testSaltedDigestWithATemplate(): void
    {
        $result = $this->runCli([
            '--hash', hash('sha256', 's4lt4711'),
            '--algo', 'sha256',
            '--salt', 's4lt',
            '--salt-format', '{salt}{secret}',
            '--digits', '4',
            '--full',
            '--no-checkpoint',
            '--workers', '2',
            '--quiet',
        ]);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('RECOVERED : 4711', $result['output']);
    }

    public function testJsonOutputIsMachineReadable(): void
    {
        $hash = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]);

        $result = $this->runCli(['--hash', $hash, '--digits', '4', '--workers', '2', '--json']);
        self::assertSame(0, $result['code']);

        $decoded = json_decode($result['output'], true);
        self::assertIsArray($decoded);
        self::assertSame('pin-crack', $decoded['tool']);
        self::assertSame(1, $decoded['recovered']);
        self::assertSame('1234', $decoded['targets'][0]['secret']);
        self::assertTrue($decoded['targets'][0]['found']);
        self::assertStringContainsString('bcrypt', $decoded['targets'][0]['algorithm']);
    }

    public function testMultipleHashesAndTheOutFile(): void
    {
        $hashFile = tempnam(sys_get_temp_dir(), 'pcint_');
        $outFile = tempnam(sys_get_temp_dir(), 'pcout_');
        self::assertIsString($hashFile);
        self::assertIsString($outFile);
        @unlink($outFile);

        file_put_contents($hashFile, implode("\n", [
            '# two accounts and one unusable value',
            'alice:' . password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]),
            'bob:' . password_hash('0000', PASSWORD_BCRYPT, ['cost' => 4]),
            'carol:nonsense',
        ]) . "\n");

        $result = $this->runCli([
            '--hash-file', $hashFile,
            '--digits', '4',
            '--workers', '2',
            '--out', $outFile,
            '--quiet',
        ]);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('unrecognised hash format', $result['output']);

        $recovered = file($outFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertSame(['alice:1234', 'bob:0000'], $recovered);

        @unlink($hashFile);
        @unlink($outFile);
    }

    public function testSaveWordlistWritesACandidateFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pcwl_');
        self::assertIsString($path);

        $result = $this->runCli(['--save-wordlist', $path, '--digits', '4']);
        self::assertSame(0, $result['code']);
        self::assertStringContainsString('wordlist written', $result['output']);

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertGreaterThan(100, count($lines));
        self::assertSame('1234', $lines[0]);

        @unlink($path);
    }

    public function testOversizedKeyspaceIsRefused(): void
    {
        $result = $this->runCli([
            '--hash', md5('x'),
            '--mask', '?d?d?d?d?d?d?d?d?d',
            '--quiet',
        ]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('max-candidates', $result['output']);
    }

    public function testTimeLimitStopsAndSavesProgress(): void
    {
        $checkpoint = tempnam(sys_get_temp_dir(), 'pcckpt_');
        self::assertIsString($checkpoint);
        @unlink($checkpoint);

        // A cost-8 hash is slow enough that a 1 second budget cannot finish
        // the keyspace, so the run must stop and leave resumable progress.
        $hash = password_hash('999999', PASSWORD_BCRYPT, ['cost' => 8]);

        $result = $this->runCli([
            '--hash', $hash,
            '--full',
            '--workers', '2',
            '--checkpoint', $checkpoint,
            '--max-hours', '0.0003',
            '--guard', '0',
            '--quiet',
        ]);

        self::assertSame(3, $result['code'], $result['output']);
        self::assertStringContainsString('STOPPED', $result['output']);
        self::assertFileExists($checkpoint);

        $state = json_decode((string) file_get_contents($checkpoint), true);
        self::assertIsArray($state);
        self::assertNotEmpty($state['shards']);
        self::assertNull($state['found']);

        @unlink($checkpoint);
    }

    /**
     * The worker count is no longer part of a checkpoint's identity, so a
     * sweep started with one pool size resumes with another. It used to be
     * rejected, and the rejected file was then overwritten.
     */
    public function testResumeWorksAfterChangingTheWorkerCount(): void
    {
        $checkpoint = tempnam(sys_get_temp_dir(), 'pcckpt_');
        self::assertIsString($checkpoint);
        @unlink($checkpoint);

        $hash = password_hash('999999', PASSWORD_BCRYPT, ['cost' => 8]);
        $common = ['--hash', $hash, '--full', '--checkpoint', $checkpoint,
                   '--max-hours', '0.0003', '--guard', '0'];

        $first = $this->runCli(array_merge($common, ['--workers', '6', '--quiet']));
        self::assertSame(3, $first['code'], $first['output']);

        $before = json_decode((string) file_get_contents($checkpoint), true);
        self::assertIsArray($before);
        self::assertGreaterThan(0, $before['tried']);

        $second = $this->runCli(array_merge($common, ['--workers', '3', '--resume']));
        self::assertSame(3, $second['code'], $second['output']);
        self::assertStringContainsString('resuming from checkpoint', $second['output']);

        $after = json_decode((string) file_get_contents($checkpoint), true);
        self::assertIsArray($after);
        self::assertGreaterThan(
            $before['tried'],
            $after['tried'],
            'progress must accumulate across the worker-count change'
        );

        @unlink($checkpoint);
    }

    /**
     * A checkpoint belonging to another job is somebody's unfinished work:
     * refuse, and leave the file exactly as it was.
     */
    public function testResumeRefusesAForeignCheckpointWithoutTouchingIt(): void
    {
        $checkpoint = tempnam(sys_get_temp_dir(), 'pcckpt_');
        self::assertIsString($checkpoint);
        @unlink($checkpoint);

        $jobA = password_hash('999999', PASSWORD_BCRYPT, ['cost' => 8]);
        $first = $this->runCli([
            '--hash', $jobA, '--full', '--workers', '2', '--checkpoint', $checkpoint,
            '--max-hours', '0.0003', '--guard', '0', '--quiet',
        ]);
        self::assertSame(3, $first['code'], $first['output']);

        $original = (string) file_get_contents($checkpoint);
        self::assertNotSame('', $original);

        $jobB = password_hash('888888', PASSWORD_BCRYPT, ['cost' => 8]);
        $second = $this->runCli([
            '--hash', $jobB, '--full', '--workers', '2', '--checkpoint', $checkpoint,
            '--resume', '--guard', '0', '--quiet',
        ]);

        self::assertSame(2, $second['code'], $second['output']);
        self::assertStringContainsString('belongs to a different job', $second['output']);
        self::assertSame(
            $original,
            (string) file_get_contents($checkpoint),
            'the foreign checkpoint must be left untouched'
        );

        @unlink($checkpoint);
    }

    public function testRawDigestsGetACappedWorkerCountWithAnExplanation(): void
    {
        $result = $this->runCli(['--hash', md5('1234'), '--digits', '4']);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('raw digests gain nothing from many workers', $result['output']);
        self::assertStringContainsString('RECOVERED : 1234', $result['output']);
    }

    public function testBadMaskIsAUsageErrorNotARuntimeError(): void
    {
        $result = $this->runCli(['--hash', md5('x'), '--mask', '?z', '--quiet']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown mask token', $result['output']);
    }

    /**
     * End-to-end proof that the throughput guard controls the workers.
     *
     * This is the test whose absence hid the original bug: the guard's
     * decision logic was unit-tested and correct, while the parent only ever
     * called sleep() on itself. The message said "pausing 120 s to cool down"
     * and all N workers kept hashing at 100%.
     *
     * Driving one worker through the real `--worker --job` entry point is the
     * only way to observe it, so that is what this does.
     */
    public function testPauseFileActuallyStopsAWorkerHashing(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcguard_' . getmypid() . '_' . bin2hex(random_bytes(3));
        self::assertTrue(@mkdir($dir, 0700, true) || is_dir($dir));

        $candidates = $dir . DIRECTORY_SEPARATOR . 'cand.txt';
        $handle = fopen($candidates, 'wb');
        self::assertIsResource($handle);
        for ($i = 0; $i < 20000; $i++) {
            fwrite($handle, sprintf('%06d', $i) . "\n");
        }
        fclose($handle);

        $status = $dir . DIRECTORY_SEPARATOR . 'w.json';
        $pause = $dir . DIRECTORY_SEPARATOR . 'PAUSE';
        $beat = $dir . DIRECTORY_SEPARATOR . 'HEARTBEAT';
        $job = $dir . DIRECTORY_SEPARATOR . 'job.json';
        touch($beat);

        Json::writeAtomic($job, [
            'candidate_path' => $candidates,
            'record_length' => 7,
            'index_path' => null,
            'from' => 0,
            'to' => 20000,
            'status_path' => $status,
            'stop_path' => $dir . DIRECTORY_SEPARATOR . 'STOP',
            'pause_path' => $pause,
            'heartbeat_path' => $beat,
            'heartbeat_timeout' => 30,
            'hash' => password_hash('not-in-the-list', PASSWORD_BCRYPT, ['cost' => 5]),
            'verifier' => [],
        ]);

        $worker = proc_open(
            [PHP_BINARY, self::BIN, '--worker', '--job', $job],
            [1 => ['file', Filesystem::devNull(), 'a'], 2 => ['file', Filesystem::devNull(), 'a']],
            $pipes
        );
        self::assertIsResource($worker);

        try {
            usleep(2_000_000);
            self::assertGreaterThan(0, $this->triedSoFar($status), 'the worker should be hashing');

            touch($pause);
            usleep(1_500_000);
            $atPause = $this->triedSoFar($status);

            for ($i = 0; $i < 6; $i++) {
                usleep(500_000);
                touch($beat);
            }
            $afterWaiting = $this->triedSoFar($status);

            self::assertSame(
                $atPause,
                $afterWaiting,
                'a paused worker must not verify another candidate'
            );

            @unlink($pause);
            usleep(2_500_000);
            touch($beat);

            self::assertGreaterThan(
                $afterWaiting,
                $this->triedSoFar($status),
                'the worker must resume once the pause is lifted'
            );
        } finally {
            @proc_terminate($worker);
            @proc_close($worker);
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function triedSoFar(string $statusPath): int
    {
        $status = Json::readSafe($statusPath);
        self::assertIsArray($status, 'the worker should have written a status file');

        return (int) ($status['tried'] ?? 0);
    }
}
