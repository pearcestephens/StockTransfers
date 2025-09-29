<?php
declare(strict_types=1);
/**
 * File: Autoload.php
 * Purpose: Register PSR-4 autoloading for the Stock transfer shared stack
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: PHP SPL autoload
 */

spl_autoload_register(static function(string $class): void {
    $prefix = 'Modules\\Transfers\\Stock\\Shared\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;
    $path = $baseDir . $relativePath;
    if (is_file($path)) {
        require_once $path;
    }
});
