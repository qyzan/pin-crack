<?php

declare(strict_types=1);

namespace PinCrack\Cli;

final class Target
{
    public function __construct(
        public readonly string $label,
        public readonly string $hash
    ) {
    }
}
