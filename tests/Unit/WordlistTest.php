<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Candidates\Wordlist;
use PHPUnit\Framework\TestCase;

final class WordlistTest extends TestCase
{
    public function testCuratedListStartsWithTheMostCommonPin(): void
    {
        self::assertSame('123456', Wordlist::curated(6)[0]);
        self::assertSame('1234', Wordlist::curated(4)[0]);
    }

    public function testCuratedListIsEmptyForUnsupportedLengths(): void
    {
        self::assertSame([], Wordlist::curated(5));
    }

    public function testBuiltListIsDeduplicatedAndUniformLength(): void
    {
        $list = Wordlist::build(6);

        self::assertNotEmpty($list);
        self::assertSame(array_values(array_unique($list)), $list);

        foreach ($list as $candidate) {
            self::assertSame(6, strlen($candidate));
            self::assertTrue(ctype_digit($candidate));
        }
    }

    public function testExtraCandidatesComeFirst(): void
    {
        $list = Wordlist::build(6, false, ['424242']);

        self::assertSame('424242', $list[0]);
        self::assertSame(1, count(array_keys($list, '424242', true)));
    }

    public function testExtraCandidatesOfTheWrongLengthAreDropped(): void
    {
        $list = Wordlist::build(6, false, ['42', 'abcdef']);

        self::assertSame('123456', $list[0]);
    }

    public function testDatesAddCandidatesOnlyForSixDigits(): void
    {
        self::assertGreaterThan(count(Wordlist::build(6)), count(Wordlist::build(6, true)));
        self::assertSame(Wordlist::build(4), Wordlist::build(4, true));
    }

    public function testExpandTemplateCoversEveryAssignment(): void
    {
        $expanded = Wordlist::expandTemplate('abab');

        self::assertCount(100, $expanded);
        self::assertContains('1212', $expanded);
        self::assertNotContains('1234', $expanded);
    }

    public function testSequencesWrapAroundNine(): void
    {
        $sequences = Wordlist::sequences(4);

        self::assertContains('1234', $sequences);
        self::assertContains('4321', $sequences);
        self::assertContains('9012', $sequences);
    }

    public function testDatesCoverLeapDaysButNotImpossibleOnes(): void
    {
        $dates = Wordlist::dates();

        // 2024 was a leap year, so 29-02-24 exists as a ddmmyy date.
        self::assertContains('290224', $dates);

        // "009900" cannot be read as any of the three shapes: day 00, month
        // 00 and month 99 are all impossible.
        self::assertNotContains('009900', $dates);

        foreach (array_slice($dates, 0, 500) as $candidate) {
            self::assertSame(6, strlen($candidate));
            self::assertTrue(ctype_digit($candidate));
        }
    }

    public function testYearShapesOnlyExistForSixDigits(): void
    {
        self::assertSame([], Wordlist::years(4));
        self::assertContains('199090', Wordlist::years(6));
    }
}
