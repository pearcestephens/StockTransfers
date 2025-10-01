<?php
declare(strict_types=1);
/**
 * File: consignment.create.php (TEMP LOCATION - move to /assets/services/consignment.create.php for production path)
 * Purpose: Secure server-side proxy to create a Vend (Lightspeed Retail) consignment synchronously with idempotency.
 * Input (JSON POST): {
 *    external_ref: string,
 *    source_outlet_id: string,
 *    destination_outlet_id: string,
 *    lines: [ { sku: string, qty: int } ],
 *    note?: string,
 *    transfer_id?: int,
 *    pipeline_ref?: string
 * }
 * Headers (expected):
 *  - Content-Type: application/json
 *  - X-Idempotency-Key (required for idempotent caching)
 *  - X-Pipeline-Ref (optional – logged)
 *  - X-Request-ID  (optional – logged)
 *
 * Response:
 *  Success 200: { ok:true, id:"<vend_uuid>", number:"<vend_number>", raw:{...}, replay?:true }
 *  Error   4xx/5xx: { ok:false, error:"message", code:"ERR_CODE", http:int }
 *
 * Idempotency Strategy:
 *  - Reuses transfer_idempotency table (schema used by pack_send.php) keyed by idem_key.
 *  - Only caches successful (HTTP 2xx) responses; failures may be retried.
 *  - Body hash stored as SHA-256 of canonical payload (excluding volatile fields).
 *
 * Security:
 *  - Requires authenticated session (staff id) else 401.
 *  - Never exposes VEND_TOKEN to client.
 *
 * TODO: Reconcile with alternate draft version (payload keys: external_ref vs reference, products vs lines) and unify.
 * Frontend currently sends: external_ref, source_outlet_id, destination_outlet_id, products[], note, transfer_id
 * Accept both legacy (lines) and current (products) for backward compatibility.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
header('Content-Type: application/json; charset=utf-8');

// --- Helpers -----------------------------------------------------------------
function cc_pdo(): PDO {
    if(function_exists('pdo')) { return pdo(); }
    if(function_exists('cis_pdo')) { return cis_pdo(); }
    throw new RuntimeException('No PDO accessor available');
}
function cc_fail(int $http, string $code, string $msg, array $extra = []): void {
    http_response_code($http);
    echo json_encode(['ok'=>false,'error'=>$msg,'code'=>$code,'http'=>$http] + $extra, JSON_UNESCAPED_SLASHES); exit;
}
function cc_getenv(string $k, ?string $def=null): ?string { $v=getenv($k); return ($v===false||$v==='') ? $def : $v; }

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cc_fail(405,'METHOD_NOT_ALLOWED','Use POST');
    if(session_status()!==PHP_SESSION_ACTIVE) session_start();
    $userId = (int)($_SESSION['staff_id'] ?? $_SESSION['userID'] ?? 0);
    if($userId <= 0) cc_fail(401,'UNAUTHENTICATED','Login required');

    $raw = file_get_contents('php://input') ?: '';
    $payload = $raw ? json_decode($raw, true) : [];
    if(!is_array($payload)) cc_fail(400,'INVALID_JSON','Malformed JSON body');

    // Basic validation
    $externalRef = trim((string)($payload['external_ref'] ?? '')) ?: null;
    $sourceOutlet = trim((string)($payload['source_outlet_id'] ?? ''));
    $destOutlet   = trim((string)($payload['destination_outlet_id'] ?? ''));
    $lines        = $payload['lines'] ?? $payload['products'] ?? [];
    $note         = (string)($payload['note'] ?? '');
    $transferId   = isset($payload['transfer_id']) ? (int)$payload['transfer_id'] : null;
    $pipelineRef  = (string)($_SERVER['HTTP_X_PIPELINE_REF'] ?? ($payload['pipeline_ref'] ?? ''));
    $requestId    = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');

    if($sourceOutlet === '' || $destOutlet === '') cc_fail(422,'OUTLET_REQUIRED','Source & destination outlets required');
    if(!is_array($lines) || !count($lines)) cc_fail(422,'LINES_REQUIRED','At least one line required');

    // Normalise lines -> Vend expected shape (assuming product by SKU; adapt to product_id mapping if available)
    $vendLines = [];
    foreach($lines as $ln){
        if(!is_array($ln)) continue; $sku = (string)($ln['sku'] ?? ''); $qty = (int)($ln['qty'] ?? $ln['quantity'] ?? 0);
        if($sku === '' || $qty <= 0) continue; $vendLines[] = ['sku'=>$sku,'quantity'=>$qty];
    }
    if(!count($vendLines)) cc_fail(422,'LINES_EMPTY','No valid line entries');

    $idemKey = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if($idemKey === '') cc_fail(400,'IDEMPOTENCY_REQUIRED','X-Idempotency-Key required');

    $vendBase  = cc_getenv('VEND_BASE');
    $vendToken = cc_getenv('VEND_TOKEN');
    if(!$vendBase || !$vendToken) cc_fail(500,'VEND_ENV_MISSING','Vend environment not configured');

    $pdo = cc_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Idempotency lookup with dual-schema compatibility
    // Some earlier drafts used columns: response_code, response_body
    // Current draft expects: status_code, response_json
    $idemVariant = 'json';
    try { $pdo->query('SELECT response_json, status_code FROM transfer_idempotency LIMIT 0'); }
    catch(Throwable $e){ $idemVariant = 'body'; }
    $selectCols = $idemVariant === 'json'
        ? 'response_json AS body_col, status_code AS code_col'
        : 'response_body AS body_col, response_code AS code_col';
    $stmt = $pdo->prepare("SELECT $selectCols FROM transfer_idempotency WHERE idem_key = ? LIMIT 1");
    $stmt->execute([$idemKey]);
    if($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $resp = json_decode($row['body_col'] ?? 'null', true) ?: [];
        $code = (int)($row['code_col'] ?? 0);
        if($code >= 200 && $code < 300) {
            if(is_array($resp)) {
                $resp['replay'] = true;
                if(!isset($resp['idempotency_key'])) $resp['idempotency_key'] = $idemKey;
                if(!isset($resp['request_id']) && $requestId) $resp['request_id'] = $requestId;
            }
            echo json_encode($resp, JSON_UNESCAPED_SLASHES); exit;
        }
    }

    // New ordered probing logic (paths + Variant A then B on schema errors)
    $reference = $payload['reference'] ?? $externalRef; $reference = $reference ? trim((string)$reference) : '';
    $paths = ['/api/2.0/consignments','/api/2.0/stock_transfers'];
    $buildProducts = static function(array $src): array {
        $out=[]; foreach($src as $ln){ if(!is_array($ln)) continue; $sku=trim((string)($ln['sku']??'')); if($sku==='') continue; $qty = isset($ln['quantity'])? (int)$ln['quantity'] : (int)($ln['qty']??0); if($qty<=0) continue; $out[]=['sku'=>$sku,'quantity'=>$qty]; } return $out; };
    $buildLines = static function(array $src): array {
        $out=[]; foreach($src as $ln){ if(!is_array($ln)) continue; $sku=trim((string)($ln['sku']??'')); if($sku==='') continue; $qty = isset($ln['qty'])? (int)$ln['qty'] : (int)($ln['quantity']??0); if($qty<=0) continue; $out[]=['sku'=>$sku,'qty'=>$qty]; } return $out; };
    $payloadA = [
        'type'=>'stock_transfer',
        'source_outlet_id'=>(string)$sourceOutlet,
        'destination_outlet_id'=>(string)$destOutlet,
        'reference'=>$reference,
        'note'=>$note,
        'products'=>$buildProducts($payload['products'] ?? $payload['lines'] ?? $vendLines)
    ];
    $payloadB = [
        'reference'=>$reference,
        'outlet_from'=>(string)$sourceOutlet,
        'outlet_to'=>(string)$destOutlet,
        'note'=>$note,
        'lines'=>$buildLines($payload['lines'] ?? $payload['products'] ?? $vendLines)
    ];
    if($payloadA['note']==='') unset($payloadA['note']); if($payloadB['note']==='') unset($payloadB['note']);
    if($pipelineRef){ $payloadA['pipeline_ref']=$pipelineRef; $payloadB['pipeline_ref']=$pipelineRef; }

    // Headers (add pipeline/request if present)
    $headers = [ 'Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$vendToken,'X-Idempotency-Key: '.$idemKey ];
    if($pipelineRef) $headers[] = 'X-Pipeline-Ref: '.$pipelineRef; if($requestId) $headers[]='X-Request-ID: '.$requestId;

    $finalJson=null; $finalVariant=null; $finalPath=null; $http=0; $raw=''; $latMs=0; $errStr=null; $startAll=microtime(true);
    foreach($paths as $p){
        // Variant A
        [$http,$raw,$errStr] = (function($url,$body,$headers){ $ch=curl_init($url); $t0=microtime(true); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES)]); $resp=curl_exec($ch); $err=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE)?:0; curl_close($ch); return [$code,$resp,$err,microtime(true)-$t0]; })(rtrim($vendBase,'/').$p,$payloadA,$headers);
        $latMs=(int)round(($startAll+($latMs/1000??0))*1000); // non-critical, overwritten after decode below
        $jsonA = $raw!==false? json_decode($raw,true): null; $successA = $http>=200 && $http<300;
        if($errStr){ cc_fail(502,'VEND_CURL_ERROR','Vend network error: '.$errStr,[ 'path'=>$p,'variant'=>'A' ]); }
        if($raw!==false && $jsonA===null && json_last_error()!==JSON_ERROR_NONE){ cc_fail(502,'VEND_BAD_JSON','Vend returned invalid JSON',[ 'path'=>$p,'variant'=>'A','snippet'=>substr((string)$raw,0,160) ]); }
        if($successA){ $finalJson=$jsonA; $finalVariant='A'; $finalPath=$p; break; }
        // On 4xx try variant B on same path
        if($http>=400 && $http<500){
            [$http,$raw,$errStr] = (function($url,$body,$headers){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_SLASHES)]); $resp=curl_exec($ch); $err=curl_error($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE)?:0; curl_close($ch); return [$code,$resp,$err]; })(rtrim($vendBase,'/').$p,$payloadB,$headers);
            $jsonB = $raw!==false? json_decode($raw,true): null; $successB = $http>=200 && $http<300;
            if($errStr){ cc_fail(502,'VEND_CURL_ERROR','Vend network error: '.$errStr,[ 'path'=>$p,'variant'=>'B' ]); }
            if($raw!==false && $jsonB===null && json_last_error()!==JSON_ERROR_NONE){ cc_fail(502,'VEND_BAD_JSON','Vend returned invalid JSON',[ 'path'=>$p,'variant'=>'B','snippet'=>substr((string)$raw,0,160) ]); }
            if($successB){ $finalJson=$jsonB; $finalVariant='B'; $finalPath=$p; break; }
        }
        // Else continue to next path
    }
    if(!$finalJson){
        $decoded = $raw!==false? json_decode($raw,true): null;
        $msg = $decoded['error'] ?? ($decoded['message'] ?? 'Vend error '.$http);
        cc_fail($http?:502,'VEND_ERROR',$msg,[ 'path'=>$finalPath,'variant'=>$finalVariant,'raw'=>$decoded ]);
    }
    $vendId = $finalJson['id'] ?? $finalJson['uuid'] ?? $finalJson['consignment_id'] ?? $finalJson['transfer_id'] ?? null;
    $vendNumber = $finalJson['number'] ?? $finalJson['reference'] ?? $vendId;
    $json = $finalJson; // maintain existing variable name for downstream caching section

    $envelope = [
        'ok' => true,
        'id' => $vendId,
        'number' => $vendNumber,
        'raw' => $json,
        'path' => $finalPath,
        'variant' => $finalVariant,
        'pipeline_ref' => $pipelineRef ?: null,
        'idempotency_key' => $idemKey,
        'request_id' => $requestId ?: null,
    ];

    // Cache success response idempotently
    try {
        $idemHash = hash('sha256', json_encode($vendBody, JSON_UNESCAPED_SLASHES));
        if($idemVariant === 'json') {
            $ins = $pdo->prepare('INSERT INTO transfer_idempotency (idem_key, idem_hash, response_json, status_code, created_at) VALUES (?,?,?,?,NOW())');
            $ins->execute([$idemKey,$idemHash,json_encode($envelope, JSON_UNESCAPED_SLASHES),200]);
        } else {
            $ins = $pdo->prepare('INSERT INTO transfer_idempotency (idem_key, idem_hash, response_body, response_code, created_at) VALUES (?,?,?,?,NOW())');
            $ins->execute([$idemKey,$idemHash,json_encode($envelope, JSON_UNESCAPED_SLASHES),200]);
        }
    } catch(Throwable $e) {
        error_log('[consignment.create] idempotency insert failed: '.$e->getMessage());
    }

    // Optional immediate persistence if transfer id provided
    if($transferId && $vendId) {
        try {
            $up = $pdo->prepare('UPDATE transfers SET vend_transfer_id = COALESCE(vend_transfer_id, :vid), vend_number = COALESCE(vend_number,:vnum), updated_at = NOW() WHERE id = :tid');
            $up->execute([':vid'=>$vendId,':vnum'=>$vendNumber,':tid'=>$transferId]);
        } catch(Throwable $e){ error_log('[consignment.create] transfer update failed: '.$e->getMessage()); }
    }

    echo json_encode($envelope, JSON_UNESCAPED_SLASHES); exit;

} catch(Throwable $e) {
    cc_fail(500,'UNEXPECTED','Unhandled error: '.$e->getMessage());
}
