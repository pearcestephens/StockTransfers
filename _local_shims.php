<?php
declare(strict_types=1);

/**
 * Bridges the transfers module to your site's bootstrap (app.php).
 * - Reuses your existing session/auth
 * - Reuses your existing PDO (Core\DB, pdo(), or $GLOBALS['pdo'])
 * - Provides a MySQLi handle for the lock service when needed
 */

if (!defined('MODULES_PATH')) {
  // Public root/modules
  define('MODULES_PATH', rtrim($_SERVER['DOCUMENT_ROOT'] . '/modules', '/'));
}

/** Get a PDO handle shared from your app */
if (!function_exists('cis_pdo')) {
  function cis_pdo(): PDO {
    // 1) Core\DB adapter
    if (class_exists('\Core\DB') && method_exists('\Core\DB', 'instance')) {
      $pdo = \Core\DB::instance();
      if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
      }
    }
    // 2) Global helper from your functions/pdo.php (if it exists)
    if (function_exists('pdo')) {
      $pdo = pdo();
      if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
      }
    }
    // 3) Fallback global
    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
      $GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      return $GLOBALS['pdo'];
    }
    throw new RuntimeException('No PDO available: ensure /app.php is included first.');
  }
}

/** MySQLi handle needed by the lock service (only). */
if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof mysqli)) {
  $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? '127.0.0.1';
  $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? '';
  $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
  $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? '';
  $port = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? 3306);
  // Create once (quiet if it already existed and is valid)
  try {
    $GLOBALS['db'] = @new mysqli($host, $user, $pass, $name, $port);
    if ($GLOBALS['db'] instanceof mysqli && $GLOBALS['db']->connect_errno === 0) {
      $GLOBALS['db']->set_charset('utf8mb4');
    }
  } catch (Throwable $e) {
    // Pack locks will fail gracefully if MySQLi isn’t available; rest of the module still runs
  }
}
