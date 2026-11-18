<?php

declare(strict_types=1);

namespace PinCrack\Support;

final class Priority
{
    public const BELOW = 'below';
    public const IDLE = 'idle';
    public const NORMAL = 'normal';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::BELOW, self::IDLE, self::NORMAL];
    }

    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }
}
