<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

use PinCrack\Support\Priority;

final class CrackOptions
{
    /**
     * @param array<string, mixed> $verifierOptions passed through to VerifierFactory
     */
    public function __construct(
        public readonly int $workers = 1,
        public readonly string $priority = Priority::NORMAL,
        public readonly ?Checkpoint $checkpoint = null,
        public readonly int $maxSeconds = 0,
        public readonly int $guardPercent = 0,
        public readonly int $cooldownSeconds = 120,
        public readonly array $verifierOptions = []
    ) {
    }

    public function withWorkers(int $workers): self
    {
        return new self(
            $workers,
            $this->priority,
            $this->checkpoint,
            $this->maxSeconds,
            $this->guardPercent,
            $this->cooldownSeconds,
            $this->verifierOptions
        );
    }
}
