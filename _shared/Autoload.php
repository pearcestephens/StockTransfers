<?php
declare(strict_types=1);

/**
 * Autoload.php
 *
 * Registers the PSR-4 style autoloader for the shared CIS library.
 *
 * @package CIS\Shared
 */

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'CIS\\Shared\\';
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
);
