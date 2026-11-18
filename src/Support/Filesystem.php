<?php

declare(strict_types=1);

namespace PinCrack\Support;

use RuntimeException;

final class Filesystem
{
    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function devNull(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    public static function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('cannot create a temporary file in ' . sys_get_temp_dir());
        }

        return $path;
    }

    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . $prefix . getmypid() . '_' . bin2hex(random_bytes(3));

        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create directory $dir");
        }

        return $dir;
    }

    public static function freeSpace(string $path): ?int
    {
        $dir = is_dir($path) ? $path : dirname($path);
        $free = @disk_free_space($dir);

        return is_float($free) ? (int) $free : null;
    }
}
