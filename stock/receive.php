<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';

spl_autoload_register(static function(string $class): void {
  $prefix = 'Modules\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
  $rel = substr($class, strlen($prefix));
  $relSlashes = str_replace('\\', '/', $rel);

  $p1 = MODULES_PATH . '/' . $relSlashes . '.php';
  if (is_file($p1)) { require_once $p1; return; }

  $parts = explode('/', $relSlashes);
  $file  = array_pop($parts);
  $dir   = strtolower(implode('/', $parts));
  $p2 = MODULES_PATH . '/' . $dir . '/' . $file . '.php';
  if (is_file($p2)) { require_once $p2; return; }

  $p3 = MODULES_PATH . '/' . strtolower($relSlashes) . '.php';
  if (is_file($p3)) { require_once $p3; return; }
});

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/JsonGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';

use Modules\Transfers\Stock\Services\ReceiptService;
use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

/**
 * Simple User wrapper (object-oriented).
 */
class User {
  public int $id;

  public function __construct(int $id) {
    $this->id = $id;
  }

  public static function requireLoggedIn(): self {
    if (empty($_SESSION['userID'])) {
      http_response_code(302);
      header('Location:/login.php');
      exit;
    }
    return new self((int)$_SESSION['userID']);
  }
}

// --- Require a logged-in user ---
$user = User::requireLoggedIn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  HttpGuard::sameOriginOr([]);
  HttpGuard::rateLimit('receive_save:' . (int)$user->id, 60, 60);
  JsonGuard::csrfCheckOptional();
  JsonGuard::idempotencyGuard();

  $payload = JsonGuard::readJson();
  try {
    $tid = (int)($_GET['transfer'] ?? 0);
    if ($tid <= 0) {
      ApiResponder::json(['success'=>false,'error'=>'Missing ?transfer id'],400);
    }
    if (!AccessPolicy::canAccessTransfer($user->id, $tid)) {
      ApiResponder::json(['success'=>false,'error'=>'Forbidden'],403);
    }

    $svc = new ReceiptService();
    $res = $svc->saveReceive($tid, $payload, $user->id);
    ApiResponder::json($res,200);
  } catch (\Throwable $e) {
    ApiResponder::json(['success'=>false,'error'=>$e->getMessage()],500);
  }
  exit;
}

$tid = (int)($_GET['transfer'] ?? 0);
if ($tid <= 0) {
  http_response_code(400);
  echo 'Missing ?transfer id';
  exit;
}
if (!AccessPolicy::canAccessTransfer($user->id, $tid)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$txSvc = new TransfersService();
$transfer = $txSvc->getTransfer($tid);

include $_SERVER['DOCUMENT_ROOT'].'/assets/template/html-header.php';
include $_SERVER['DOCUMENT_ROOT'].'/assets/template/header.php';
?>
<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <link rel="stylesheet" href="<?php echo BASE_PATH;?>/assets/css/stock-transfers/transfers-common.css">
  <link rel="stylesheet" href="<?php echo BASE_PATH;?>/assets/css/stock-transfers/transfers-pack.css">
  <link rel="stylesheet" href="<?php echo BASE_PATH;?>/assets/css/stock-transfers/transfers-boxplanner.css">
  <link rel="stylesheet" href="<?php echo BASE_PATH;?>/assets/css/stock-transfers/transfers-receive.css">

  <div class="app-body">
    <?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/sidemenu.php'; ?>
    <main class="main">
      <ol class="breadcrumb">
        <li class="breadcrumb-item">Home</li>
        <li class="breadcrumb-item"><a href="/modules/transfers">Transfers</a></li>
        <li class="breadcrumb-item active">Receive #<?=htmlspecialchars((string)$tid)?></li>
      </ol>
      <div class="container-fluid">
        <?php include __DIR__.'/views/receive.view.php'; ?>
      </div>
    </main>
    <?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/personalisation-menu.php'; ?>
  </div>

  <?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/html-footer.php'; ?>
  <?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/footer.php'; ?>

  <script src="<?php echo BASE_PATH;?>/assets/js/stock-transfers/transfers-common.js"></script>
  <script src="<?php echo BASE_PATH;?>/assets/js/stock-transfers/transfers-pack.js"></script>
  <script src="<?php echo BASE_PATH;?>/assets/js/stock-transfers/transfers-receive.js"></script>
</body>
</html>
