<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use InvalidArgumentException;
use PinCrack\Candidates\Mask;
use PHPUnit\Framework\TestCase;

final class MaskTest extends TestCase
{
    public function testDigitMaskCountsAndLength(): void
    {
        $mask = Mask::digits(6);

        self::assertSame(1_000_000, $mask->count());
        self::assertSame(6, $mask->length());
        self::assertSame('000000', $mask->at(0));
        self::assertSame('999999', $mask->at(999_999));
    }

    public function testLiteralsArePreserved(): void
    {
        $mask = new Mask('19?d?d');

        self::assertSame(100, $mask->count());
        self::assertSame('1900', $mask->at(0));
        self::assertSame('1999', $mask->at(99));
    }

    public function testDoubleQuestionMarkIsALiteralQuestionMark(): void
    {
        $mask = new Mask('??x?d');

        self::assertSame(10, $mask->count());
        self::assertSame('?x0', $mask->at(0));
        self::assertSame(3, $mask->length());
    }

    public function testMixedCharsetsMultiply(): void
    {
        $mask = new Mask('?u?l?d');

        self::assertSame(26 * 26 * 10, $mask->count());
        self::assertSame('Aa0', $mask->at(0));
    }

    public function testAllTokenCoversLowerUpperDigitsAndSymbols(): void
    {
        $mask = new Mask('?a');

        self::assertSame(95, $mask->count());
    }

    public function testEveryCandidateIsUniqueAndFixedLength(): void
    {
        $mask = new Mask('?h?h');
        $seen = [];

        foreach ($mask->each() as $candidate) {
            self::assertSame(2, strlen($candidate));
            $seen[$candidate] = true;
        }

        self::assertCount($mask->count(), $seen);
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Mask('?z');
    }

    public function testDanglingQuestionMarkIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Mask('?d?');
    }

    public function testIndexOutOfRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Mask('?d'))->at(10);
    }
}
