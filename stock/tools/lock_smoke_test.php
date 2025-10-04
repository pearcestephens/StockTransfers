<?php
/**
 * lock_smoke_test.php
 * Purpose: Command-line harness to exercise simple_lock.php endpoint and simulate multi-tab / multi-user flows.
 *
 * Usage:
 *   php modules/transfers/stock/tools/lock_smoke_test.php \
 *       --resource="transfer:TEST123" \
 *       --userA=1001 --userB=2002 \
 *       [--ttl=60] [--cycles=1] [--json]
 *
 * The harness generates random tab IDs for each simulated tab and logs a timeline of events.
 * It does NOT depend on browser APIs, allowing CI execution.
 */

declare(strict_types=1);

// -------------------- Arg Parse --------------------
$args = getopt('', [
  'resource:', 'userA:', 'userB:', 'ttl::', 'cycles::', 'json::'
]);
$resource = $args['resource'] ?? ('transfer:SMOKE_'.date('Ymd_His'));
$userA    = $args['userA'] ?? '9001';
$userB    = $args['userB'] ?? '9002';
$ttl      = (int)($args['ttl'] ?? 60);
$cycles   = (int)($args['cycles'] ?? 1);
$jsonOut  = array_key_exists('json',$args);

$endpoint = '/modules/transfers/stock/api/simple_lock.php';
$absEndpoint = $_SERVER['DOCUMENT_ROOT'] . $endpoint; // local include if same host (avoid HTTP)

if(!is_file($absEndpoint)){
  fwrite(STDERR, "Cannot locate endpoint: $absEndpoint\n");
  exit(1);
}

function call_lock(string $action, array $payload): array {
  global $absEndpoint;
  // Simulate HTTP POST by invoking the PHP file in process; isolate scope via separate process would be safer.
  $jsonPayload = json_encode(array_merge(['action'=>$action], $payload));
  $cmd = escapeshellcmd(PHP_BINARY). ' ' . escapeshellarg($absEndpoint) . ' 2>/dev/null';
  // Fallback to curl if direct exec is not valid (since endpoint expects web env). We will attempt HTTP via curl if needed.
  // Simpler: Use curl locally referencing host if direct CLI fails. We'll detect failure by invalid JSON.
  $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
  $proc = proc_open($cmd, $descriptors, $pipes, null, [ 'REQUEST_METHOD' => 'POST' ]);
  if(is_resource($proc)){
    fwrite($pipes[0], $jsonPayload); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode($out,true);
    if(is_array($decoded)) return $decoded + ['_raw'=>$out];
    return ['ok'=>false,'err'=>'decode_cli','output'=>$out,'stderr'=>$err];
  }
  return ['ok'=>false,'err'=>'proc_open_failure'];
}

function timeline(array &$log, string $event, array $data=[]){
  $log[] = ['ts'=>date('c'),'event'=>$event,'data'=>$data];
}

$log = [];
$tabA1 = 'A1_'.bin2hex(random_bytes(3));
$tabB1 = 'B1_'.bin2hex(random_bytes(3));

for($c=1;$c<=$cycles;$c++){
  timeline($log, 'cycle_start', ['cycle'=>$c]);

  // 1. UserA TabA acquire
  $r1 = call_lock('acquire',['resource_key'=>$resource,'owner_id'=>$userA,'tab_id'=>$tabA1,'ttl'=>$ttl]);
  timeline($log,'userA_tabA_acquire',$r1);

  // 2. UserB TabB attempt acquire (expect blocked)
  $r2 = call_lock('acquire',['resource_key'=>$resource,'owner_id'=>$userB,'tab_id'=>$tabB1,'ttl'=>$ttl]);
  timeline($log,'userB_tabB_acquire',$r2);

  // 3. UserA heartbeat
  $r3 = call_lock('heartbeat',['resource_key'=>$resource,'owner_id'=>$userA,'tab_id'=>$tabA1,'token'=>$r1['token'] ?? '']);
  timeline($log,'userA_tabA_heartbeat',$r3);

  // 4. UserA release
  $r4 = call_lock('release',['resource_key'=>$resource,'owner_id'=>$userA,'tab_id'=>$tabA1,'token'=>$r1['token'] ?? '']);
  timeline($log,'userA_tabA_release',$r4);

  // 5. UserB acquire post-release (expect success)
  $r5 = call_lock('acquire',['resource_key'=>$resource,'owner_id'=>$userB,'tab_id'=>$tabB1,'ttl'=>$ttl]);
  timeline($log,'userB_tabB_acquire_after_release',$r5);

  // 6. Same-owner steal simulation (change B to A new tab)
  $tabA2 = 'A2_'.bin2hex(random_bytes(3));
  $r6 = call_lock('steal',['resource_key'=>$resource,'owner_id'=>$userB,'tab_id'=>$tabA2]);
  timeline($log,'steal_wrong_owner_expect_fail',$r6);

  $r7 = call_lock('steal',['resource_key'=>$resource,'owner_id'=>$userA,'tab_id'=>$tabA2]);
  timeline($log,'steal_same_owner',$r7);

  // 7. Status check final
  $r8 = call_lock('status',['resource_key'=>$resource]);
  timeline($log,'final_status',$r8);

  // 8. Cleanup release by whoever owns it (try both)
  if(($r7['acquired'] ?? false) && isset($r7['token'])){
    timeline($log,'cleanup_release_same_owner', call_lock('release',[ 'resource_key'=>$resource,'owner_id'=>$userA,'tab_id'=>$tabA2,'token'=>$r7['token'] ]));
  } elseif(($r5['acquired'] ?? false) && isset($r5['token'])) {
    timeline($log,'cleanup_release_userB', call_lock('release',[ 'resource_key'=>$resource,'owner_id'=>$userB,'tab_id'=>$tabB1,'token'=>$r5['token'] ]));
  }

  timeline($log,'cycle_end',['cycle'=>$c]);
}

if($jsonOut){
  echo json_encode(['resource'=>$resource,'userA'=>$userA,'userB'=>$userB,'cycles'=>$cycles,'timeline'=>$log], JSON_PRETTY_PRINT) . "\n";
  exit(0);
}

// Human-readable summary
foreach($log as $entry){
  $ev = str_pad($entry['event'],32,' ');
  $ok = $entry['data']['ok'] ?? null; $flag = ($ok===true?'OK  ':($ok===false?'FAIL':'    '));
  $detail = '';
  if(isset($entry['data']['acquired'])){ $detail .= 'acquired='.(int)$entry['data']['acquired'].' '; }
  if(isset($entry['data']['locked'])){ $detail .= 'locked='.(int)$entry['data']['locked'].' '; }
  if(isset($entry['data']['reason'])){ $detail .= 'reason='.$entry['data']['reason'].' '; }
  if(isset($entry['data']['same_owner'])){ $detail .= 'same_owner='.(int)$entry['data']['same_owner'].' '; }
  echo $entry['ts'].' | '.$flag.' | '.$ev.' | '.$detail."\n";
}

echo "\nDone.\n";
