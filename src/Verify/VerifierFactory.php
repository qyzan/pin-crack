<?php

declare(strict_types=1);

namespace PinCrack\Verify;

use InvalidArgumentException;

/**
 * Builds the right verifier for a target hash.
 *
 * Auto-detection handles the common cases; --algo, --salt and --salt-format
 * are there for the ones no hash string can describe on its own.
 */
final class VerifierFactory
{
    /**
     * @param array{algo?: string|null, salt?: string, salt_format?: string, iterations?: int} $options
     */
    public static function create(string $hash, array $options = []): Verifier
    {
        $hash = trim($hash);
        $forced = $options['algo'] ?? null;
        $info = HashIdentifier::identify($hash);

        if ($forced !== null && $forced !== '') {
            $forced = strtolower($forced);

            if ($forced === 'bcrypt' || $forced === 'argon2' || $forced === 'crypt') {
                return new CryptVerifier($hash, $info['label']);
            }

            return new DigestVerifier(
                $forced,
                $hash,
                $options['salt'] ?? '',
                $options['salt_format'] ?? DigestVerifier::DEFAULT_TEMPLATE,
                $options['iterations'] ?? 1
            );
        }

        return match ($info['family']) {
            HashIdentifier::FAMILY_BCRYPT,
            HashIdentifier::FAMILY_ARGON2 => new CryptVerifier($hash, $info['label']),
            HashIdentifier::FAMILY_DIGEST => new DigestVerifier(
                (string) $info['algo'],
                $hash,
                $options['salt'] ?? '',
                $options['salt_format'] ?? DigestVerifier::DEFAULT_TEMPLATE,
                $options['iterations'] ?? 1
            ),
            default => throw new InvalidArgumentException(
                'cannot identify hash format; pass --algo to say what it is'
            ),
        };
    }

    public static function isUsable(string $hash, ?string $forcedAlgo = null): bool
    {
        if ($forcedAlgo !== null && $forcedAlgo !== '') {
            $forced = strtolower($forcedAlgo);

            return in_array($forced, ['bcrypt', 'argon2', 'crypt'], true)
                || in_array($forced, hash_algos(), true);
        }

        return HashIdentifier::identify($hash)['supported'];
    }
}
