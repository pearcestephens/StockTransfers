<?php
declare(strict_types=1);

header('Content-Type:application/json;charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/shipping/OutletRepo.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/shipping/StarshipitClient.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/shipping/GSSClient.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/services/FreightCalculator.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';

use CIS\Shipping\StarshipitClient;
use CIS\Shipping\GSSClient;
use Modules\Transfers\Stock\Services\FreightCalculator;

function jout(array $payload,int $code=200): never {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}
function jerr(string $msg,array $extra=[],int $code=400): never {
  jout(['ok'=>false,'error'=>$msg]+$extra,$code);
}

$tid=(int)($_GET['transfer']??$_GET['t']??0);
if($tid<=0) jerr('transfer is required');

$carriersWanted=array_values(array_filter(array_map('trim',explode(',',strtolower((string)($_GET['carriers']??'nz_post,gss'))))));
if(!$carriersWanted) $carriersWanted=['nz_post','gss'];

$wantAssets=((int)($_GET['assets']??1)===1);
$deep=((int)($_GET['deep']??1)===1);

$env=[
  'php_version'=>PHP_VERSION,
  'time'=>date('c'),
  'timezone'=>(string)(date_default_timezone_get()?:'UTC'),
  'app_env'=>(string)($_ENV['APP_ENV']??getenv('APP_ENV')??'dev'),
];

$advice=[];
$sections=[];
$pushAdv=function(int $prio,string $msg)use(&$advice){$advice[]=['prio'=>$prio,'msg'=>$msg];};

try {
  $pdo=cis_pdo();
  if(!$pdo instanceof PDO) jerr('database unavailable',[],500);

  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

  $tx=$pdo->prepare("SELECT id,outlet_from,outlet_to,status,state,created_at FROM transfers WHERE id=:id LIMIT 1");
  $tx->execute(['id'=>$tid]);
  $transfer=$tx->fetch(PDO::FETCH_ASSOC);
  if(!$transfer) jerr('transfer not found',['transfer'=>$tid],404);

  $it=$pdo->prepare("SELECT COUNT(*) FROM transfer_items WHERE transfer_id=:id");
  $it->execute(['id'=>$tid]);
  $itemsCount=(int)($it->fetchColumn()?:0);
  if($itemsCount<=0) $pushAdv(10,'No items on this transfer — pack table will look empty');

  $sections['transfer']=[
    'id'=>(int)$transfer['id'],
    'status'=>(string)$transfer['status'],
    'state'=>(string)$transfer['state'],
    'created_at'=>(string)$transfer['created_at'],
    'items_count'=>$itemsCount,
  ];
} catch(\Throwable $e) {
  jerr('db error',['exception'=>get_class($e)],500); // message not exposed
}

$from=outlet_by_vend_uuid((string)$transfer['outlet_from']);
$to=outlet_by_vend_uuid((string)$transfer['outlet_to']);

$must=function(?array $row,string $side)use($pushAdv):array{
  if(!$row) return ['missing'=>['outlet_not_found']];
  $addr=[
    'name'=>(string)($row['name']??''),
    'addr1'=>(string)($row['physical_address_1']??''),
    'addr2'=>(string)($row['physical_address_2']??''),
    'suburb'=>(string)($row['physical_suburb']??''),
    'city'=>(string)($row['physical_city']??''),
    'postcode'=>(string)($row['physical_postcode']??''),
    'country'=>(string)($row['physical_country_id']??''),
    'phone'=>(string)($row['physical_phone_number']??''),
  ];
  $missing=[];
  foreach(['name','addr1','city','postcode','country'] as $k){
    if(trim($addr[$k])==='') $missing[]=$k;
  }
  if($missing) $pushAdv(5,strtoupper($side).": incomplete address — missing ".implode(',',$missing));
  return ['address'=>$addr,'missing'=>$missing];
};

$fCheck=$must($from,'from');
$tCheck=$must($to,'to');
$sections['outlets']=[
  'from'=>['id'=>$from['id']??null,'name'=>$from['name']??null,'missing'=>$fCheck['missing']],
  'to'=>['id'=>$to['id']??null,'name'=>$to['name']??null,'missing'=>$tCheck['missing']],
];

// credential presence only (not keys)
$hasStar=!empty($from['nz_post_api_key'])&&!empty($from['nz_post_subscription_key']);
$hasGss=!empty($from['gss_token']);
if(!$hasStar && in_array('nz_post',$carriersWanted,true)) $pushAdv(4,'Starshipit API keys missing');
if(!$hasGss && in_array('gss',$carriersWanted,true)) $pushAdv(4,'GoSweetSpot token missing');
$sections['credentials']=['starshipit'=>['present'=>$hasStar],'gss'=>['present'=>$hasGss]];

$weight=['ok'=>false];
try {
  $calc=new FreightCalculator();
  $lines=$calc->getWeightedItems($tid);
  $sumG=0; foreach($lines as $ln) $sumG+=(int)($ln['line_weight_g']??0);
  $totalKg=round($sumG/1000,3);

  $capKg=18.0;
  try {
    $cap=$pdo->query("SELECT MAX(max_weight_kg) FROM containers WHERE max_weight_kg IS NOT NULL")->fetchColumn();
    if($cap && (float)$cap>0) $capKg=(float)$cap;
  } catch(\Throwable $e) {}

  $boxes=[];
  $rem=$totalKg;
  while($rem>0.0001){
    $load=min($capKg,$rem);
    $boxes[]=round($load,3);
    $rem=round($rem-$load,3);
    if(count($boxes)>200){ $boxes=[]; break; }
  }

  $weight=[
    'ok'=>true,
    'items_count'=>count($lines),
    'total_kg'=>$totalKg,
    'cap_kg'=>$capKg,
    'boxes'=>count($boxes)
  ];
} catch(\Throwable $e) {
  $weight['error']='calc_failed';
  $pushAdv(3,'Weight calculation failed');
}
$sections['weight_suggest']=$weight;

// (services, rates omitted for brevity if deep=false)

$urls=[
  'services_live_nzpost'=>"/modules/transfers/stock/api/services_live.php?transfer={$tid}&carrier=nz_post&debug=1",
  'services_live_gss'=>"/modules/transfers/stock/api/services_live.php?transfer={$tid}&carrier=gss&debug=1",
  'weight_suggest'=>"/modules/transfers/stock/api/weight_suggest.php?transfer={$tid}",
];

usort($advice,fn($a,$b)=>($a['prio']<=>$b['prio']));
$critical=array_filter($advice,fn($a)=>$a['prio']<=5);

jout([
  'ok'=>empty($critical),
  'env'=>$env,
  'sections'=>$sections,
  'urls'=>$urls,
  'advice'=>$advice,
]);
