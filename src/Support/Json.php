<?php

declare(strict_types=1);

namespace PinCrack\Support;

/**
 * Crash-safe JSON files, used for inter-process state.
 */
final class Json
{
    /**
     * Write-then-rename, so a reader never observes a half-written file.
     *
     * @param array<string, mixed> $data
     */
    public static function writeAtomic(string $path, array $data): bool
    {
        $tmp = $path . '.tmp';
        $encoded = json_encode($data);
        if ($encoded === false) {
            return false;
        }
        if (@file_put_contents($tmp, $encoded) === false) {
            return false;
        }

        // rename() does not overwrite an existing file on Windows.
        if (DIRECTORY_SEPARATOR === '\\' && is_file($path)) {
            @unlink($path);
        }

        return @rename($tmp, $path);
    }

    /** @return array<string, mixed>|null */
    public static function readSafe(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
