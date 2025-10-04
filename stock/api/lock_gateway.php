<?php<?php<?php<?php

// LOCK GATEWAY - SIMPLE AND WORKING

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';declare(strict_types=1);

header('Content-Type: application/json');

declare(strict_types=1);declare(strict_types=1);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$transferId = (int)($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? 0);// SIMPLE LOCK GATEWAY - JUST WORKS

$userId = (int)($_SESSION['userID'] ?? 0);

$DOCUMENT_ROOT = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

if (!$action || !$transferId || !$userId) {

    echo json_encode(['success' => false, 'error' => 'Missing params']);require_once $DOCUMENT_ROOT . '/app.php';

    exit;

}/**/**



// Create table if neededheader('Content-Type: application/json');

$mysqli->query("CREATE TABLE IF NOT EXISTS transfer_locks (

    id INT AUTO_INCREMENT PRIMARY KEY, * lock_gateway.php * Lock Gateway — single, stable surface for lock status/ops.

    transfer_id INT NOT NULL,

    user_id INT NOT NULL,// Get params

    holder_name VARCHAR(100),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,$action = $_GET['action'] ?? $_POST['action'] ?? ''; * SINGLE UNIFIED LOCK ENDPOINT - All lock operations route through here * Actions (action=…):

    expires_at TIMESTAMP NULL

)");$transferId = (int)($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? 0);



switch ($action) {$userId = (int)($_SESSION['userID'] ?? 0); * Actions: status, acquire, release, heartbeat, request_start, request_decide, request_state, force_release *   status            GET   transfer_id           -> {success,data:{has_lock,is_locked,is_locked_by_other,holder_name,expires_at}}

    case 'status':

        $stmt = $mysqli->prepare("SELECT * FROM transfer_locks WHERE transfer_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");$fingerprint = $_GET['fingerprint'] ?? $_POST['fingerprint'] ?? '';

        $stmt->bind_param('i', $transferId);

        $stmt->execute(); *  *   acquire           POST  transfer_id,fingerprint?

        $result = $stmt->get_result();

        $lock = $result->fetch_assoc();if (!$action || !$transferId || !$userId) {

        

        if (!$lock) {    echo json_encode(['success' => false, 'error' => 'Missing params']); * Replaces 45+ scattered legacy endpoints with clean action-based routing *   release           POST  transfer_id

            echo json_encode(['success' => true, 'data' => ['has_lock' => false, 'is_locked_by_other' => false]]);

        } else {    exit;

            $hasLock = ($lock['user_id'] == $userId);

            echo json_encode(['success' => true, 'data' => [} * Single-tab enforcement via fingerprint system *   heartbeat         POST  transfer_id

                'has_lock' => $hasLock,

                'is_locked_by_other' => !$hasLock,

                'user_id' => (int)$lock['user_id'],

                'holder_name' => $lock['holder_name'] ?? 'User'try { */ *   request_start     POST  transfer_id,message?

            ]]);

        }    $db = new mysqli($host, $user, $pass, $db_name);

        break;

            if ($db->connect_error) throw new Exception('DB error'); *   request_decide    POST  transfer_id,decision=grant|decline

    case 'acquire':

        $holderName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';    

        $expiresAt = date('Y-m-d H:i:s', time() + 1800);

            switch ($action) {@date_default_timezone_set('Pacific/Auckland'); *   request_state     GET   transfer_id           -> latest request snapshot (for polling UIs)

        // Delete any existing locks

        $mysqli->query("DELETE FROM transfer_locks WHERE transfer_id = $transferId");        case 'status':

        

        // Create new lock            $stmt = $db->prepare("SELECT * FROM transfer_locks WHERE transfer_id = ? ORDER BY created_at DESC LIMIT 1"); *   force_release     POST  transfer_id           -> (admin / same holder)

        $stmt = $mysqli->prepare("INSERT INTO transfer_locks (transfer_id, user_id, holder_name, expires_at) VALUES (?, ?, ?, ?)");

        $stmt->bind_param('iiss', $transferId, $userId, $holderName, $expiresAt);            $stmt->bind_param('i', $transferId);

        

        if ($stmt->execute()) {            $stmt->execute();$DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'); */

            echo json_encode(['success' => true]);

        } else {            $result = $stmt->get_result();

            echo json_encode(['success' => false, 'error' => 'Could not acquire']);

        }            $lock = $result->fetch_assoc();if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {

        break;

                    

    case 'release':

        $mysqli->query("DELETE FROM transfer_locks WHERE transfer_id = $transferId AND user_id = $userId");            if (!$lock) {  http_response_code(500);@date_default_timezone_set('Pacific/Auckland');

        echo json_encode(['success' => true]);

        break;                echo json_encode(['success' => true, 'data' => ['has_lock' => false, 'is_locked_by_other' => false]]);

        

    case 'heartbeat':            } else {  echo json_encode(['success' => false, 'error' => 'Server misconfiguration']);

        $expiresAt = date('Y-m-d H:i:s', time() + 1800);

        $stmt = $mysqli->prepare("UPDATE transfer_locks SET expires_at = ? WHERE transfer_id = ? AND user_id = ?");                $hasLock = ($lock['user_id'] == $userId);

        $stmt->bind_param('sii', $expiresAt, $transferId, $userId);

        $stmt->execute();                $isExpired = (strtotime($lock['expires_at']) < time());  exit;require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';

        

        if ($stmt->affected_rows > 0) {                

            echo json_encode(['success' => true]);

        } else {                if ($isExpired) {}if (session_status() !== PHP_SESSION_ACTIVE) session_start();

            echo json_encode(['success' => false, 'error' => 'Lock not found']);

        }                    // Clean up expired lock

        break;

                            $db->query("DELETE FROM transfer_locks WHERE transfer_id = $transferId");

    default:

        echo json_encode(['success' => false, 'error' => 'Unknown action']);                    echo json_encode(['success' => true, 'data' => ['has_lock' => false, 'is_locked_by_other' => false]]);

}
                } else {require_once $DOCUMENT_ROOT . '/app.php';use Modules\Transfers\Stock\Services\PackLockService;

                    echo json_encode(['success' => true, 'data' => [

                        'has_lock' => $hasLock,require_once $DOCUMENT_ROOT . '/modules/transfers/_shared/Autoload.php';use Modules\Transfers\Stock\Services\StaffNameResolver;

                        'is_locked_by_other' => !$hasLock,

                        'user_id' => (int)$lock['user_id'],

                        'holder_name' => $lock['holder_name'] ?? 'User',

                        'expires_at' => $lock['expires_at']use Modules\Transfers\Stock\Services\PackLockService;header('Content-Type: application/json; charset=utf-8');

                    ]]);

                }header('Cache-Control: no-store');

            }

            break;// CORS Headers

            

        case 'acquire':header('Content-Type: application/json');function respond($ok, array $payload = [], int $code = 200): never {

            // Try to get lock

            $holderName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';header('Access-Control-Allow-Origin: *');  http_response_code($code);

            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 min

            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');  echo json_encode($ok ? (['success'=>true] + $payload) : (['success'=>false] + $payload), JSON_UNESCAPED_SLASHES);

            // Delete any existing locks for this transfer

            $db->query("DELETE FROM transfer_locks WHERE transfer_id = $transferId");header('Access-Control-Allow-Headers: Content-Type');  exit;

            

            // Insert new lock}

            $stmt = $db->prepare("INSERT INTO transfer_locks (transfer_id, user_id, holder_name, fingerprint, expires_at) VALUES (?, ?, ?, ?, ?)");

            $stmt->bind_param('iisss', $transferId, $userId, $holderName, $fingerprint, $expiresAt);if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

            

            if ($stmt->execute()) {  http_response_code(200);function require_auth(): int {

                echo json_encode(['success' => true]);

            } else {  exit;  $uid = (int)($_SESSION['userID'] ?? $_SESSION['user_id'] ?? 0);

                echo json_encode(['success' => false, 'error' => 'Could not acquire lock']);

            }}  if ($uid <= 0) respond(false, ['error'=>['code'=>'UNAUTH','message'=>'Login required']], 401);

            break;

              return $uid;

        case 'release':

            $db->query("DELETE FROM transfer_locks WHERE transfer_id = $transferId AND user_id = $userId");// Get action parameter}

            echo json_encode(['success' => true]);

            break;$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

            

        case 'heartbeat':if ($action === '') {function str_bool($v): bool { return !!$v; }

            $expiresAt = date('Y-m-d H:i:s', time() + 1800);

            $stmt = $db->prepare("UPDATE transfer_locks SET expires_at = ? WHERE transfer_id = ? AND user_id = ?");  http_response_code(400);

            $stmt->bind_param('sii', $expiresAt, $transferId, $userId);

            $stmt->execute();  echo json_encode(['success' => false, 'error' => 'Missing action parameter']);$uid    = require_auth();

            

            if ($stmt->affected_rows > 0) {  exit;$action = strtolower((string)($_GET['action'] ?? $_POST['action'] ?? ''));

                echo json_encode(['success' => true]);

            } else {}$tid    = (string)($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? '');

                echo json_encode(['success' => false, 'error' => 'Lock not found']);

            }if ($tid === '') respond(false, ['error'=>['code'=>'MISSING_TRANSFER_ID','message'=>'transfer_id required']], 422);

            break;

            // Get common parameters

        default:

            echo json_encode(['success' => false, 'error' => 'Unknown action']);$transferId = (int)($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? 0);$svc      = new PackLockService();

    }

    $userId = (int)($_SESSION['userID'] ?? 0);$resolver = new StaffNameResolver();

} catch (Exception $e) {

    error_log("Lock Gateway Error: " . $e->getMessage());$fingerprint = trim((string)($_GET['fingerprint'] ?? $_POST['fingerprint'] ?? ''));

    echo json_encode(['success' => false, 'error' => 'System error']);

}try {

if ($transferId <= 0) {  switch ($action) {

  http_response_code(400);    case 'status': {

  echo json_encode(['success' => false, 'error' => 'Invalid transfer_id']);      $clientFp = (string)($_GET['fp'] ?? $_GET['fingerprint'] ?? '');

  exit;      $lock = $svc->getLock($tid);

}      $sameUserOtherTab = false;

      if ($lock && (int)$lock['user_id'] === $uid && $clientFp !== '' && ($lock['client_fingerprint'] ?? '') !== '' && $clientFp !== $lock['client_fingerprint']) {

if ($userId <= 0) {        $sameUserOtherTab = true; // holder elsewhere

  http_response_code(401);      }

  echo json_encode(['success' => false, 'error' => 'Authentication required']);      $hasLock = $lock && (int)$lock['user_id'] === $uid && !$sameUserOtherTab;

  exit;      $out  = [

}        'has_lock'              => $hasLock,

        'is_locked'             => (bool)$lock,

try {        'is_locked_by_other'    => $lock ? ((int)$lock['user_id'] !== $uid || $sameUserOtherTab) : false,

  $lockService = new PackLockService();        'holder_id'             => $lock ? (int)$lock['user_id'] : null,

          'holder_name'           => $lock ? ($resolver->name((int)$lock['user_id']) ?? 'Unknown User') : null,

  switch ($action) {        'expires_at'            => $lock['expires_at'] ?? null,

    case 'status':        'lock_acquired_at'      => $lock['acquired_at'] ?? null,

      $status = $lockService->getStatus($transferId);        'can_request'           => !$lock || (int)$lock['user_id'] !== $uid,

      echo json_encode(['success' => true, 'data' => $status]);        'same_user_other_tab'   => $sameUserOtherTab,

      break;        'client_fingerprint'    => $lock['client_fingerprint'] ?? null,

            ];

    case 'acquire':      respond(true, ['data'=>$out]);

      $result = $lockService->acquire($transferId, $userId, $fingerprint);    }

      echo json_encode($result);

      break;    case 'acquire': {

            $fp = (string)($_POST['fingerprint'] ?? null);

    case 'release':      $r  = $svc->acquire($tid, $uid, $fp ?: null);

      $result = $lockService->release($transferId, $userId, $fingerprint);      if (!($r['success'] ?? false)) {

      echo json_encode($result);        $code = 'ACQUIRE_FAILED';

      break;        $msg  = 'Acquire failed';

              if (!empty($r['same_user_other_tab'])) { $code='SELF_OTHER_TAB'; $msg='You already hold this lock in another tab.'; }

    case 'heartbeat':        elseif (!empty($r['conflict'])) { $code='LOCK_HELD'; $msg='Lock held by another user'; }

      $result = $lockService->heartbeat($transferId, $userId, $fingerprint);        respond(false, ['error'=>['code'=>$code,'message'=>$msg],'holder'=>$r['holder']??null,'same_user_other_tab'=>!empty($r['same_user_other_tab'])], 409);

      echo json_encode($result);      }

      break;      respond(true, ['lock'=>$r['lock'] ?? null]);

          }

    case 'request_start':

      $message = trim((string)($_POST['message'] ?? ''));    case 'release': {

      $result = $lockService->startOwnershipRequest($transferId, $userId, $fingerprint, $message);      $r = $svc->releaseLock($tid, $uid, false);

      echo json_encode($result);      respond(($r['success']??false), ['released'=>($r['success']??false)]);

      break;    }

      

    case 'request_decide':    case 'heartbeat': {

      $decision = trim((string)($_POST['decision'] ?? ''));      $r = $svc->heartbeat($tid, $uid);

      $requestId = (int)($_POST['request_id'] ?? 0);      if (!($r['success']??false)) respond(false, ['error'=>['code'=>'NOT_HOLDER','message'=>'No active lock for user']], 409);

      if (!in_array($decision, ['accept', 'decline'])) {      respond(true, ['lock'=>$r['lock'] ?? null]);

        http_response_code(400);    }

        echo json_encode(['success' => false, 'error' => 'Invalid decision']);

        exit;    case 'request_start': {

      }      $msg = (string)($_POST['message'] ?? 'Ownership request');

      $result = $lockService->decideOwnershipRequest($transferId, $userId, $requestId, $decision);      $fp  = (string)($_POST['fingerprint'] ?? null);

      echo json_encode($result);      $r   = $svc->requestAccess($tid, $uid, $fp ?: null);

      break;      if (!($r['success']??false)) respond(false, ['error'=>['code'=>'REQUEST_FAILED','message'=>'Unable to create request']]);

            // shape for UI

    case 'request_state':      respond(true, [

      $state = $lockService->getOwnershipRequestState($transferId, $userId);        'request_id'   => (int)($r['request_id'] ?? 0),

      echo json_encode(['success' => true, 'data' => $state]);        'expires_at'   => $r['expires_at'] ?? null,

      break;        'already_owner'=> str_bool($r['already_holder'] ?? false),

              'holder'       => $r['holder'] ?? null,

    case 'force_release':        'state'        => 'pending'

      // Admin-only endpoint      ]);

      if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {    }

        http_response_code(403);

        echo json_encode(['success' => false, 'error' => 'Admin access required']);    case 'request_decide': {

        exit;      $decision = strtolower((string)($_POST['decision'] ?? ''));

      }      if (!in_array($decision, ['grant','decline'], true)) {

      $result = $lockService->forceRelease($transferId);        respond(false, ['error'=>['code'=>'BAD_DECISION','message'=>'decision must be grant|decline']], 422);

      echo json_encode($result);      }

      break;

            // latest pending request for this transfer:

    default:      $pending = $svc->holderPendingRequests($tid, $uid);

      http_response_code(400);      if (!$pending) respond(false, ['error'=>['code'=>'NO_PENDING','message'=>'No pending requests']], 404);

      echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);

      break;      $req = $pending[0];

  }      $acc = $decision === 'grant';

        $rr  = $svc->respond((int)$req['id'], $uid, $acc);

} catch (Exception $e) {      if (!($rr['success']??false)) respond(false, ['error'=>['code'=>'DECIDE_FAIL','message'=>$rr['error']??'Decision failed']], 409);

  error_log("Lock Gateway Error: " . $e->getMessage());

  http_response_code(500);      respond(true, [

  echo json_encode(['success' => false, 'error' => 'Internal server error']);        'state'     => $acc ? 'accepted' : 'declined',

}        'request_id'=> (int)$req['id'],
        'new_owner' => $acc ? (int)$req['user_id'] : null
      ]);
    }

    case 'request_state': {
      $pending = $svc->holderPendingRequests($tid, $uid);
      $lock    = $svc->getLock($tid);
      $holderId= $lock ? (int)$lock['user_id'] : null;

      if (!$pending) {
        respond(true, ['state'=>'none', 'holder_user_id'=>$holderId]);
      }

      $req = $pending[0];
      // Window enforced server-side (default 60s) after which auto-grant occurs during cleanup
      $holderDeadline = $req['expires_at'] ?? null;
      respond(true, [
        'state'                 => 'pending',
        'request_id'            => (int)$req['id'],
        'requesting_user_id'    => (int)$req['user_id'],
        'requesting_user_name'  => $resolver->name((int)$req['user_id']),
        'holder_user_id'        => $holderId,
        'holder_deadline'       => $holderDeadline,
      ]);
    }

    case 'force_release': {
      // allow holder or privileged users to force
      $lock = $svc->getLock($tid);
      if ($lock && (int)$lock['user_id'] !== $uid) {
        // here you can add your own RBAC; for now only holder can force
        respond(false, ['error'=>['code'=>'FORBIDDEN','message'=>'Only holder may force release in this build']], 403);
      }
      $r = $svc->releaseLock($tid, $uid, true);
      respond(($r['success']??false), ['released'=>($r['success']??false)]);
    }

    default:
      respond(false, ['error'=>['code'=>'BAD_ACTION','message'=>'Unknown or missing action']], 400);
  }
} catch (Throwable $e) {
  respond(false, ['error'=>['code'=>'EXCEPTION','message'=>$e->getMessage()]], 500);
}
