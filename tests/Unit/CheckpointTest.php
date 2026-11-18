<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cracking\Checkpoint;
use PHPUnit\Framework\TestCase;

final class CheckpointTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pcckpt_');
        self::assertIsString($path);
        $this->path = $path;
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testRoundTrip(): void
    {
        $signature = Checkpoint::signature('hash', 'source', 1000);
        $checkpoint = new Checkpoint($this->path, $signature);

        self::assertFalse($checkpoint->exists());
        self::assertSame(Checkpoint::STATUS_MISSING, $checkpoint->status());
        self::assertNull($checkpoint->load());

        $shards = [
            ['from' => 0, 'to' => 500, 'pos' => 123],
            ['from' => 500, 'to' => 1000, 'pos' => 777],
        ];
        $checkpoint->save($shards, 900, 42.5, null, -1);

        self::assertTrue($checkpoint->exists());
        self::assertSame(Checkpoint::STATUS_USABLE, $checkpoint->status());

        $state = $checkpoint->load();
        self::assertNotNull($state);
        self::assertSame($shards, $state['shards']);
        self::assertSame(900, $state['tried']);
        self::assertSame(42.5, $state['elapsed']);
        self::assertNull($state['found']);
    }

    public function testFoundSecretSurvivesAReload(): void
    {
        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 10));
        $checkpoint->save([['from' => 0, 'to' => 10, 'pos' => 10]], 7, 1.0, '424242', 6);

        $state = $checkpoint->load();
        self::assertNotNull($state);
        self::assertSame('424242', $state['found']);
        self::assertSame(6, $state['index']);
    }

    /**
     * The worker count is deliberately NOT part of the signature: shards
     * belong to the candidate list, not to how many processes chew through
     * them. Including it meant `--workers 8 --resume` threw away a sweep
     * started with `--workers 12`.
     */
    public function testWorkerCountDoesNotAffectTheSignature(): void
    {
        $a = Checkpoint::signature('hash', 'full 6-digit keyspace', 1000000);
        $b = Checkpoint::signature('hash', 'full 6-digit keyspace', 1000000);

        self::assertSame($a, $b);

        $written = new Checkpoint($this->path, $a);
        $written->save([['from' => 0, 'to' => 1000000, 'pos' => 400000]], 400000, 10.0, null, -1);

        // A later run with a different pool size must still resume.
        $reopened = new Checkpoint($this->path, $b);
        self::assertSame(Checkpoint::STATUS_USABLE, $reopened->status());
        $state = $reopened->load();
        self::assertNotNull($state);
        self::assertSame(400000, $state['tried']);
    }

    public function testACheckpointForAnotherJobIsReportedAsMismatched(): void
    {
        $written = new Checkpoint($this->path, Checkpoint::signature('hash-a', 'source', 1000));
        $written->save([['from' => 0, 'to' => 1000, 'pos' => 500]], 500, 10.0, null, -1);

        foreach ([
            'a different hash' => Checkpoint::signature('hash-b', 'source', 1000),
            'a different source' => Checkpoint::signature('hash-a', 'other', 1000),
            'a different candidate count' => Checkpoint::signature('hash-a', 'source', 2000),
        ] as $why => $signature) {
            $other = new Checkpoint($this->path, $signature);
            self::assertSame(Checkpoint::STATUS_MISMATCHED, $other->status(), $why);
            self::assertNull($other->load(), $why);
        }

        // ...and reading it must not have destroyed the original.
        self::assertTrue($written->exists());
        self::assertNotNull($written->load());
    }

    public function testCorruptFileIsMismatchedRatherThanMissing(): void
    {
        file_put_contents($this->path, '{ this is not json');

        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 1));

        // Mismatched, not missing: the operator gets told instead of having
        // an unreadable-but-present file silently replaced.
        self::assertSame(Checkpoint::STATUS_MISMATCHED, $checkpoint->status());
        self::assertNull($checkpoint->load());
    }

    public function testAnOldFormatIsMismatched(): void
    {
        file_put_contents($this->path, (string) json_encode([
            'ckpt_ver' => Checkpoint::VERSION - 1,
            'sig' => Checkpoint::signature('h', 's', 1),
            'shards' => [],
            'tried' => 5,
        ]));

        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 1));
        self::assertSame(Checkpoint::STATUS_MISMATCHED, $checkpoint->status());
    }

    public function testDiscardRemovesTheFile(): void
    {
        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 1));
        $checkpoint->save([], 0, 0.0, null, -1);
        self::assertTrue($checkpoint->exists());

        $checkpoint->discard();
        self::assertFalse($checkpoint->exists());
        self::assertSame(Checkpoint::STATUS_MISSING, $checkpoint->status());
    }
}
