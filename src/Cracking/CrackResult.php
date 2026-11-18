<?php

declare(strict_types=1);

namespace PinCrack\Cracking;

final class CrackResult
{
    /**
     * @param string|null $secret  the recovered value, null when nothing matched
     * @param int         $tried   candidates actually verified
     * @param float       $seconds wall-clock time, including any resumed runs
     * @param int         $index   position of the hit in the candidate list, -1 if none
     * @param string|null $stopped why the sweep ended early, null when it ran to its end
     * @param bool        $complete whether the whole candidate list was covered
     * @param int         $cooldowns number of throughput-guard pauses
     * @param bool        $resumed whether progress came from a checkpoint
     */
    public function __construct(
        public readonly ?string $secret,
        public readonly int $tried,
        public readonly float $seconds,
        public readonly int $index = -1,
        public readonly ?string $stopped = null,
        public readonly bool $complete = true,
        public readonly int $cooldowns = 0,
        public readonly bool $resumed = false
    ) {
    }

    public function found(): bool
    {
        return $this->secret !== null;
    }

    public function rate(): float
    {
        return $this->seconds > 0 ? $this->tried / $this->seconds : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'found' => $this->found(),
            'secret' => $this->secret,
            'tried' => $this->tried,
            'seconds' => round($this->seconds, 3),
            'rate_per_second' => round($this->rate(), 3),
            'index' => $this->index,
            'stopped' => $this->stopped,
            'complete' => $this->complete,
            'cooldowns' => $this->cooldowns,
            'resumed' => $this->resumed,
        ];
    }
}
