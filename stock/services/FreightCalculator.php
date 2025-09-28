<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;

final class FreightCalculator {
  private PDO $db;

  public function __construct() {
    if (class_exists('\Core\DB') && method_exists('\Core\DB','instance')) {
      $pdo = \Core\DB::instance();
    } elseif (function_exists('cis_pdo')) {
      $pdo = cis_pdo();
    } else {
      $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!$pdo instanceof PDO) throw new \RuntimeException('DB not initialized');
    $this->db = $pdo;
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  public function getWeightedItems(int $transferId): array {
    $sql = <<<SQL
      SELECT
        ti.id AS id,
        ti.product_id AS product_id,
        COALESCE(NULLIF(ti.qty_sent_total,0),ti.qty_requested,0) AS qty,
        COALESCE(vp.avg_weight_grams,cw.avg_weight_grams,100) AS unit_weight_g
      FROM transfer_items ti
      LEFT JOIN vend_products vp ON vp.id=ti.product_id
      LEFT JOIN product_classification_unified pcu ON pcu.product_id=ti.product_id
      LEFT JOIN category_weights cw ON cw.category_id=pcu.category_id
      WHERE ti.transfer_id=:tid
      ORDER BY ti.id ASC
    SQL;
    $st = $this->db->prepare($sql);
    $st->execute(['tid'=>$transferId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $qty  = max(0,(int)$r['qty']);
      $unit = max(0,(int)$r['unit_weight_g']);
      $rows[] = [
        'id'=>(int)$r['id'],
        'product_id'=>(string)$r['product_id'],
        'qty'=>$qty,
        'unit_weight_g'=>$unit,
        'line_weight_g'=>$qty*$unit,
      ];
    }
    return $rows;
  }

  public function getRules(): array {
    $st = $this->db->query("SELECT container,max_weight_grams,max_units,cost,updated_at FROM freight_rules ORDER BY cost ASC,max_weight_grams ASC");
    $rules = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $code = (string)($row['container'] ?? '');
      $rules[] = [
        'container'=>$code,
        'max_weight_grams'=>isset($row['max_weight_grams'])?(int)$row['max_weight_grams']:null,
        'max_units'=>isset($row['max_units'])?(int)$row['max_units']:null,
        'cost'=>isset($row['cost'])?(float)$row['cost']:null,
        'updated_at'=>$row['updated_at']??null,
        'carrier_hint'=>$this->detectCarrier($code),
        'label'=>$this->humanizeContainer($code),
      ];
    }
    return $rules;
  }

  public function getRulesGroupedByCarrier(?array $rules=null): array {
    $rules = $rules ?? $this->getRules();
    $grouped=['nz_post'=>[],'gss'=>[],'manual'=>[]];
    foreach ($rules as $rule) {
      $carrier=$rule['carrier_hint'];
      if (!isset($grouped[$carrier])) $grouped[$carrier]=[];
      $grouped[$carrier][]=[
        'code'=>$rule['container'],
        'label'=>$rule['label'],
        'max_weight_grams'=>$rule['max_weight_grams'],
        'max_units'=>$rule['max_units'],
        'cost'=>$rule['cost'],
        'carrier'=>$carrier,
      ];
    }
    return $grouped;
  }

  public function pickRuleForCarrier(string $carrierCode,int $requiredGrams):?array {
    $carrierCode=$this->normalizeCarrierCode($carrierCode);
    $best=null;
    foreach ($this->getRules() as $rule) {
      if ($rule['carrier_hint']!==$carrierCode) continue;
      $cap=$rule['max_weight_grams']??null;
      if ($cap!==null && $cap>0 && $requiredGrams>$cap) continue;
      if ($best===null) { $best=$rule; continue; }
      $bestCost=$best['cost']??PHP_FLOAT_MAX;
      $ruleCost=$rule['cost']??PHP_FLOAT_MAX;
      if ($ruleCost<$bestCost) { $best=$rule; continue; }
      if ($ruleCost===$bestCost) {
        $bestCap=$best['max_weight_grams']??PHP_INT_MAX;
        $ruleCap=$rule['max_weight_grams']??PHP_INT_MAX;
        if ($ruleCap<$bestCap) $best=$rule;
      }
    }
    return $best;
  }

  public function getCarrierIdByCode(string $code): ?int {
    $st=$this->db->prepare("SELECT carrier_id FROM carriers WHERE LOWER(code) LIKE :c LIMIT 1");
    $st->execute(['c'=>strtolower($code).'%']);
    $id=$st->fetchColumn();
    return $id!==false?(int)$id:null;
  }

  public function pickContainer(int $carrierId,int $requiredGrams):?array {
    $sql=<<<SQL
      SELECT
        c.container_id,c.code,c.name,c.kind,c.length_mm,c.width_mm,c.height_mm,
        COALESCE(fr.max_weight_grams,c.max_weight_grams) AS cap_g,
        fr.cost
      FROM containers c
      LEFT JOIN freight_rules fr ON fr.container_id=c.container_id
      WHERE c.carrier_id=:cid
      AND c.soft_deleted=0
      AND (fr.soft_deleted=0 OR fr.soft_deleted IS NULL)
      AND (COALESCE(fr.max_weight_grams,c.max_weight_grams) IS NULL
           OR COALESCE(fr.max_weight_grams,c.max_weight_grams)>=:g)
      ORDER BY fr.cost ASC, COALESCE(fr.max_weight_grams,c.max_weight_grams) ASC, c.container_id ASC
      LIMIT 1
    SQL;
    $st=$this->db->prepare($sql);
    $st->execute(['cid'=>$carrierId,'g'=>$requiredGrams]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
  }

  public function planParcelsByCap(int $totalGrams,?int $capGrams):array {
    if ($totalGrams<=0) return [];
    if (empty($capGrams)) return [$totalGrams];
    $out=[]; $rem=$totalGrams;
    while ($rem>0) {
      $take=min($rem,$capGrams);
      $out[]=$take; $rem-=$take;
    }
    return $out;
  }

  public function normalizeCarrier(string $code):string {
    return $this->normalizeCarrierCode($code);
  }
  private function normalizeCarrierCode(string $code):string {
    $code=strtolower(trim($code));
    if ($code===''||$code==='manual') return 'manual';
    if ($code==='nzpost'||$code==='nz_post'||$code==='starshipit'||str_contains($code,'nz-post')) return 'nz_post';
    if ($code==='gss'||str_contains($code,'gosweetspot')||str_contains($code,'nz_courier')) return 'gss';
    return $code;
  }
  private function detectCarrier(string $code):string {
    $c=strtolower(trim($code));
    if ($c==='') return 'manual';
    if (str_starts_with($c,'cpol')||str_starts_with($c,'cpo')||str_contains($c,'nzpost')||str_contains($c,'nz_post')) return 'nz_post';
    if (str_starts_with($c,'gss')||str_contains($c,'nz courier')||str_contains($c,'nzcourier')||str_contains($c,'nz_courier')) return 'gss';
    if (str_contains($c,'manual')||str_contains($c,'driver')) return 'manual';
    return 'manual';
  }
  private function humanizeContainer(string $code):string {
    $trim=trim($code);
    if ($trim==='') return 'Custom';
    if (preg_match('/^[A-Z0-9_\-]+$/',$trim)) return $trim;
    return ucwords(str_replace(['_','-'],' ',strtolower($trim)));
  }
}
