<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cli\Options;
use PinCrack\Cli\UsageException;
use PinCrack\Support\Priority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = Options::parse([]);

        self::assertSame(6, $options->digits);
        self::assertFalse($options->full);
        self::assertSame(50, $options->guard);
        self::assertSame(120, $options->cooldown);
        self::assertSame(Priority::NORMAL, $options->effectivePriority());
    }

    public function testMaxModeLowersPriorityByDefault(): void
    {
        self::assertSame(Priority::BELOW, Options::parse(['--max'])->effectivePriority());
        self::assertSame(Priority::IDLE, Options::parse(['--max', '--priority', 'idle'])->effectivePriority());
    }

    public function testValuesAreParsed(): void
    {
        $options = Options::parse([
            '--hash', 'abc',
            '--digits', '4',
            '--workers', '8',
            '--include', '1234, 4321 ,',
            '--max-hours', '1.5',
            '--guard', '70',
            '--algo', 'SHA256',
            '--salt', 'pepper',
            '--salt-format', '{salt}{secret}',
            '--iterations', '3',
        ]);

        self::assertSame('abc', $options->hash);
        self::assertSame(4, $options->digits);
        self::assertSame(8, $options->workers);
        self::assertSame(['1234', '4321'], $options->include);
        self::assertSame(1.5, $options->maxHours);
        self::assertSame(70, $options->guard);
        self::assertSame('sha256', $options->algo);
        self::assertSame(3, $options->iterations);
        self::assertSame(['algo' => 'sha256', 'salt' => 'pepper', 'salt_format' => '{salt}{secret}', 'iterations' => 3], $options->verifierOptions());
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(UsageException::class);

        Options::parse(['--nope']);
    }

    public function testMissingValueIsRejected(): void
    {
        $this->expectException(UsageException::class);

        Options::parse(['--hash']);
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('invalidCombinationProvider')]
    public function testInvalidCombinationsAreRejected(array $argv, string $expected): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessageMatches($expected);

        Options::parse($argv)->validate();
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidCombinationProvider(): iterable
    {
        yield 'digits too small' => [['--digits', '2'], '/--digits/'];
        yield 'digits too large' => [['--digits', '99'], '/--digits/'];
        yield 'bad priority' => [['--priority', 'turbo'], '/--priority/'];
        yield 'mask with full' => [['--mask', '?d?d', '--full'], '/--mask/'];
        yield 'missing wordlist' => [['--wordlist', '/definitely/not/here'], '/wordlist not found/'];
        yield 'missing hash file' => [['--hash-file', '/definitely/not/here'], '/hash file not found/'];
        yield 'salt without secret token' => [['--salt', 'x', '--salt-format', '{salt}'], '/salt-format/'];
        yield 'negative hours' => [['--max-hours', '-1'], '/max-hours/'];
        yield 'guard out of range' => [['--guard', '150'], '/--guard/'];
        yield 'worker without job' => [['--worker'], '/--job/'];
    }

    public function testCooldownHasAFloor(): void
    {
        self::assertSame(5, Options::parse(['--cooldown', '1'])->cooldown);
    }

    public function testWorkersHasAFloor(): void
    {
        self::assertSame(1, Options::parse(['--workers', '0'])->workers);
    }
}
