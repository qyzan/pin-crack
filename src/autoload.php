<?php

/**
 * Dependency-free PSR-4 autoloader.
 *
 * The tool is meant to be usable straight from a `git clone` on a machine with
 * nothing but PHP installed, so it must not require `composer install`.
 * Composer's autoloader is preferred when present - see bin/pin-crack.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'PinCrack\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
