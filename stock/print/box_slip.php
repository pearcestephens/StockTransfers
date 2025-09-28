<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';

use PDO;
use Modules\Transfers\Stock\Lib\AccessPolicy;

if (empty($_SESSION['userID'])) {
  http_response_code(302);
  header('Location:/login.php');
  exit;
}

$tid = (int)($_GET['transfer'] ?? 0);
if ($tid <= 0) { http_response_code(400); echo 'Missing transfer'; exit; }
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $tid)) { http_response_code(403); echo 'Forbidden'; exit; }

$db = null;
if (class_exists('\\Core\\DB') && method_exists('\\Core\\DB', 'instance')) {
  $db = \Core\DB::instance();
} elseif (function_exists('cis_pdo')) {
  $db = cis_pdo();
} elseif (class_exists('\\DB') && method_exists('\\DB', 'instance')) {
  $db = \DB::instance();
} elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
  $db = $GLOBALS['pdo'];
}

if (!$db instanceof PDO) {
  throw new \RuntimeException('Database connection not available for box slip renderer.');
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tx = $db->prepare("SELECT outlet_from, outlet_to FROM transfers WHERE id=:id");
$tx->execute(['id'=>$tid]);
$tr = $tx->fetch(PDO::FETCH_ASSOC);
if (!$tr) { http_response_code(404); echo 'Transfer not found'; exit; }

function outlet_name_by_uuid(PDO $db, string $vendUuid): string {
  $st = $db->prepare("SELECT name FROM vend_outlets WHERE id=:id");
  $st->execute(['id'=>$vendUuid]);
  $n = $st->fetchColumn();
  return $n ?: 'UNKNOWN';
}

$fromName = outlet_name_by_uuid($db, (string)$tr['outlet_from']);
$toName   = outlet_name_by_uuid($db, (string)$tr['outlet_to']);

/* ---------- PREVIEW MODE: ?preview=1&n=7 ---------- */
if ((int)($_GET['preview'] ?? 0) === 1) {
  $n = max(1, (int)($_GET['n'] ?? 1));
  header('Content-Type:text/html;charset=utf-8'); ?>
  <!doctype html>
  <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Box Slips (Preview)</title>
      <style>
        :root {
          color-scheme: light dark;
        }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          font-family: system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial,"Noto Sans","Liberation Sans",sans-serif;
          background: #f5f5f5;
          color: #111;
        }
        header {
          position: sticky;
          top: 0;
          z-index: 10;
          background: rgba(255,255,255,0.94);
          backdrop-filter: blur(8px);
          border-bottom: 1px solid rgba(0,0,0,0.08);
          padding: 12px 18px;
          display: flex;
          align-items: center;
          justify-content: space-between;
        }
        header h1 {
          margin: 0;
          font-size: 1rem;
          letter-spacing: 0.04em;
          text-transform: uppercase;
        }
        header .meta {
          font-size: 0.85rem;
          color: rgba(0,0,0,0.55);
        }
        header button {
          background: #0f7a3d;
          color: #fff;
          border: none;
          border-radius: 999px;
          padding: 9px 18px;
          font-size: 0.9rem;
          font-weight: 600;
          cursor: pointer;
          box-shadow: 0 10px 24px rgba(15, 122, 61, 0.25);
          transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        header button:hover {
          transform: translateY(-1px);
          box-shadow: 0 12px 28px rgba(15, 122, 61, 0.28);
        }
        .grid {
          padding: 28px clamp(16px, 5vw, 64px) 56px;
          display: grid;
          gap: 36px;
          grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }
        .slip {
          background: #fffef5;
          border-radius: 24px;
          padding: 32px;
          box-shadow: 0 20px 48px rgba(0,0,0,0.09);
          border: 2px solid rgba(255, 221, 120, 0.75);
          position: relative;
          display: flex;
          flex-direction: column;
          min-height: 280px;
          font-size: 1.1rem;
        }
        .slip::after {
          content: '';
          position: absolute;
          left: 26px;
          right: 26px;
          bottom: 28px;
          height: 1px;
          border-bottom: 3px dashed rgba(0,0,0,0.22);
        }
        .slip h2 {
          margin: 0;
          font-size: 1.35rem;
          letter-spacing: 0.08em;
          font-weight: 800;
        }
        .slip dl {
          margin: 24px 0 0;
          display: grid;
          gap: 14px;
          font-size: 1.05rem;
        }
        .slip dt {
          font-weight: 700;
          color: rgba(0,0,0,0.55);
          font-size: 0.95rem;
          text-transform: uppercase;
          letter-spacing: 0.08em;
        }
        .slip dd {
          margin: 4px 0 0;
          font-weight: 700;
          font-size: 1.35rem;
        }
        .slip .badge {
          margin-top: auto;
          align-self: flex-start;
          padding: 8px 16px;
          border-radius: 999px;
          font-size: 0.95rem;
          background: rgba(15, 122, 61, 0.12);
          color: #0f7a3d;
          font-weight: 700;
        }
        @media (max-width: 640px) {
          header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
          }
          .grid {
            padding: 22px 14px 40px;
            gap: 24px;
          }
        }
      </style>
    </head>
    <body>
      <header>
        <div>
          <h1>Transfer #<?= htmlspecialchars((string)$tid) ?> — Preview (<?= $n ?> boxes)</h1>
          <div class="meta">From <?= htmlspecialchars($fromName) ?> to <?= htmlspecialchars($toName) ?></div>
        </div>
        <button type="button" onclick="window.print()">Print These Labels</button>
      </header>
      <main class="grid" role="list">
        <?php for ($i = 1; $i <= $n; $i++): ?>
          <article class="slip" role="listitem">
            <h2>Transfer #<?= htmlspecialchars((string)$tid) ?></h2>
            <dl>
              <div>
                <dt>From</dt>
                <dd><?= htmlspecialchars($fromName) ?></dd>
              </div>
              <div>
                <dt>To</dt>
                <dd><?= htmlspecialchars($toName) ?></dd>
              </div>
              <div>
                <dt>Box</dt>
                <dd><?= $i ?> of <?= $n ?></dd>
              </div>
            </dl>
            <span class="badge">Preview — no tracking</span>
          </article>
        <?php endfor; ?>
      </main>
    </body>
  </html>
  <?php exit;
}

/* ---------- LIVE MODE (shipments/parcels) ---------- */
$shipmentParam = $_GET['shipment'] ?? 'latest';
$shipmentId = 0;

if ($shipmentParam === 'latest') {
  $s = $db->prepare("SELECT id FROM transfer_shipments WHERE transfer_id=:tid ORDER BY id DESC LIMIT 1");
  $s->execute(['tid'=>$tid]);
  $shipmentId = (int)($s->fetchColumn() ?: 0);
} else {
  $shipmentId = (int)$shipmentParam;
}
if ($shipmentId <= 0) {
  // Fallback: if n is provided, allow preview without parcels
  $n = (int)($_GET['n'] ?? 0);
  if ($n > 0) {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?transfer='.$tid.'&preview=1&n='.$n);
    exit;
  }
  http_response_code(404); echo 'Shipment not found'; exit;
}

$p = $db->prepare("SELECT id, box_number, tracking_number, courier
                     FROM transfer_parcels
                    WHERE shipment_id=:sid
                 ORDER BY box_number ASC");
$p->execute(['sid'=>$shipmentId]);
$parcels = $p->fetchAll(PDO::FETCH_ASSOC);
if (!$parcels) { http_response_code(404); echo 'No parcels'; exit; }

header('Content-Type:text/html;charset=utf-8'); ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Box Slips</title>
<style>
  @page { size: 80mm auto; margin: 0; }
  body { margin: 0; font-family: system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial,"Noto Sans","Liberation Sans",sans-serif; }
  .slip { width: 76mm; padding: 4mm; border-bottom: 1px dashed #000; page-break-inside: avoid; }
  .title { font-weight: 800; font-size: 16px; letter-spacing: .3px; }
  .line  { font-size: 14px; margin-top: 2mm; }
  .small { font-size: 12px; color:#111; }
  .muted { color:#333; }
  .big   { font-size: 18px; font-weight: 800; }
</style></head><body>
<?php $total = count($parcels);
foreach ($parcels as $pc):
  $boxNo = (int)$pc['box_number'];
  $trk   = trim((string)($pc['tracking_number'] ?? ''));
  $car   = strtoupper((string)($pc['courier'] ?? ''));
?>
  <div class="slip">
    <div class="title">TRANSFER #<?= htmlspecialchars((string)$tid) ?></div>
    <div class="line"><span class="muted">FROM:&nbsp;</span><span class="big"><?= htmlspecialchars($fromName) ?></span></div>
    <div class="line"><span class="muted">TO:&nbsp;&nbsp;&nbsp;&nbsp;</span><span class="big"><?= htmlspecialchars($toName) ?></span></div>
    <div class="line"><span class="muted">BOX:&nbsp;</span><span class="big"><?= $boxNo ?> of <?= $total ?></span></div>
    <?php if ($trk): ?>
      <div class="line"><span class="muted">TRACKING:&nbsp;</span><span class="small"><?= htmlspecialchars($trk) ?></span></div>
    <?php else: ?>
      <div class="line small muted">No tracking (<?= $car ?: 'INTERNAL' ?>)</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<script>window.print();</script>
</body></html>
