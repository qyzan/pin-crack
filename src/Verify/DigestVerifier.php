<?php

declare(strict_types=1);

namespace PinCrack\Verify;

use InvalidArgumentException;

/**
 * Verifier for raw digests (md5, sha1, sha256, ...), optionally salted.
 *
 * These are included because short PINs are still frequently stored this way,
 * and because it makes the point of the tool sharper: against a 6-digit
 * keyspace an unsalted md5 and a cost-12 bcrypt differ only in how long the
 * same guaranteed recovery takes.
 *
 * The salt template says how the stored string was built, e.g.
 * "{salt}{secret}", "{secret}{salt}" or "{secret}". Iteration counts and
 * application-specific peppers are out of scope.
 */
final class DigestVerifier implements Verifier
{
    public const DEFAULT_TEMPLATE = '{secret}';

    private string $expected;

    public function __construct(
        private readonly string $algo,
        string $hash,
        private readonly string $salt = '',
        private readonly string $template = self::DEFAULT_TEMPLATE,
        private readonly int $iterations = 1
    ) {
        if (!in_array($algo, hash_algos(), true)) {
            throw new InvalidArgumentException("unsupported digest algorithm: $algo");
        }
        if (!str_contains($template, '{secret}')) {
            throw new InvalidArgumentException('salt template must contain {secret}');
        }
        if ($iterations < 1) {
            throw new InvalidArgumentException('iterations must be >= 1');
        }

        $this->expected = strtolower(trim($hash));
    }

    public function verify(string $candidate): bool
    {
        $input = str_replace(
            ['{secret}', '{salt}'],
            [$candidate, $this->salt],
            $this->template
        );

        $digest = hash($this->algo, $input);
        for ($i = 1; $i < $this->iterations; $i++) {
            $digest = hash($this->algo, $digest);
        }

        return hash_equals($this->expected, $digest);
    }

    public function describe(): string
    {
        $parts = ['raw ' . $this->algo];
        if ($this->salt !== '') {
            $parts[] = 'salted (' . $this->template . ')';
        }
        if ($this->iterations > 1) {
            $parts[] = $this->iterations . ' iterations';
        }

        return implode(', ', $parts);
    }
}
