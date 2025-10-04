<?php
declare(strict_types=1);
/**
 * Courier API — Monolith V1
 * Single file: helpers, service & container catalogs, GSS/NZ Post clients, and actions.
 * Actions: rates, buy_label, cancel_label, validate_transfer, address_validate, address_save, manual_dispatch, load_prefs, save_prefs
 */
header('Content-Type: application/json; charset=utf-8');

/* ========== Core Helpers ========== */
if (!function_exists('cis_pdo')) { $app = $_SERVER['DOCUMENT_ROOT'].'/app.php'; if (is_file($app)) require_once $app; }
function db(): PDO {
  if (function_exists('cis_pdo')) return cis_pdo();
  $dsn  = getenv('DB_DSN') ?: sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST')?:'localhost', getenv('DB_NAME')?:'db');
  $user = getenv('DB_USER') ?: 'root'; $pass = getenv('DB_PASS') ?: '';
  return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function jpost(string $k,$d=null){ return isset($_POST[$k]) ? json_decode($_POST[$k], true) : $d; }
function sval(string $k,$d=null){ return $_POST[$k] ?? $d; }
function now(): string { return date('Y-m-d H:i:s'); }
final class R{ public static function ok(array $d=[],int $c=200){ http_response_code($c); echo json_encode(['ok'=>true,'data'=>$d],JSON_UNESCAPED_SLASHES); exit; } public static function err(string $m,array $x=[],int $c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m,'meta'=>$x],JSON_UNESCAPED_SLASHES); exit; }}

function get_transfer(int $id): array { $s=db()->prepare("SELECT * FROM transfers WHERE id=?"); $s->execute([$id]); $t=$s->fetch(PDO::FETCH_ASSOC); if(!$t) R::err('TRANSFER_NOT_FOUND',[],404); return $t; }
function ensure_shipment(int $tid): array {
  $s=db()->prepare("SELECT * FROM transfer_shipments WHERE transfer_id=? ORDER BY id DESC LIMIT 1"); $s->execute([$tid]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r) return $r;
  db()->prepare("INSERT INTO transfer_shipments (transfer_id, delivery_mode, status, created_at) VALUES (?, 'courier', 'packed', NOW())")->execute([$tid]);
  $id=(int)db()->lastInsertId(); $s=db()->prepare("SELECT * FROM transfer_shipments WHERE id=?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC);
}
function ensure_lock(int $transferId,int $userId): void { try{ $s=db()->prepare("SELECT 1 FROM transfer_pack_locks WHERE transfer_id=? AND user_id=? AND expires_at>NOW()"); $s->execute([$transferId,$userId]); if(!$s->fetchColumn()) R::err('LOCK_REQUIRED',[],423);}catch(Throwable $e){} }
function audit_log(int $tid,string $action,string $status='success',array $meta=[]): void { try{ db()->prepare("INSERT INTO transfer_audit_log (entity_type, transfer_pk, transfer_id, action, status, actor_type, created_at, metadata) VALUES ('transfer', ?, ?, ?, ?, 'user', NOW(), JSON_OBJECT('meta', CAST(? AS CHAR)))")->execute([$tid,(string)$tid,$action,$status,json_encode($meta)]);}catch(Throwable $e){} }
function event_log(int $tid,string $evt,array $data=[]): void { try{ db()->prepare("INSERT INTO transfer_logs (transfer_id,event_type,event_data,source_system,created_at) VALUES (?,?,?,?,NOW())")->execute([$tid,$evt,json_encode($data),'CIS']); }catch(Throwable $e){} }

/* ========== Credentials Resolver ========== */
function carrier_creds(string $outletFrom): array {
  $slug=strtoupper(preg_replace('/[^A-Z0-9]+/i','_',$outletFrom));
  $gss=['access_key'=>getenv("GSS_ACCESS_KEY_$slug")?:getenv('GSS_ACCESS_KEY'),'site_id'=>getenv("GSS_SITE_ID_$slug")?:getenv('GSS_SITE_ID'),'supportemail'=>getenv('GSS_SUPPORT_EMAIL')?:'it@local'];
  $nzp=['mode'=>getenv('NZPOST_MODE')?:'label','api_key'=>getenv("NZPOST_API_KEY_$slug")?:getenv('NZPOST_API_KEY'),'client_id'=>getenv("NZPOST_CLIENT_ID_$slug")?:getenv('NZPOST_CLIENT_ID'),'client_secret'=>getenv("NZPOST_CLIENT_SECRET_$slug")?:getenv('NZPOST_CLIENT_SECRET'),'site_code'=>getenv("NZPOST_SITE_CODE_$slug")?:getenv('NZPOST_SITE_CODE'),'account'=>getenv("NZPOST_ACCOUNT_$slug")?:getenv('NZPOST_ACCOUNT')];
  try{ $s=db()->prepare("SELECT extra_json FROM vend_outlets WHERE id=? OR code=? OR name=? LIMIT 1"); $s->execute([$outletFrom,$outletFrom,$outletFrom]); if($row=$s->fetch(PDO::FETCH_ASSOC)){ $j=json_decode($row['extra_json']??'null',true); if(is_array($j)){ if(!empty($j['gss'])) $gss=array_merge($gss,$j['gss']); if(!empty($j['nzpost'])) $nzp=array_merge($nzp,$j['nzpost']); } } }catch(Throwable $e){}
  if(!empty($gss['access_key']) && !empty($gss['site_id'])) return ['carrier'=>'GSS','gss'=>$gss,'nzpost'=>$nzp];
  if(!empty($nzp['api_key']) || (!empty($nzp['client_id']) && !empty($nzp['client_secret']))) return ['carrier'=>'NZ_POST','gss'=>$gss,'nzpost'=>$nzp];
  return ['carrier'=>'NONE','gss'=>$gss,'nzpost'=>$nzp];
}

/* ========== Service Catalog (DB-powered) ========== */
function sc_get_carrier_row(string $code): ?array { $s=db()->prepare("SELECT * FROM carriers WHERE code=? LIMIT 1"); $s->execute([$code]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r?:null; }
function sc_get_service_row_by_code(string $carrier_code,string $service_code): ?array {
  $s=db()->prepare("SELECT cs.* FROM carrier_services cs JOIN carriers c ON c.carrier_id=cs.carrier_id WHERE c.code=? AND cs.code=? LIMIT 1");
  $s->execute([$carrier_code,$service_code]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r?:null;
}
function sc_get_service_options(int $service_id): array { $s=db()->prepare("SELECT option_code FROM carrier_service_options WHERE service_id=?"); $s->execute([$service_id]); return array_map(fn($x)=>$x['option_code'],$s->fetchAll(PDO::FETCH_ASSOC)); }
function sc_resolve_provider_code(int $service_id,string $provider): ?string {
  try{ $s=db()->prepare("SELECT provider_code FROM carrier_service_mappings WHERE service_id=? AND provider=? LIMIT 1"); $s->execute([$service_id,$provider]); $r=$s->fetch(PDO::FETCH_ASSOC); if($r && !empty($r['provider_code'])) return $r['provider_code']; }catch(Throwable $e){}
  return null;
}
function sc_carrier_vol_divisor(string $carrier_code): int { $row=sc_get_carrier_row($carrier_code); return (int)($row['volumetric_factor']??5000); }
function sc_billable_weight_g(array $parcel,int $vol_div=5000): int {
  $g=(int)($parcel['weight_g']??0); $L=(int)($parcel['length_mm']??0); $W=(int)($parcel['width_mm']??0); $H=(int)($parcel['height_mm']??0);
  if($L>0&&$W>0&&$H>0&&$vol_div>0){ $kg_vol=(($L/10.0)*($W/10.0)*($H/10.0))/$vol_div; $g_vol=(int)ceil($kg_vol*1000); return max($g,$g_vol); } return $g;
}
function sc_choose_service(string $carrier_code,array $parcels,bool $prefer_satchel,bool $saturday): ?array {
  $code=null;
  if($carrier_code==='NZ_POST'){ $code=$saturday?'DOM_EXP_TONIGHT':'DOM_COURIER'; }
  elseif($carrier_code==='NZC' || $carrier_code==='GSS'){ $code=$saturday?'NZC_EXPRESS':'NZC_STANDARD'; }
  if(!$code) return null; return sc_get_service_row_by_code($carrier_code==='GSS'?'NZC':$carrier_code,$code);
}
function sc_collect_options(int $service_id,bool $sig,bool $sat,bool $r18=false): array {
  $avail=sc_get_service_options($service_id); $want=[];
  if($sig && in_array('SIGNATURE_REQUIRED',$avail,true)) $want[]='SIGNATURE_REQUIRED';
  if($r18 && in_array('AGE_RESTRICTED',$avail,true)) $want[]='AGE_RESTRICTED';
  if(!$sig && in_array('NO_ATL',$avail,true)) { /* ATL allowed marker if you want */ }
  return $want;
}

/* ========== Container Catalog (views) ========== */
function cc_carrier_id(string $code): ?int { $s=db()->prepare("SELECT carrier_id FROM carriers WHERE code=? LIMIT 1"); $s->execute([$code]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r?(int)$r['carrier_id']:null; }
function cc_list_containers(string $carrier_code,?string $service_code=null): array {
  $cid=cc_carrier_id($carrier_code); if(!$cid) return [];
  $sql="SELECT * FROM v_carrier_container_prices WHERE carrier_id=?"; $args=[$cid];
  if($service_code){ $sql.=" AND (service_code=? OR service_code IS NULL OR service_code='')"; $args[]=$service_code; }
  $sql.=" ORDER BY (kind='bag') DESC, cost ASC, container_cap_g ASC";
  $s=db()->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function cc_container_fits(array $row,array $parcel): bool {
  $cap=(int)($row['rule_cap_g']??0); if($cap<=0) $cap=(int)($row['container_cap_g']??0);
  $wg=(int)($parcel['weight_g']??0); if($cap>0 && $wg>0 && $wg>$cap) return false;
  $Lr=(int)($row['length_mm']??0); $Wr=(int)($row['width_mm']??0); $Hr=(int)($row['height_mm']??0);
  $Lp=(int)($parcel['length_mm']??0); $Wp=(int)($parcel['width_mm']??0); $Hp=(int)($parcel['height_mm']??0);
  if($Lr>0 && $Lp>0 && $Lp>$Lr) return false; if($Wr>0 && $Wp>0 && $Wp>$Wr) return false; if($Hr>0 && $Hp>0 && $Hp>$Hr) return false;
  return true;
}
function cc_best_container_for_parcel(string $carrier_code,?string $service_code,array $parcel,bool $prefer_satchel=true): ?array {
  $rows=cc_list_containers($carrier_code,$service_code); if(!$rows) return null;
  $passes=$prefer_satchel ? [['bag'],['bag','box','document','unknown']] : [['bag','box','document','unknown']];
  foreach($passes as $kinds){ foreach($rows as $r){ $kind=($r['kind']??'unknown'); if(!in_array($kind,$kinds,true)) continue; if(cc_container_fits($r,$parcel)) return $r; } }
  return $rows[0];
}
function cc_pick_containers(string $carrier_code,?string $service_code,array $parcels,bool $prefer_satchel=true): array {
  $result=[]; $total=0.0; foreach($parcels as $p){ $r=cc_best_container_for_parcel($carrier_code,$service_code,$p,$prefer_satchel); if($r){ $result[]=$r; $total+=(float)($r['cost']??0); } }
  return ['containers'=>$result,'est_cost'=>$total];
}

/* ========== Carrier Clients ========== */
final class GSSClient {
  private string $ak,$sid,$sup;
  public function __construct(array $a){ $this->ak=$a['access_key']; $this->sid=$a['site_id']; $this->sup=$a['supportemail']??'it@local'; }
  private function H(){ return ["Content-Type: application/json","access_key: {$this->ak}","site_id: {$this->sid}","supportemail: {$this->sup}"]; }
  private function http(string $m,string $u,?array $b=null): array {
    $ch=curl_init($u); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_HTTPHEADER=>$this->H(),CURLOPT_TIMEOUT=>30]);
    if($b!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($b));
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($out===false) throw new RuntimeException("GSS_HTTP_ERR:$err"); $j=json_decode($out,true); if($j===null && $code>=400) throw new RuntimeException("GSS_HTTP_$code:$out"); return [$code,$j,$out];
  }
  public function rates(array $payload): array { [$c,$j]=$this->http('POST','https://api.gosweetspot.com/api/rates',$payload); return $j??[]; }
  public function ship(array $payload): array { [$c,$j]=$this->http('POST','https://api.gosweetspot.com/api/shipments',$payload); return $j??[]; }
  public function labels(string $connote,string $format='LABEL_PDF'): array { [$c,$j,$raw]=$this->http('GET','https://api.gosweetspot.com/api/labels?format='.$format.'&connote='.rawurlencode($connote),null); return $j??[]; }
  public function cancel(string $connote): bool { [$c,$j,$raw]=$this->http('DELETE','https://api.gosweetspot.com/api/shipments?connote='.rawurlencode($connote),null); return $c>=200 && $c<300; }
}
final class NZPostClient {
  private array $a;
  public function __construct(array $auth){ $this->a=$auth; }
  public function labelLegacy(array $b): array {
    $b['api_key']=$this->a['api_key']; $ch=curl_init('https://api.nzpost.co.nz/labels/generate');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($b),CURLOPT_TIMEOUT=>30]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($out===false) throw new RuntimeException("NZP_HTTP_ERR:$err"); $j=json_decode($out,true); if(!$j || ($j['success']??false)!==true) throw new RuntimeException('NZP_LABEL_ERR:'.($j['message']??('HTTP '.$code))); return $j;
  }
  private function token(): string {
    $ch=curl_init('https://api.nzpost.co.nz/oauth2/token'); $data=http_build_query(['grant_type'=>'client_credentials','scope'=>'parcellabel.read parcellabel.write']);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_USERPWD=>$this->a['client_id'].':'.$this->a['client_secret'],CURLOPT_POSTFIELDS=>$data,CURLOPT_TIMEOUT=>20]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($out===false) throw new RuntimeException("NZP_OAUTH_ERR:$err"); $j=json_decode($out,true); if(!isset($j['access_token'])) throw new RuntimeException('NZP_OAUTH_HTTP_'.$code.':'.$out); return $j['access_token'];
  }
  public function labelOauth(array $payload): array {
    $tok=$this->token(); $ch=curl_init('https://api.nzpost.co.nz/parcellabel/v3/labels');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json',"Authorization: Bearer {$tok}"],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>30]);
    $out=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($out===false) throw new RuntimeException("NZP_HTTP_ERR:$err"); $j=json_decode($out,true); if(!$j || ($code<200||$code>=300)) throw new RuntimeException('NZP_LABEL_HTTP_'.$code.':'.$out); return $j;
  }
  public function cancelPlaceholder(string $labelId): bool { return true; }
}

/* ========== Router & Actions ========== */
$action = sval('action','');
$transferId = (int)(sval('transfer_id', jpost('transfer_id',0)) ?: 0);
$userId = (int)($_SESSION['user']['id'] ?? (defined('CIS_USER_ID')?CIS_USER_ID:0));

/* ---- rates ---- */
if($action==='rates'){
  if($transferId<=0) R::err('MISSING_TRANSFER');
  $t=get_transfer($transferId); $auth=carrier_creds($t['outlet_from']??'');
  $preferSatchel=(bool)jpost('prefer_satchel',true); $sig=(bool)jpost('sig_required',true);
  $par=jpost('parcels',[]); $domG=(int)jpost('dom_weight_g',0); $saturday=(bool)jpost('saturday',false); $pref=sval('pref_carrier','auto');
  if(!$par && $domG>0) $par=[['type'=>'satchel','length_mm'=>0,'width_mm'=>0,'height_mm'=>0,'weight_g'=>$domG]];

  $carrierCode=($auth['carrier']==='GSS' ? 'NZC' : $auth['carrier']); // GSS uses NZC catalog
  $service=sc_choose_service($carrierCode ?: 'NZ_POST', $par, $preferSatchel, $saturday);
  $service_meta=$service?['service_id'=>(int)$service['service_id'],'service_code'=>$service['code'],'service_name'=>$service['name']]:null;

  $containers=cc_pick_containers($carrierCode ?: 'NZ_POST', $service_meta['service_code']??null, $par, $preferSatchel);
  $container_meta=[ 'list'=>array_map(fn($r)=>[
    'container_id'=>(int)($r['container_id']??0),'container_code'=>$r['container_code']??'','container_name'=>$r['container_name']??'',
    'kind'=>$r['kind']??'unknown','length_mm'=>(int)($r['length_mm']??0),'width_mm'=>(int)($r['width_mm']??0),'height_mm'=>(int)($r['height_mm']??0),
    'cap_g'=>(int)($r['container_cap_g']??0),'cost'=>(float)($r['cost']??0)
  ],$containers['containers']), 'est_total_cost'=>(float)$containers['est_cost'] ];

  $rates=[];
  if($auth['carrier']==='GSS' || $pref==='GSS'){
    $g=new GSSClient($auth['gss']); $ship=ensure_shipment($transferId);
    $dest=[ "Name"=>$ship['dest_name'] ?: 'Destination',
      "Address"=>[ "StreetAddress"=>$ship['dest_addr1'] ?: 'Address', "Suburb"=>$ship['dest_suburb'] ?: '', "City"=>$ship['dest_city'] ?: '', "PostCode"=>$ship['dest_postcode'] ?: '', "CountryCode"=>"NZ" ],
      "Email"=>$ship['dest_email'] ?: 'noreply@example.com', "ContactPerson"=>$ship['dest_name'] ?: 'Receiver', "PhoneNumber"=>$ship['dest_phone'] ?: '' ];
    $pk=[]; foreach($par as $i=>$p){
      $kg=max(0.01,round(($p['weight_g']??0)/1000,2));
      $L=max(1,(int)($p['length_mm']??($container_meta['list'][$i]['length_mm']??1)));
      $W=max(1,(int)($p['width_mm'] ??($container_meta['list'][$i]['width_mm'] ??1)));
      $H=max(1,(int)($p['height_mm']??($container_meta['list'][$i]['height_mm']??1)));
      $pkg=[ "Name"=>($p['type']==='satchel'?"GSS-SATCHEL":"BOX"), "Length"=>$L, "Width"=>$W, "Height"=>$H, "Kg"=>$kg ];
      if(!empty($container_meta['list'][$i]['container_code'])) $pkg["PackageCode"]=$container_meta['list'][$i]['container_code'];
      $pk[]=$pkg;
    }
    $payload=["Destination"=>$dest,"IsSignatureRequired"=>$sig,"Packages"=>$pk]; if($saturday) $payload["SaturdayDelivery"]=true;
    $j=$g->rates($payload);
    foreach(($j['Available']??[]) as $row){
      $rates[]=[ 'provider'=>'GSS','carrier'=>$row['CarrierName']??'GSS','service'=>$row['DeliveryType']??'Standard','cost'=>(float)($row['Cost']??0),
        'note'=>trim(($row['Comments']??'').' '.($row['ServiceStandard']??'')),'quote_id'=>$row['QuoteId']??null,'is_satchel'=>(bool)(stripos($row['Comments']??'','satchel')!==false),
        'meta'=>['service'=>$service_meta,'containers'=>$container_meta] ];
    }
  }
  if(($auth['carrier']==='NZ_POST' || $pref==='NZ_POST') && !$rates){
    $est=(float)$container_meta['est_total_cost']; if($est<=0) { $est=10.00 + max(1,$domG)/500*1.50 + ($sig?1.0:0) + ($saturday?3.0:0); }
    $rates[]=[ 'provider'=>'NZ_POST','carrier'=>'NZ Post','service'=>($service_meta['service_code']??'Domestic'),
      'cost'=>round($est,2),'note'=>'From container price view','quote_id'=>null,'is_satchel'=>true,'meta'=>['service'=>$service_meta,'containers'=>$container_meta] ];
  }
  usort($rates,function($a,$b) use($preferSatchel){ $ac=$a['cost']??99999; $bc=$b['cost']??99999; $as=($a['is_satchel']??false)?0:1; $bs=($b['is_satchel']??false)?0:1; return $preferSatchel ? ($as<=>$bs ?: $ac<=>$bc) : ($ac<=>$bc); });
  R::ok(['rates'=>$rates,'chosen'=>$rates[0]??null]);
}

/* ---- validate_transfer ---- */
if($action==='validate_transfer'){
  $par=jpost('parcels',[]); $ready=true; $reasons=[]; if(!$par){ $ready=false; $reasons[]='NO_PARCELS'; }
  foreach($par as $p){ if(($p['weight_g']??0)<=0){ $ready=false; $reasons[]='BAD_WEIGHT'; break; } }
  R::ok(['ready'=>$ready,'reasons'=>$reasons]);
}

/* ---- buy_label ---- */
if($action==='buy_label'){
  if($transferId<=0) R::err('MISSING_TRANSFER'); $t=get_transfer($transferId); $ship=ensure_shipment($transferId);
  $userId=(int)($_SESSION['user']['id'] ?? (defined('CIS_USER_ID')?CIS_USER_ID:0)); ensure_lock($transferId,$userId);
  $finalize=((int)jpost('finalize',0))===1; $rate=jpost('rate',[]); $par=jpost('parcels',[]); $auth=carrier_creds($t['outlet_from']??'');
  $service_id=(int)($rate['meta']['service']['service_id']??0); $service_code=(string)($rate['meta']['service']['service_code']??''); $containers=$rate['meta']['containers']['list'] ?? [];
  if($auth['carrier']==='GSS'){
    $g=new GSSClient($auth['gss']); db()->beginTransaction();
    try{
      foreach($par as $i=>$p){ $L=(int)($p['length_mm']??($containers[$i]['length_mm']??0)); $W=(int)($p['width_mm']??($containers[$i]['width_mm']??0)); $H=(int)($p['height_mm']??($containers[$i]['height_mm']??0)); $G=(int)($p['weight_g']??0);
        db()->prepare("INSERT INTO transfer_parcels (shipment_id, box_number, weight_grams, length_mm, width_mm, height_mm, weight_kg, status, created_at) VALUES (?, (SELECT IFNULL(MAX(box_number),0)+1 FROM transfer_parcels WHERE shipment_id=?), ?, ?, ?, ?, ?, 'pending', NOW())")
          ->execute([$ship['id'],$ship['id'],$G,$L,$W,$H,($G>0?round($G/1000,2):null)]);
      }
      $dest=[ "Name"=>$ship['dest_name']?:'Destination',"Address"=>[ "BuildingName"=>"","StreetAddress"=>$ship['dest_addr1']?:'Address',"Suburb"=>$ship['dest_suburb']?:'',"City"=>$ship['dest_city']?:'',"PostCode"=>$ship['dest_postcode']?:'',"CountryCode"=>"NZ" ],"Email"=>$ship['dest_email']?:'noreply@example.com',"ContactPerson"=>$ship['dest_name']?:'Receiver',"PhoneNumber"=>$ship['dest_phone']?:'' ];
      $pk=[]; foreach($par as $i=>$p){ $L=(int)($p['length_mm']??($containers[$i]['length_mm']??1)); $W=(int)($p['width_mm']??($containers[$i]['width_mm']??1)); $H=(int)($p['height_mm']??($containers[$i]['height_mm']??1)); $G=max(0.01,round(($p['weight_g']??0)/1000,2));
        $pkg=[ "Name"=>($p['type']==='satchel'?"GSS-SATCHEL":"BOX"), "Length"=>$L,"Width"=>$W,"Height"=>$H,"Kg"=>$G ]; if(!empty($containers[$i]['container_code'])) $pkg["PackageCode"]=$containers[$i]['container_code']; $pk[]=$pkg; }
      $payload=["Destination"=>$dest,"Packages"=>$pk,"IsSignatureRequired"=>true]; if(!empty($rate['quote_id'])) $payload['QuoteId']=$rate['quote_id'];
      $j=$g->ship($payload); $cons=$j['Consignments'][0]??null; if(!$cons) throw new RuntimeException('GSS_SHIPMENT_EMPTY'); $connote=$cons['Connote']??''; $turl=$cons['TrackingUrl']??''; $carrier=$j['CarrierName']??'GSS';
      $labels=$g->labels($connote,'LABEL_PDF'); $b64=(is_array($labels)&&!empty($labels))?($labels[0]??null):null; $path=null; if($b64){ $dir=$_SERVER['DOCUMENT_ROOT'].'/labels'; if(!is_dir($dir)) @mkdir($dir,0775,true); $path='/labels/'.$connote.'.pdf'; file_put_contents($_SERVER['DOCUMENT_ROOT'].$path, base64_decode($b64)); }
      db()->prepare("INSERT INTO transfer_carrier_orders (transfer_id, carrier, order_id, order_number, payload) VALUES (?, 'GSS', ?, ?, ?)")->execute([$transferId,(string)$connote,'TR-'.$transferId,json_encode($j)]);
      db()->prepare("INSERT INTO transfer_labels (transfer_id, order_id, carrier_code, tracking, label_url, spooled, created_by, created_at) VALUES (?, NULL, 'GSS', ?, ?, 1, ?, NOW())")->execute([$transferId,(string)$connote,(string)$path,$userId]);
      db()->prepare("UPDATE transfer_shipments SET carrier_name=?, tracking_number=?, tracking_url=?, dispatched_at=NOW() WHERE id=?")->execute([$carrier,(string)$connote,(string)$turl,(int)$ship['id']]);
      db()->prepare("UPDATE transfer_parcels SET status='labelled', courier='GSS', tracking_number=?, label_url=? WHERE shipment_id=?")->execute([(string)$connote,(string)$path,(int)$ship['id']]);
      if($finalize){ db()->prepare("UPDATE transfers SET state='PACKAGED', updated_at=NOW() WHERE id=?")->execute([$transferId]); event_log($transferId,'PACKING_COMPLETED',['finalize'=>true]); }
      audit_log($transferId,'LABEL_PURCHASED','success',['provider'=>'GSS','connote'=>$connote,'service_id'=>$service_id,'service_code'=>$service_code,'containers'=>$containers]);
      db()->commit(); R::ok(['labels'=>[['carrier'=>'GSS','tracking'=>$connote,'label_url'=>$path]]]);
    }catch(Throwable $e){ db()->rollBack(); audit_log($transferId,'LABEL_PURCHASED','failed',['err'=>$e->getMessage()]); R::err('GSS_SHIP_ERR',['detail'=>$e->getMessage()],500); }
  } else {
    $nz=new NZPostClient($auth['nzpost']); db()->beginTransaction();
    try{
      foreach($par as $i=>$p){ $L=(int)($p['length_mm']??($containers[$i]['length_mm']??0)); $W=(int)($p['width_mm']??($containers[$i]['width_mm']??0)); $H=(int)($p['height_mm']??($containers[$i]['height_mm']??0)); $G=(int)($p['weight_g']??0);
        db()->prepare("INSERT INTO transfer_parcels (shipment_id, box_number, weight_grams, length_mm, width_mm, height_mm, weight_kg, status, created_at) VALUES (?, (SELECT IFNULL(MAX(box_number),0)+1 FROM transfer_parcels WHERE shipment_id=?), ?, ?, ?, ?, ?, 'pending', NOW())")
          ->execute([$ship['id'],$ship['id'],$G,$L,$W,$H,($G>0?round($G/1000,2):null)]);
      }
      $provider_code = $service_id ? sc_resolve_provider_code($service_id,'NZ_POST') : null; if(!$provider_code) $provider_code='PCM3C4';
      $p0=$par[0]??[]; $L=(int)($p0['length_mm']??($containers[0]['length_mm']??300)); $W=(int)($p0['width_mm']??($containers[0]['width_mm']??200)); $H=(int)($p0['height_mm']??($containers[0]['height_mm']??200)); $G=max(0.01,round(($p0['weight_g']??0)/1000,2));
      $payload=[ "labels"=>[[ "carrier"=>"NZP","domestic"=>[[ "to"=>[ "name"=>$ship['dest_name']??'Receiver', "company"=>$ship['dest_company']??'', "phone"=>$ship['dest_phone']??'', "email"=>$ship['dest_email']??'', "address"=>[ "line1"=>$ship['dest_addr1']??'Address', "suburb"=>$ship['dest_suburb']??'', "city"=>$ship['dest_city']??'', "postcode"=>$ship['dest_postcode']??'' ] ], "service_code"=>$provider_code, "packages"=>[[ "length"=>$L, "width"=>$W, "height"=>$H, "weight"=>$G ]] ]] ]] ];
      $j = ($auth['nzpost']['mode']??'label')==='parcellabel' && !empty($auth['nzpost']['client_id']) ? $nz->labelOauth($payload) : $nz->labelLegacy([ "destination_contact_name"=>$ship['dest_name']??'Receiver',"destination_street"=>$ship['dest_addr1']??'Address',"destination_city"=>$ship['dest_city']??'',"destination_country_code"=>"NZ","sender_contact_name"=>"The Vape Shed","sender_street"=>"Warehouse","sender_city"=>"Hamilton","service_code"=>$provider_code,"user_reference_code"=>"TR-{$transferId}-".time(),"parcel_length"=>$L,"parcel_height"=>$H,"parcel_width"=>$W,"parcel_unit_description"=>"Retail stock","parcel_unit_quantity"=>1,"parcel_unit_value"=>1.00,"parcel_unit_currency"=>"NZD","parcel_unit_weight"=>$G,"insurance_required"=>0,"documents"=>0,"non_delivery_instruction"=>"RETURN","validate_only"=>0,"skip_print"=>0,"force_regenerate"=>1 ]);
      $printUrl=$j['labels'][0]['files'][0]['url'] ?? ($j['print_url'] ?? null); $tracking=$j['labels'][0]['tracking_numbers'][0] ?? '';
      db()->prepare("INSERT INTO transfer_carrier_orders (transfer_id, carrier, order_id, order_number, payload) VALUES (?, 'NZ_POST', ?, ?, ?)")->execute([$transferId,(string)($tracking??''),'TR-'.$transferId,json_encode($j)]);
      db()->prepare("INSERT INTO transfer_labels (transfer_id, order_id, carrier_code, tracking, label_url, spooled, created_by, created_at) VALUES (?, NULL, 'NZ_POST', ?, ?, 1, ?, NOW())")->execute([$transferId,(string)$tracking,(string)$printUrl,$userId]);
      db()->prepare("UPDATE transfer_shipments SET carrier_name='NZ Post', tracking_number=?, tracking_url=?, dispatched_at=NOW() WHERE id=?")->execute([(string)$tracking,(string)$printUrl,(int)$ship['id']]);
      db()->prepare("UPDATE transfer_parcels SET status='labelled', courier='NZ_POST', tracking_number=?, label_url=? WHERE shipment_id=?")->execute([(string)$tracking,(string)$printUrl,(int)$ship['id']]);
      if($finalize){ db()->prepare("UPDATE transfers SET state='PACKAGED', updated_at=NOW() WHERE id=?")->execute([$transferId]); event_log($transferId,'PACKING_COMPLETED',['finalize'=>true]); }
      audit_log($transferId,'LABEL_PURCHASED','success',['provider'=>'NZ_POST','tracking'=>$tracking,'service_id'=>$service_id,'service_code'=>$service_code,'provider_code'=>$provider_code,'containers'=>$containers]);
      db()->commit(); R::ok(['labels'=>[['carrier'=>'NZ_POST','tracking'=>$tracking,'label_url'=>$printUrl]]]);
    }catch(Throwable $e){ db()->rollBack(); audit_log($transferId,'LABEL_PURCHASED','failed',['err'=>$e->getMessage()]); R::err('NZP_LABEL_ERR',['detail'=>$e->getMessage()],500); }
  }
}

/* ---- cancel_label ---- */
if($action==='cancel_label'){
  if($transferId<=0) R::err('MISSING_TRANSFER'); $t=get_transfer($transferId); $auth=carrier_creds($t['outlet_from']??'');
  $s=db()->prepare("SELECT * FROM transfer_labels WHERE transfer_id=? ORDER BY id DESC LIMIT 1"); $s->execute([$transferId]); $lab=$s->fetch(PDO::FETCH_ASSOC); if(!$lab) R::err('NO_LABEL');
  if($auth['carrier']==='GSS'){ try{ $g=new GSSClient($auth['gss']); if($g->cancel($lab['tracking'])){ db()->prepare("UPDATE transfer_parcels SET status='cancelled' WHERE transfer_id=?")->execute([$transferId]); db()->prepare("UPDATE transfer_shipments SET status='packed', tracking_number=NULL, tracking_url=NULL WHERE transfer_id=?")->execute([$transferId]); audit_log($transferId,'LABEL_CANCELLED','success',['provider'=>'GSS','connote'=>$lab['tracking']]); R::ok(['cancelled'=>true]); } }catch(Throwable $e){ R::err('GSS_CANCEL_ERR',['detail'=>$e->getMessage()],500);} }
  else { db()->prepare("UPDATE transfer_parcels SET status='cancelled' WHERE transfer_id=?")->execute([$transferId]); db()->prepare("UPDATE transfer_shipments SET status='packed', tracking_number=NULL, tracking_url=NULL WHERE transfer_id=?")->execute([$transferId]); audit_log($transferId,'LABEL_CANCELLED','success',['provider'=>'NZ_POST','note'=>'local_cancel']); R::ok(['cancelled'=>true]); }
}

/* ---- address_validate / address_save ---- */
if($action==='address_validate'){ $addr=jpost('address',[]); R::ok(['status'=>'OK','suggest'=>['formatted'=>true,'line1'=>$addr['line1']??null,'suburb'=>$addr['suburb']??null,'city'=>$addr['city']??null,'postcode'=>$addr['postcode']??null]]); }
if($action==='address_save'){ if($transferId<=0) R::err('MISSING_TRANSFER'); $addr=jpost('address',[]); $ship=ensure_shipment($transferId);
  $q="UPDATE transfer_shipments SET dest_name=?,dest_company=?,dest_addr1=?,dest_addr2=?,dest_suburb=?,dest_city=?,dest_postcode=?,dest_email=?,dest_phone=?, updated_at=NOW() WHERE id=?";
  db()->prepare($q)->execute([$addr['name']??null,$addr['company']??null,$addr['line1']??null,$addr['line2']??null,$addr['suburb']??null,$addr['city']??null,$addr['postcode']??null,$addr['email']??null,$addr['phone']??null,(int)$ship['id']]);
  R::ok(['saved'=>true]);
}

/* ---- manual_dispatch ---- */
if($action==='manual_dispatch'){
  if($transferId<=0) R::err('MISSING_TRANSFER'); $mode=sval('mode','manual'); $carrier=sval('carrier','MANUAL'); $tracking=sval('tracking',''); $ship=ensure_shipment($transferId);
  if($mode==='pickup'){ db()->prepare("UPDATE transfer_shipments SET delivery_mode='pickup', status='packed', dispatched_at=NOW(), carrier_name=?, tracking_number=? WHERE id=?")->execute([$carrier,$tracking,(int)$ship['id']]); }
  else { db()->prepare("UPDATE transfer_shipments SET delivery_mode='courier', status='packed', dispatched_at=NOW(), carrier_name=?, tracking_number=? WHERE id=?")->execute([$carrier,$tracking,(int)$ship['id']]); }
  db()->prepare("INSERT INTO transfer_labels (transfer_id, carrier_code, tracking, label_url, spooled, created_by, created_at) VALUES (?, ?, ?, '', 0, ?, NOW())")->execute([$transferId,(string)$carrier,(string)$tracking,(int)$GLOBALS['userId'] ?? 0]);
  audit_log($transferId,'MANUAL_DISPATCH','success',['mode'=>$mode,'carrier'=>$carrier,'tracking'=>$tracking]); R::ok(['saved'=>true]);
}

/* ---- prefs ---- */
if($action==='load_prefs'){ $s=db()->prepare("SELECT state_json FROM transfer_ui_sessions WHERE transfer_id=? AND user_id=?"); $s->execute([$transferId,$userId]); $row=$s->fetch(PDO::FETCH_ASSOC); $prefs=[]; if($row){ $j=json_decode($row['state_json']??'{}',true); if(isset($j['prefs'])) $prefs=$j['prefs']; } R::ok(['prefs'=>$prefs]); }
if($action==='save_prefs'){ $prefs=jpost('prefs',[]); $s=db()->prepare("INSERT INTO transfer_ui_sessions (transfer_id,user_id,state_json,autosave_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json), autosave_at=NOW()"); $s->execute([$transferId,$userId,json_encode(['prefs'=>$prefs])]); R::ok(['saved'=>true]); }

R::err('UNKNOWN_ACTION',['seen'=>$action],400);
