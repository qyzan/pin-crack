<?php

declare(strict_types=1);

namespace PinCrack\Support;

/**
 * Human-readable formatting of durations, counts and progress.
 */
final class Format
{
    public static function duration(float $seconds): string
    {
        if ($seconds < 0 || !is_finite($seconds)) {
            return '?';
        }
        if ($seconds < 1) {
            return sprintf('%.0f ms', $seconds * 1000);
        }
        if ($seconds < 90) {
            return sprintf('%.0f s', $seconds);
        }
        if ($seconds < 5400) {
            return sprintf('%.1f min', $seconds / 60);
        }
        if ($seconds < 172800) {
            return sprintf('%.1f h', $seconds / 3600);
        }

        return sprintf('%.1f days', $seconds / 86400);
    }

    public static function number(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    public static function bytes(int $n): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $value = (float) $n;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return $i === 0
            ? sprintf('%d %s', (int) $value, $units[$i])
            : sprintf('%.1f %s', $value, $units[$i]);
    }

    /**
     * Wall-clock arrival time, not a duration. Returns '?' for values that
     * cannot be an ETA (negative, infinite, or more than a year out).
     */
    public static function eta(float $secondsLeft): string
    {
        if (!is_finite($secondsLeft) || $secondsLeft < 0 || $secondsLeft > 86400 * 365) {
            return '?';
        }

        return date('d M H:i', (int) (time() + $secondsLeft));
    }

    public static function progressBar(float $fraction, int $width = 28): string
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $fill = (int) round($fraction * $width);

        return '[' . str_repeat('#', $fill) . str_repeat('.', $width - $fill) . ']';
    }
}
