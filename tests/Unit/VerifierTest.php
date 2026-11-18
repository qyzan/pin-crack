<?php

declare(strict_types=1);

namespace PinCrack\Tests\Unit;

use InvalidArgumentException;
use PinCrack\Verify\CryptVerifier;
use PinCrack\Verify\DigestVerifier;
use PinCrack\Verify\VerifierFactory;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    public function testBcryptVerifierMatchesOnlyTheRealSecret(): void
    {
        $hash = password_hash('481523', PASSWORD_BCRYPT, ['cost' => 4]);
        $verifier = VerifierFactory::create($hash);

        self::assertInstanceOf(CryptVerifier::class, $verifier);
        self::assertTrue($verifier->verify('481523'));
        self::assertFalse($verifier->verify('481524'));
        self::assertFalse($verifier->verify(''));
    }

    public function testUnsaltedDigestIsDetectedFromItsLength(): void
    {
        $verifier = VerifierFactory::create(md5('9182'));

        self::assertInstanceOf(DigestVerifier::class, $verifier);
        self::assertTrue($verifier->verify('9182'));
        self::assertFalse($verifier->verify('9183'));
    }

    public function testSaltTemplateIsApplied(): void
    {
        $verifier = new DigestVerifier('sha256', hash('sha256', 'pepper1234'), 'pepper', '{salt}{secret}');

        self::assertTrue($verifier->verify('1234'));
        self::assertFalse($verifier->verify('4321'));
    }

    public function testSuffixSaltTemplateIsApplied(): void
    {
        $verifier = new DigestVerifier('sha1', sha1('1234pepper'), 'pepper', '{secret}{salt}');

        self::assertTrue($verifier->verify('1234'));
    }

    public function testIterationsAreApplied(): void
    {
        $verifier = new DigestVerifier('md5', md5(md5(md5('4242'))), '', '{secret}', 3);

        self::assertTrue($verifier->verify('4242'));
        self::assertFalse($verifier->verify('2424'));
    }

    public function testUppercaseStoredDigestStillMatches(): void
    {
        $verifier = new DigestVerifier('md5', strtoupper(md5('1234')));

        self::assertTrue($verifier->verify('1234'));
    }

    public function testTemplateWithoutSecretIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DigestVerifier('md5', md5('x'), 'salt', '{salt}');
    }

    public function testUnknownAlgorithmIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DigestVerifier('definitely-not-a-hash', 'deadbeef');
    }

    public function testFactoryRefusesUnidentifiableHashes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VerifierFactory::create('not-a-hash-at-all');
    }

    public function testForcedAlgorithmOverridesDetection(): void
    {
        // 32 hex characters would be auto-detected as md5; --algo says sha256
        // truncation is not what we are looking at here, so the forced digest
        // simply fails to match - what matters is that no exception is thrown.
        $verifier = VerifierFactory::create(str_repeat('a', 32), ['algo' => 'sha256']);

        self::assertFalse($verifier->verify('1234'));
    }

    public function testIsUsable(): void
    {
        self::assertTrue(VerifierFactory::isUsable(password_hash('1', PASSWORD_BCRYPT, ['cost' => 4])));
        self::assertTrue(VerifierFactory::isUsable(md5('1')));
        self::assertFalse(VerifierFactory::isUsable('garbage'));
        self::assertTrue(VerifierFactory::isUsable('garbage', 'md5'));
        self::assertFalse(VerifierFactory::isUsable('garbage', 'nope'));
    }
}
