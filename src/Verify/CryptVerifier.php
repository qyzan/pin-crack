<?php

declare(strict_types=1);

namespace PinCrack\Verify;

/**
 * Verifier for PHP crypt-format hashes: bcrypt and argon2.
 *
 * password_verify() parses the cost and salt out of the hash itself, so no
 * extra parameters are needed - which is also why these hashes are the ones
 * this tool handles best.
 */
final class CryptVerifier implements Verifier
{
    public function __construct(
        private readonly string $hash,
        private readonly string $label
    ) {
    }

    public function verify(string $candidate): bool
    {
        return password_verify($candidate, $this->hash);
    }

    public function describe(): string
    {
        return $this->label;
    }
}
