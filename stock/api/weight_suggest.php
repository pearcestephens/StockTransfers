<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../assets/functions/config.php';

header('Content-Type:application/json;charset=utf-8');

function ok(array $d): never {
  http_response_code(200);
  echo json_encode(['success'=>true]+$d, JSON_UNESCAPED_SLASHES);
  exit;
}
function bad(int $c,string $m,array $e=[]): never {
  http_response_code($c);
  echo json_encode(['success'=>false,'error'=>$m]+$e, JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $tid=(int)($_GET['transfer']??$_GET['t']??0);
  if($tid<=0) bad(400,'transfer is required');

  $fallbackCap=is_numeric($_GET['default_cap_kg']??null)?max(1,(float)$_GET['default_cap_kg']):18.0;

  $pdo=cis_pdo();
  if(!$pdo instanceof PDO) bad(500,'db unavailable');

  $q=$pdo->prepare("
    SELECT
      COUNT(*) AS items_count,
      ROUND(SUM(COALESCE(vp.avg_weight_grams,cw.avg_weight_grams,100)*GREATEST(COALESCE(NULLIF(ti.qty_sent_total,0),ti.qty_requested,0),0))/1000,3) AS total_kg,
      SUM(CASE WHEN COALESCE(vp.avg_weight_grams,cw.avg_weight_grams)<=0 OR COALESCE(vp.avg_weight_grams,cw.avg_weight_grams) IS NULL THEN 1 ELSE 0 END) AS missing_weights
    FROM transfer_items ti
    LEFT JOIN vend_products vp ON vp.id=ti.product_id
    LEFT JOIN product_classification_unified pcu ON pcu.product_id=ti.product_id
    LEFT JOIN category_weights cw ON cw.category_id=pcu.category_id
    WHERE ti.transfer_id=:tid
  ");
  $q->execute([':tid'=>$tid]);
  $w=$q->fetch(PDO::FETCH_ASSOC)?:['items_count'=>0,'total_kg'=>0,'missing_weights'=>0];

  $itemsCount=(int)$w['items_count'];
  $totalKg=max(0.0,(float)$w['total_kg']);
  $missingWeights=(int)$w['missing_weights'];

  $warnings=[];
  $capKg=$fallbackCap;

  try {
    $cap=$pdo->query("SELECT MAX(max_weight_grams) FROM containers WHERE max_weight_grams IS NOT NULL")->fetchColumn();
    if($cap && (float)$cap>0) $capKg=(float)$cap/1000.0;
  } catch(\Throwable $e) {}

  $boxes=[];
  $remaining=$totalKg;
  $loop=0;
  while($remaining>0.0001){
    $load=min($capKg,$remaining);
    $boxes[]=round($load,3);
    $remaining=round($remaining-$load,3);
    $loop++;
    if($loop>200){
      $warnings[]='Excessive box count abort';
      $boxes=[]; break;
    }
  }

  if($totalKg<=0) $boxes=[];

  $packages=[];
  foreach($boxes as $i=>$kg){
    $packages[]=[
      'name'=>'Box '.($i+1),
      'l_cm'=>40,'w_cm'=>30,'h_cm'=>20,
      'weight_kg'=>max(0.001,$kg),
      'qty'=>1,
      'ref'=>'T'.$tid.'-'.($i+1),
    ];
  }

  ok([
    'transfer_id'=>$tid,
    'items_count'=>$itemsCount,
    'total_weight_kg'=>round($totalKg,3),
    'missing_weights'=>$missingWeights,
    'plan'=>['cap_kg'=>$capKg,'boxes'=>count($boxes),'per_box_kg'=>$boxes],
    'packages'=>$packages,
    'warnings'=>$warnings,
  ]);
} catch(\Throwable $e) {
  bad(500,'weight_suggest failed',['exception'=>get_class($e)]);
}
