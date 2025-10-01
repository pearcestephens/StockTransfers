<?php
declare(strict_types=1);
/**
 * lock_smoke_test.php
 * CLI/HTTP runnable smoke test for PackLockService + basic lifecycle.
 * Usage (CLI): php modules/transfers/stock/tools/lock_smoke_test.php <transferId> <userId>
 * If run via browser, provide ?transfer_id= & user_id=
 */

use Modules\Transfers\Stock\Services\PackLockService;

$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
    // Derive from script path (two levels up to public_html)
    $scriptDir = __DIR__;
    // Find 'public_html' ancestor
    $parts = explode(DIRECTORY_SEPARATOR, $scriptDir);
    $foundIndex = null;
    for ($i = count($parts)-1; $i >=0; $i--) {
        if ($parts[$i] === 'public_html') { $foundIndex = $i; break; }
    }
    if ($foundIndex !== null) {
        $DOCUMENT_ROOT = implode(DIRECTORY_SEPARATOR, array_slice($parts,0,$foundIndex+1));
    } else {
        $DOCUMENT_ROOT = realpath($scriptDir . '/../../..');
    }
}
// Guard again
if (!is_dir($DOCUMENT_ROOT)) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'Could not resolve DOCUMENT_ROOT','script'=>__FILE__]);
    exit(1);
}
require_once $DOCUMENT_ROOT . '/modules/transfers/_local_shims.php';
require_once $DOCUMENT_ROOT . '/app.php';

// Simple autoload shim if not already registered
spl_autoload_register(static function(string $class){
    if (str_starts_with($class, 'Modules\\')) {
        $base = $_SERVER['DOCUMENT_ROOT'] . '/modules';
        $rel = substr($class, 8);
        $relPath = str_replace('\\','/',$rel);
        $file = $base . '/' . strtolower($relPath) . '.php';
        if (is_file($file)) require_once $file;
    }
});

$cli = (php_sapi_name() === 'cli');
$transferId = $cli ? ($argv[1] ?? null) : ($_GET['transfer_id'] ?? null);
$userId = (int)($cli ? ($argv[2] ?? 0) : ($_GET['user_id'] ?? 0));
if(!$transferId || !$userId){
    $msg = "Usage: php modules/transfers/stock/tools/lock_smoke_test.php <transferId> <userId>\nOr via browser: .../lock_smoke_test.php?transfer_id=123&user_id=45";
    if($cli){ fwrite(STDERR, $msg."\n"); exit(1);} else { header('Content-Type: text/plain'); echo $msg; exit; }
}

header('Content-Type: application/json; charset=utf-8');

// Explicit require for PackLockService to avoid autoload case sensitivity issues
$svcPath = $DOCUMENT_ROOT . '/modules/transfers/stock/services/PackLockService.php';
if (is_file($svcPath)) {
    require_once $svcPath;
} else {
    echo json_encode(['ok'=>false,'error'=>'PackLockService file not found','path'=>$svcPath]);
    exit(1);
}

$svc = new PackLockService();

$result = [];

try {
    $result['initial_lock'] = $svc->getLock($transferId);
    $acq = $svc->acquire($transferId, $userId, 'smoke');
    $result['acquire'] = $acq;
    $hb = $svc->heartbeat($transferId, $userId);
    $result['heartbeat'] = $hb;
    $rel = $svc->releaseLock($transferId, $userId);
    $result['release'] = $rel;
    $result['final_lock'] = $svc->getLock($transferId);
    $result['ok'] = true;
} catch(Throwable $e){
    $result['ok'] = false;
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";