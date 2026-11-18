<?php

declare(strict_types=1);

namespace PinCrack\Tests\Integration;

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
}
