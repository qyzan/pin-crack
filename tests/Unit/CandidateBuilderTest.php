<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Candidates\CandidateBuilder;
use PinCrack\Candidates\CandidateSet;
use PinCrack\Candidates\Mask;
use PinCrack\Cracking\CandidateReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CandidateBuilderTest extends TestCase
{
    /** @var list<CandidateSet> */
    private array $sets = [];

    protected function tearDown(): void
    {
        foreach ($this->sets as $set) {
            $set->cleanup();
        }
        $this->sets = [];
    }

    public function testFullKeyspaceIsCompleteAndFixedLength(): void
    {
        $set = $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_FULL,
            digits: 3
        ));

        self::assertSame(1000, $set->count);
        self::assertSame(4, $set->recordLength);
        self::assertFalse($set->isIndexed());
        self::assertNull($set->indexPath);

        $lines = file($set->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1000, $lines);
        self::assertSame('000', $lines[0]);
        self::assertSame('999', $lines[999]);
    }

    public function testIncludedCandidatesComeFirstAndAreNotRepeated(): void
    {
        $set = $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_FULL,
            digits: 3,
            include: ['777', '777', '123']
        ));

        $lines = file($set->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        self::assertSame(1000, $set->count);
        self::assertSame('777', $lines[0]);
        self::assertSame('123', $lines[1]);
        self::assertSame(1, count(array_keys($lines, '777', true)));
    }

    public function testMaskModeUsesTheMaskLength(): void
    {
        $set = $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_MASK,
            mask: new Mask('19?d?d')
        ));

        self::assertSame(100, $set->count);
        self::assertSame(5, $set->recordLength);
        self::assertStringContainsString('19?d?d', $set->label);
    }

    public function testWordlistFileWithMixedLengthsBuildsAnIndex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pctest_');
        self::assertIsString($path);
        file_put_contents($path, "12\nabcdef\n7\nlonger-candidate\n");

        $set = $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_FILE,
            wordlistPath: $path
        ));

        self::assertSame(4, $set->count);
        self::assertTrue($set->isIndexed());
        self::assertSame(0, $set->recordLength);
        self::assertIsString($set->indexPath);
        self::assertFileExists($set->indexPath);

        // The index must let a reader jump straight to any record.
        $reader = CandidateReader::fromSet($set);
        $reader->seek(3);
        self::assertSame('longer-candidate', $reader->next());
        $reader->seek(1);
        self::assertSame('abcdef', $reader->next());
        $reader->close();

        @unlink($path);
    }

    public function testUniformLengthWordlistStaysOnTheFixedRecordPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pctest_');
        self::assertIsString($path);
        file_put_contents($path, "1111\n2222\n3333\n");

        $set = $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_FILE,
            wordlistPath: $path
        ));

        self::assertSame(3, $set->count);
        self::assertFalse($set->isIndexed());
        self::assertSame(5, $set->recordLength);

        @unlink($path);
    }

    public function testBuiltinWordlistIsUsedByDefault(): void
    {
        $set = $this->build(new CandidateBuilder(digits: 4));

        self::assertGreaterThan(100, $set->count);
        self::assertSame(5, $set->recordLength);
        self::assertStringContainsString('built-in', $set->label);
    }

    public function testKeyspaceLimitIsEnforcedBeforeAnythingIsWritten(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/max-candidates/');

        $this->build(new CandidateBuilder(
            mode: CandidateBuilder::MODE_FULL,
            digits: 8,
            maxCandidates: 1000
        ));
    }

    public function testEmptySourceIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pctest_');
        self::assertIsString($path);
        file_put_contents($path, "\n\n");

        $this->expectException(RuntimeException::class);

        try {
            $this->build(new CandidateBuilder(
                mode: CandidateBuilder::MODE_FILE,
                wordlistPath: $path
            ));
        } finally {
            @unlink($path);
        }
    }

    private function build(CandidateBuilder $builder): CandidateSet
    {
        $path = tempnam(sys_get_temp_dir(), 'pcset_');
        self::assertIsString($path);

        $set = $builder->build($path);
        $this->sets[] = $set;

        return $set;
    }
}
