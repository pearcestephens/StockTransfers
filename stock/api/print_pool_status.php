<?php
declare(strict_types=1);

require __DIR__.'/_lib/validate.php';
cors_and_headers();
handle_options_preflight();
$headers = require_headers(false);

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($documentRoot === '' || !is_dir($documentRoot)) {
  fail('SERVER_CONFIG', 'DOCUMENT_ROOT not configured', []);
}

require_once $documentRoot . '/app.php';

spl_autoload_register(static function (string $class): void {
  $prefix = 'Modules\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
    return;
  }
  $rel = substr($class, strlen($prefix));
  $rel = str_replace(['\\', "\0"], ['/', ''], $rel);
  $rel = ltrim($rel, '/');

  $base = defined('MODULES_PATH') ? MODULES_PATH : dirname(__DIR__, 2);
  $candidates = [
    $base . '/' . $rel . '.php',
    $base . '/' . strtolower($rel) . '.php',
  ];

  $parts = explode('/', $rel);
  if (count($parts) > 1) {
    $file = array_pop($parts);
    $dir  = strtolower(implode('/', $parts));
    $candidates[] = $base . '/' . $dir . '/' . $file . '.php';
  }

  foreach ($candidates as $path) {
    if (is_string($path) && is_file($path)) {
      require_once $path;
      return;
    }
  }
});

use Modules\Transfers\Stock\Services\TransfersService;

try {
  $fromHeader = trim((string)($headers['x-from-outlet-id'] ?? $headers['x_from_outlet_id'] ?? ''));
  $outletId = trim((string)($_GET['from_outlet_id'] ?? $_GET['outlet_id'] ?? $fromHeader));
  if ($outletId === '') {
    fail('MISSING_PARAM', 'from_outlet_id required', []);
  }

  $svc = new TransfersService();
  $meta = $svc->getOutletMeta($outletId) ?? [];

  $onlineCount = (int)($meta['printers_online'] ?? 0);
  $totalCount  = (int)($meta['printers_total']  ?? 0);
  $online      = $totalCount <= 0 ? true : ($onlineCount > 0);

  ok([
    'ok'            => true,
    'online'        => $online,
    'online_count'  => max(0, $onlineCount),
    'total_count'   => max(0, $totalCount),
    'checked_at'    => gmdate('c'),
  ]);
} catch (Throwable $e) {
  fail('PRINT_POOL_ERROR', 'Unable to resolve print pool status', [
    'message' => $e->getMessage(),
  ]);
}
