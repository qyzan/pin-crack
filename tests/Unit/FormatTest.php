<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Support\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testDurationScalesWithMagnitude(): void
    {
        self::assertSame('500 ms', Format::duration(0.5));
        self::assertSame('45 s', Format::duration(45));
        self::assertSame('10.0 min', Format::duration(600));
        self::assertSame('2.0 h', Format::duration(7200));
        self::assertSame('3.0 days', Format::duration(259200));
    }

    public function testDurationRejectsImpossibleValues(): void
    {
        self::assertSame('?', Format::duration(-1));
        self::assertSame('?', Format::duration(INF));
        self::assertSame('?', Format::duration(NAN));
    }

    public function testNumberUsesThousandsSeparators(): void
    {
        self::assertSame('1,000,000', Format::number(1_000_000));
        self::assertSame('0', Format::number(0));
    }

    public function testBytes(): void
    {
        self::assertSame('512 B', Format::bytes(512));
        self::assertSame('1.0 KiB', Format::bytes(1024));
        self::assertSame('7.0 MiB', Format::bytes(7 * 1024 * 1024));
    }

    public function testEtaRejectsValuesThatCannotBeAnEta(): void
    {
        self::assertSame('?', Format::eta(INF));
        self::assertSame('?', Format::eta(-5));
        self::assertSame('?', Format::eta(86400 * 400));
        self::assertMatchesRegularExpression('/^\d{2} \w{3} \d{2}:\d{2}$/', Format::eta(60));
    }

    public function testProgressBarIsClampedToItsWidth(): void
    {
        self::assertSame('[....]', Format::progressBar(0.0, 4));
        self::assertSame('[####]', Format::progressBar(1.0, 4));
        self::assertSame('[####]', Format::progressBar(5.0, 4));
        self::assertSame('[....]', Format::progressBar(-1.0, 4));
        self::assertSame('[##..]', Format::progressBar(0.5, 4));
    }
}
