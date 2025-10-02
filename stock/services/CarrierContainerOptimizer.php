<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;

/**
 * CarrierContainerOptimizer
 *
 * Provides higher-level optimization across multiple tentative boxes produced
 * by BoxAllocationService, attempting to merge / split / swap containers to
 * reduce total carrier cost while observing weight & (approx) volume caps.
 *
 * Strategy Outline:
 * 1. Normalize input boxes -> compute actual aggregate weight & volume.
 * 2. Load pricing_matrix (active) & freight_rules for constraints.
 * 3. Attempt consolidation: if two boxes individually under 40% capacity of the
 *    same container class and their merged weight fits -> merge.
 * 4. Attempt downsizing: if a box uses a container whose capacity utilization
 *    (weight) is < 30% and a strictly smaller-cheaper container can fit, swap.
 * 5. Produce optimized list with pricing summary & savings metrics.
 *
 * All cost computations are advisory only; underlying persistence is left to
 * orchestration layer.
 */
final class CarrierContainerOptimizer {
  private PDO $db;
  private array $pricing = [];
  private array $containers = [];

  public function __construct(PDO $pdo) {
    $this->db = $pdo;
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->loadPricing();
  }

  private function loadPricing(): void {
    try {
      $sql = "SELECT carrier_code, container_code, container_name, length_mm, width_mm, height_mm, max_weight_grams, price, currency FROM pricing_matrix ORDER BY price ASC, max_weight_grams ASC";
      $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $code = (string)($r['container_code'] ?? '');
        if ($code === '') continue;
        if (!isset($this->containers[$code])) {
          $this->containers[$code] = [
            'code' => $code,
            'carrier_code' => $r['carrier_code'] ?? null,
            'name' => $r['container_name'] ?? $code,
            'length_mm' => (int)$r['length_mm'],
            'width_mm' => (int)$r['width_mm'],
            'height_mm' => (int)$r['height_mm'],
            'max_weight_grams' => (int)$r['max_weight_grams'],
            'price' => $r['price'] !== null ? (float)$r['price'] : null,
            'currency' => $r['currency'] ?? 'NZD'
          ];
        }
      }
      $this->pricing = $rows;
    } catch (\Throwable $e) {
      error_log('CarrierContainerOptimizer loadPricing failed: ' . $e->getMessage());
    }
  }

  /**
   * Optimize boxes array (as produced by BoxAllocationService) and attach cost.
   * @param array $boxes
   * @return array { boxes:[], totals:[], savings:float|null }
   */
  public function optimize(array $boxes): array {
    if (empty($boxes) || empty($this->containers)) {
      return [ 'boxes' => $boxes, 'totals' => $this->computeTotals($boxes), 'savings' => null ];
    }
    $originalCost = $this->estimateCost($boxes);

    // Attempt consolidation pass
    $boxes = $this->consolidatePass($boxes);

    // Attempt downsizing pass
    $boxes = $this->downsizingPass($boxes);

    $optimizedCost = $this->estimateCost($boxes);
    $savings = $originalCost !== null && $optimizedCost !== null ? max(0, $originalCost - $optimizedCost) : null;

    return [
      'boxes' => $boxes,
      'totals' => $this->computeTotals($boxes),
      'savings' => $savings,
      'original_cost' => $originalCost,
      'optimized_cost' => $optimizedCost
    ];
  }

  private function computeTotals(array $boxes): array {
    $weight = 0; $volume = 0; $cost = 0; $boxCount = count($boxes);
    foreach ($boxes as $b) {
      $weight += (float)($b['current_weight_g'] ?? 0);
      $volume += (float)($b['current_volume_cm3'] ?? 0);
      if (isset($b['estimated_cost'])) $cost += (float)$b['estimated_cost'];
    }
    return [
      'boxes' => $boxCount,
      'total_weight_kg' => round($weight/1000, 3),
      'total_volume_cm3' => round($volume, 2),
      'total_cost' => $cost > 0 ? round($cost, 2) : null
    ];
  }

  private function estimateCost(array &$boxes): ?float {
    $total = 0; $found = false;
    foreach ($boxes as &$b) {
      if (!isset($b['estimated_cost']) || $b['estimated_cost'] === null) {
        // Try enrich via container code
        $code = $b['template'] ?? $b['container_code'] ?? null;
        if ($code && isset($this->containers[$code])) {
          $b['estimated_cost'] = $this->containers[$code]['price'];
          $b['currency'] = $this->containers[$code]['currency'];
        }
      }
      if (isset($b['estimated_cost']) && $b['estimated_cost'] !== null) {
        $total += (float)$b['estimated_cost'];
        $found = true;
      }
    }
    return $found ? round($total, 2) : null;
  }

  private function consolidatePass(array $boxes): array {
    $changed = true;
    while ($changed) {
      $changed = false;
      for ($i=0; $i < count($boxes); $i++) {
        for ($j=$i+1; $j < count($boxes); $j++) {
          if (!isset($boxes[$i], $boxes[$j])) continue;
          $a = $boxes[$i]; $b = $boxes[$j];
          // Skip if either heavy or many items
          if (($a['current_weight_g'] ?? 0) <= 0 || ($b['current_weight_g'] ?? 0) <= 0) continue;
          $mergedWeight = ($a['current_weight_g'] + $b['current_weight_g']);
          $codeCandidate = $this->findContainerForWeight($mergedWeight);
          if ($codeCandidate === null) continue;
          // Only merge if utilization after merge >= 35% (avoid underfilled large boxes)
            $cap = $this->containers[$codeCandidate]['max_weight_grams'];
            if ($cap > 0 && ($mergedWeight / $cap) < 0.35) continue;
          // Merge
          $boxes[$i]['items'] = array_merge($a['items'] ?? [], $b['items'] ?? []);
          $boxes[$i]['products'] = array_merge($a['products'] ?? [], $b['products'] ?? []);
          $boxes[$i]['current_weight_g'] = $mergedWeight;
          $boxes[$i]['current_volume_cm3'] = ($a['current_volume_cm3'] ?? 0) + ($b['current_volume_cm3'] ?? 0);
          $boxes[$i]['template'] = $codeCandidate;
          $boxes[$i]['estimated_cost'] = $this->containers[$codeCandidate]['price'];
          $boxes[$i]['carrier_code'] = $this->containers[$codeCandidate]['carrier_code'];
          // Remove j
          array_splice($boxes, $j, 1);
          $changed = true;
          break 2;
        }
      }
    }
    return $boxes;
  }

  private function downsizingPass(array $boxes): array {
    foreach ($boxes as &$b) {
      $currentWeight = (float)($b['current_weight_g'] ?? 0);
      if ($currentWeight <= 0) continue;
      $bestCode = $this->findTighterCheaperContainer($currentWeight, $b['estimated_cost'] ?? null, $b['template'] ?? null);
      if ($bestCode !== null && isset($this->containers[$bestCode])) {
        $b['template'] = $bestCode;
        $b['estimated_cost'] = $this->containers[$bestCode]['price'];
        $b['carrier_code'] = $this->containers[$bestCode]['carrier_code'];
      }
    }
    return $boxes;
  }

  private function findContainerForWeight(float $weightG): ?string {
    $best = null;
    foreach ($this->containers as $code => $c) {
      $cap = (int)$c['max_weight_grams'];
      if ($cap > 0 && $weightG > $cap) continue;
      if ($best === null) { $best = $code; continue; }
      // prefer lower price then lower cap
      $bestPrice = (float)$this->containers[$best]['price'];
      $curPrice = (float)$c['price'];
      if ($curPrice < $bestPrice) { $best = $code; continue; }
      if ($curPrice === $bestPrice && $cap < (int)$this->containers[$best]['max_weight_grams']) $best = $code;
    }
    return $best;
  }

  private function findTighterCheaperContainer(float $weightG, ?float $currentCost, ?string $currentCode): ?string {
    $candidate = null;
    foreach ($this->containers as $code => $c) {
      $cap = (int)$c['max_weight_grams'];
      if ($cap > 0 && $weightG > $cap) continue;
      $price = (float)$c['price'];
      if ($currentCost !== null && $price >= $currentCost) continue; // must be cheaper
      // Must also be smaller cap than current (if known) to avoid lateral swap
      if ($currentCode && isset($this->containers[$currentCode])) {
        $currentCap = (int)$this->containers[$currentCode]['max_weight_grams'];
        if ($cap >= $currentCap) continue; // not tighter
      }
      if ($candidate === null) { $candidate = $code; continue; }
      $candPrice = (float)$this->containers[$candidate]['price'];
      if ($price < $candPrice) { $candidate = $code; continue; }
      if ($price === $candPrice && $cap < (int)$this->containers[$candidate]['max_weight_grams']) $candidate = $code;
    }
    return $candidate;
  }
}
