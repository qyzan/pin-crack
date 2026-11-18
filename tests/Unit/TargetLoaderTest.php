<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Cli\Options;
use PinCrack\Cli\TargetLoader;
use PHPUnit\Framework\TestCase;

final class TargetLoaderTest extends TestCase
{
    public function testSingleHashGetsADefaultLabel(): void
    {
        $targets = TargetLoader::load(Options::parse(['--hash', ' $2y$04$abc ']), false);

        self::assertCount(1, $targets);
        self::assertSame('hash-1', $targets[0]->label);
        self::assertSame('$2y$04$abc', $targets[0]->hash);
    }

    public function testHashFileLabelsCommentsAndBareLines(): void
    {
        $bcrypt = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]);
        $path = tempnam(sys_get_temp_dir(), 'pchash_');
        self::assertIsString($path);

        file_put_contents($path, implode("\n", [
            '# where these came from',
            'alice:' . $bcrypt,
            '',
            md5('1234'),
            ':' . $bcrypt,
        ]) . "\n");

        $targets = TargetLoader::load(Options::parse(['--hash-file', $path]), false);
        @unlink($path);

        self::assertCount(3, $targets);
        self::assertSame('alice', $targets[0]->label);
        self::assertSame($bcrypt, $targets[0]->hash);
        self::assertSame(md5('1234'), $targets[1]->hash);
        // An empty label falls back to the line number it came from.
        self::assertMatchesRegularExpression('/^line-\d+$/', $targets[2]->label);
    }

    public function testCryptHashesWithColonLabelsSplitOnTheRightColon(): void
    {
        $hash = '$argon2id$v=19$m=1024,t=1,p=1$c29tZXNhbHQ$c29tZWhhc2g';
        $target = TargetLoader::parseLine("user:42:$hash", 1);

        self::assertNotNull($target);
        self::assertSame('user:42', $target->label);
        self::assertSame($hash, $target->hash);
    }

    public function testCommentsAndBlankLinesAreSkipped(): void
    {
        self::assertNull(TargetLoader::parseLine('   ', 1));
        self::assertNull(TargetLoader::parseLine('# note', 1));
    }

    public function testBothSourcesAreCombined(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pchash_');
        self::assertIsString($path);
        file_put_contents($path, md5('x') . "\n");

        $targets = TargetLoader::load(
            Options::parse(['--hash', md5('y'), '--hash-file', $path]),
            false
        );
        @unlink($path);

        self::assertCount(2, $targets);
        self::assertSame(md5('y'), $targets[0]->hash);
        self::assertSame(md5('x'), $targets[1]->hash);
    }
}
