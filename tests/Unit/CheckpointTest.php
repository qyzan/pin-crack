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
        $signature = Checkpoint::signature('hash', 'source', 1000, 4);
        $checkpoint = new Checkpoint($this->path, $signature);

        self::assertFalse($checkpoint->exists());
        self::assertNull($checkpoint->load());

        $shards = [
            ['from' => 0, 'to' => 500, 'pos' => 123],
            ['from' => 500, 'to' => 1000, 'pos' => 777],
        ];
        $checkpoint->save($shards, 900, 42.5, null, -1);

        self::assertTrue($checkpoint->exists());

        $state = $checkpoint->load();
        self::assertNotNull($state);
        self::assertSame($shards, $state['shards']);
        self::assertSame(900, $state['tried']);
        self::assertSame(42.5, $state['elapsed']);
        self::assertNull($state['found']);
    }

    public function testFoundSecretSurvivesAReload(): void
    {
        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 10, 1));
        $checkpoint->save([['from' => 0, 'to' => 10, 'pos' => 10]], 7, 1.0, '424242', 6);

        $state = $checkpoint->load();
        self::assertNotNull($state);
        self::assertSame('424242', $state['found']);
        self::assertSame(6, $state['index']);
    }

    public function testACheckpointForAnotherJobIsRejected(): void
    {
        $written = new Checkpoint($this->path, Checkpoint::signature('hash-a', 'source', 1000, 4));
        $written->save([['from' => 0, 'to' => 1000, 'pos' => 500]], 500, 10.0, null, -1);

        $otherHash = new Checkpoint($this->path, Checkpoint::signature('hash-b', 'source', 1000, 4));
        self::assertNull($otherHash->load(), 'a different hash must not resume');

        $otherSource = new Checkpoint($this->path, Checkpoint::signature('hash-a', 'other', 1000, 4));
        self::assertNull($otherSource->load(), 'a different candidate source must not resume');

        $otherLayout = new Checkpoint($this->path, Checkpoint::signature('hash-a', 'source', 1000, 8));
        self::assertNull($otherLayout->load(), 'a different shard layout must not resume');
    }

    public function testCorruptFileIsIgnoredRatherThanFatal(): void
    {
        file_put_contents($this->path, '{ this is not json');

        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 1, 1));
        self::assertNull($checkpoint->load());
    }

    public function testDiscardRemovesTheFile(): void
    {
        $checkpoint = new Checkpoint($this->path, Checkpoint::signature('h', 's', 1, 1));
        $checkpoint->save([], 0, 0.0, null, -1);
        self::assertTrue($checkpoint->exists());

        $checkpoint->discard();
        self::assertFalse($checkpoint->exists());
    }
}
