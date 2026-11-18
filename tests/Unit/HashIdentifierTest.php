<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use PinCrack\Verify\HashIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HashIdentifierTest extends TestCase
{
    public function testBcryptIsIdentifiedWithItsCost(): void
    {
        $hash = password_hash('123456', PASSWORD_BCRYPT, ['cost' => 4]);
        $info = HashIdentifier::identify($hash);

        self::assertSame(HashIdentifier::FAMILY_BCRYPT, $info['family']);
        self::assertSame(4, $info['cost']);
        self::assertTrue($info['supported']);
        self::assertStringContainsString('cost 4', $info['label']);
    }

    public function testBcryptVariantsAreAccepted(): void
    {
        $body = str_repeat('a', 53);

        foreach (['$2$04$', '$2a$10$', '$2b$12$', '$2x$08$', '$2y$12$'] as $prefix) {
            $info = HashIdentifier::identify($prefix . $body);
            self::assertSame(
                HashIdentifier::FAMILY_BCRYPT,
                $info['family'],
                "prefix $prefix should be recognised"
            );
        }
    }

    public function testTruncatedBcryptIsNotAccepted(): void
    {
        $info = HashIdentifier::identify('$2y$12$tooshort');

        self::assertSame(HashIdentifier::FAMILY_UNKNOWN, $info['family']);
        self::assertFalse($info['supported']);
    }

    public function testArgon2IsIdentifiedWithItsParameters(): void
    {
        $hash = '$argon2id$v=19$m=1024,t=1,p=1$c29tZXNhbHQ$c29tZWhhc2g';
        $info = HashIdentifier::identify($hash);

        self::assertSame(HashIdentifier::FAMILY_ARGON2, $info['family']);
        self::assertSame('argon2id', $info['algo']);
        self::assertSame(1, $info['cost']);
        self::assertStringContainsString('m=1024', $info['label']);
    }

    #[DataProvider('digestProvider')]
    public function testBareDigestsAreIdentifiedByLength(string $algo): void
    {
        $info = HashIdentifier::identify(hash($algo, '123456'));

        self::assertSame(HashIdentifier::FAMILY_DIGEST, $info['family']);
        self::assertSame($algo, $info['algo']);
        self::assertTrue($info['supported']);
    }

    /** @return iterable<string, array{string}> */
    public static function digestProvider(): iterable
    {
        yield 'md5' => ['md5'];
        yield 'sha1' => ['sha1'];
        yield 'sha256' => ['sha256'];
        yield 'sha512' => ['sha512'];
    }

    public function testGarbageIsReportedAsUnknown(): void
    {
        foreach (['', 'not-a-hash', 'zzzz', '12345'] as $input) {
            $info = HashIdentifier::identify($input);
            self::assertSame(HashIdentifier::FAMILY_UNKNOWN, $info['family']);
            self::assertFalse($info['supported']);
        }
    }
}
