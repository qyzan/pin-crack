<?php

declare(strict_types=1);

namespace PinCrack;

final class Version
{
    public const NUMBER = '4.0.0';
    public const NAME = 'pin-crack';

    public static function line(): string
    {
        return sprintf('%s %s', self::NAME, self::NUMBER);
    }
}
