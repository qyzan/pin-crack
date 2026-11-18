<?php

declare(strict_types=1);

namespace PinCrack\Verify;

/**
 * Recognises a hash string well enough to pick a verifier and to tell the
 * operator what they are actually attacking.
 */
final class HashIdentifier
{
    public const FAMILY_BCRYPT = 'bcrypt';
    public const FAMILY_ARGON2 = 'argon2';
    public const FAMILY_DIGEST = 'digest';
    public const FAMILY_UNKNOWN = 'unknown';

    /** Raw digest lengths that are unambiguous enough to auto-detect. */
    private const DIGEST_BY_LENGTH = [
        32 => 'md5',
        40 => 'sha1',
        64 => 'sha256',
        96 => 'sha384',
        128 => 'sha512',
    ];

    /**
     * @return array{
     *     family: string,
     *     algo: string|null,
     *     cost: int|null,
     *     params: array<string, string>,
     *     label: string,
     *     supported: bool
     * }
     */
    public static function identify(string $hash): array
    {
        $hash = trim($hash);

        if (preg_match('~^\$(2[abxy]?)\$(\d{2})\$[./A-Za-z0-9]{53}$~', $hash, $m) === 1) {
            return [
                'family' => self::FAMILY_BCRYPT,
                'algo' => $m[1],
                'cost' => (int) $m[2],
                'params' => ['cost' => $m[2]],
                'label' => sprintf('bcrypt $%s$ cost %d', $m[1], (int) $m[2]),
                'supported' => true,
            ];
        }

        if (preg_match('/^\$(argon2(?:i|d|id))\$v=\d+\$([^$]*)\$/', $hash, $m) === 1) {
            $params = [];
            foreach (explode(',', $m[2]) as $pair) {
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $params[$k] = $v;
                }
            }
            $supported = defined('PASSWORD_ARGON2I') || defined('PASSWORD_ARGON2ID');

            return [
                'family' => self::FAMILY_ARGON2,
                'algo' => $m[1],
                'cost' => isset($params['t']) ? (int) $params['t'] : null,
                'params' => $params,
                'label' => sprintf(
                    '%s (m=%s,t=%s,p=%s)',
                    $m[1],
                    $params['m'] ?? '?',
                    $params['t'] ?? '?',
                    $params['p'] ?? '?'
                ),
                'supported' => $supported,
            ];
        }

        $bare = strtolower($hash);
        if (ctype_xdigit($bare) && isset(self::DIGEST_BY_LENGTH[strlen($bare)])) {
            $algo = self::DIGEST_BY_LENGTH[strlen($bare)];

            return [
                'family' => self::FAMILY_DIGEST,
                'algo' => $algo,
                'cost' => null,
                'params' => [],
                'label' => sprintf('raw %s (unsalted, %d hex chars)', $algo, strlen($bare)),
                'supported' => in_array($algo, hash_algos(), true),
            ];
        }

        return [
            'family' => self::FAMILY_UNKNOWN,
            'algo' => null,
            'cost' => null,
            'params' => [],
            'label' => 'unrecognised hash format',
            'supported' => false,
        ];
    }

    /** @return list<string> */
    public static function digestAlgos(): array
    {
        return array_values(self::DIGEST_BY_LENGTH);
    }
}
