<?php

declare(strict_types=1);

namespace PinCrack\Support;

final class System
{
    public static function cpuCount(): int
    {
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && ctype_digit((string) $env)) {
            return max(1, (int) $env);
        }

        if (is_readable('/proc/cpuinfo')) {
            $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
            if ($count > 0) {
                return $count;
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            @exec('sysctl -n hw.ncpu 2>/dev/null', $out);
            if (isset($out[0]) && ctype_digit(trim($out[0]))) {
                return max(1, (int) trim($out[0]));
            }
        }

        return 4;
    }

    /**
     * Lower a worker's CPU priority.
     *
     * This is the single most useful safeguard for multi-hour sweeps: the OS
     * keeps preferring other work, so the machine stays responsive. On a truly
     * idle machine throughput is practically unaffected - there is nothing to
     * compete with.
     *
     * It is not a thermal control; heat is handled by the throughput guard.
     */
    public static function lowerPriority(int $pid, string $level): bool
    {
        if ($level === Priority::NORMAL || $pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $class = $level === Priority::IDLE ? 'Idle' : 'BelowNormal';
            $cmd = sprintf(
                'powershell -NoProfile -NonInteractive -Command '
                . '"try{(Get-Process -Id %d -ErrorAction Stop).PriorityClass=\'%s\'}catch{}"',
                $pid,
                $class
            );
            @exec($cmd . ' 2>&1', $out, $code);

            return $code === 0;
        }

        $nice = $level === Priority::IDLE ? 19 : 10;
        @exec(sprintf('renice -n %d -p %d 2>/dev/null', $nice, $pid), $out, $code);

        return $code === 0;
    }
}
