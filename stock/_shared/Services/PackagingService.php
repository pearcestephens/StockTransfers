<?php
/**
 * PackagingService
 * --------------------------------------------------------------------------
 * Purpose: Determine optimal package allocation, billable weight, and courier
 * tier pricing for a set of order line items. This is a *skeleton* awaiting
 * full integration (DB models, config injection, logging & audits).
 *
 * SECURITY NOTE: Do NOT expose raw courier API keys or Google API keys here.
 * Fetch from environment / secure vault provider.
 *
 * Usage (future):
 *   $svc = PackagingService::bootstrap($db, $logger, $config);
 *   $result = $svc->plan($lineItems, $destinationAddress, [ 'feature_flags' => [] ]);
 *
 * Line Item Shape (input):
 *   [
 *     ['product_id'=>123,'qty'=>2],
 *     ...
 *   ]
 *
 * Result Shape (draft): see WEIGHT_PACKAGING_AUTOMATION.md (section 6)
 *
 * Author: Automation Bot
 * Date: 2025-10-01
 */

class PackagingService
{
    private \PDO $db;
    private $logger; // PSR-3 expected
    private array $config;

    // Cached lookups
    private array $productCache = [];
    private array $packagingOptions = [];
    private array $rateTable = [];

    private function __construct(\PDO $db, $logger, array $config)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->config = $config;
    }

    public static function bootstrap(\PDO $db, $logger, array $config): self
    {
        $svc = new self($db, $logger, $config);
        $svc->loadPackagingOptions();
        $svc->loadRateTable();
        return $svc;
    }

    /**
     * Plan full allocation & pricing.
     * @param array $lineItems
     * @param array $destination ['address_line','city','postcode','country']
     * @param array $options ['feature_flags'=>[], 'force_zone'=>null]
     */
    public function plan(array $lineItems, array $destination, array $options = []): array
    {
        $flags = $options['feature_flags'] ?? [];
        if (!($flags['PACK_AUTOMATION_ENABLED'] ?? false)) {
            return [ 'success' => false, 'error' => [ 'code' => 'FEATURE_DISABLED', 'message' => 'Packaging automation disabled' ] ];
        }

        $warnings = [];
        $items = $this->expandAndEnrich($lineItems, $warnings);
        if (empty($items)) {
            return [ 'success' => false, 'error' => [ 'code' => 'NO_ITEMS', 'message' => 'No valid items supplied' ] ];
        }

        $zone = $options['force_zone'] ?? $this->resolveZone($destination, $warnings);
        $packages = $this->allocate($items, $warnings);
        $this->calculateWeights($packages, $warnings);
        $this->pricePackages($packages, $zone, $warnings);

        $totals = $this->summarize($packages, $warnings);

        return [
            'success' => true,
            'packages' => $packages,
            'totals' => $totals,
            'warnings' => $warnings,
            'version' => '0.1-skeleton'
        ];
    }

    private function expandAndEnrich(array $lineItems, array &$warnings): array
    {
        $expanded = [];
        foreach ($lineItems as $li) {
            $pid = (int)($li['product_id'] ?? 0); $qty = (int)($li['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $meta = $this->fetchProduct($pid);
            if (!$meta) { $warnings[] = "missing_product:$pid"; continue; }
            for ($i=0;$i<$qty;$i++) {
                $expanded[] = [
                    'product_id' => $pid,
                    'weight_g' => $meta['weight_g'] ?? null,
                    'length_cm' => $meta['length_cm'] ?? null,
                    'width_cm' => $meta['width_cm'] ?? null,
                    'height_cm' => $meta['height_cm'] ?? null,
                    'hazmat' => (bool)($meta['hazmat_flag'] ?? false),
                    'fragile' => (bool)($meta['fragile_flag'] ?? false),
                ];
            }
        }
        // Sort heavy/volumetric first (placeholder: sort by weight desc)
        usort($expanded, function($a,$b){ return ($b['weight_g'] ?? 0) <=> ($a['weight_g'] ?? 0); });
        return $expanded;
    }

    private function fetchProduct(int $productId): ?array
    {
        if (isset($this->productCache[$productId])) return $this->productCache[$productId];
        $stmt = $this->db->prepare("SELECT product_id, weight_g, length_cm, width_cm, height_cm, hazmat_flag, fragile_flag FROM products WHERE product_id = :pid");
        if (!$stmt->execute([':pid' => $productId])) return null;
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) $this->productCache[$productId] = $row;
        return $row ?: null;
    }

    private function resolveZone(array $destination, array &$warnings): string
    {
        // Placeholder: implement postcode → zone mapping.
        $postcode = $destination['postcode'] ?? null;
        if (!$postcode) { $warnings[] = 'missing_postcode'; return 'NATIONAL'; }
        // Example stub: local if starts with 32 (Hamilton), else NATIONAL
        if (preg_match('/^32/',$postcode)) return 'LOCAL';
        return 'NATIONAL';
    }

    private function allocate(array $items, array &$warnings): array
    {
        $packages = [];
        foreach ($items as $it) {
            $placed = false;
            foreach ($packages as &$pkg) {
                if ($this->canFit($pkg, $it)) { $pkg['items'][] = $it; $placed = true; break; }
            }
            if (!$placed) {
                $pkgTemplate = $this->smallestPackagingFor($it, $warnings);
                $packages[] = [ 'packaging_code' => $pkgTemplate['code'], 'capacity' => $pkgTemplate, 'items' => [$it] ];
            }
        }
        return $packages;
    }

    private function canFit(array $pkg, array $item): bool
    {
        $cap = $pkg['capacity'];
        $currentWeight = array_sum(array_map(fn($i)=>$i['weight_g'] ?? 0, $pkg['items']));
        $newWeight = $currentWeight + ($item['weight_g'] ?? 0);
        if ($newWeight > ($cap['max_weight_g'] ?? PHP_INT_MAX)) return false;
        // Volume & hazard checks TODO
        return true;
    }

    private function smallestPackagingFor(array $item, array &$warnings): array
    {
        foreach ($this->packagingOptions as $opt) {
            if (($item['weight_g'] ?? 0) <= ($opt['max_weight_g'] ?? PHP_INT_MAX)) return $opt;
        }
        $warnings[] = 'no_packaging_match';
        return [ 'code' => 'FALLBACK', 'max_weight_g' => PHP_INT_MAX ];
    }

    private function calculateWeights(array &$packages, array &$warnings): void
    {
        $divisor = (int)($this->config['PACKAGING_VOLUME_DIVISOR'] ?? 5000);
        foreach ($packages as &$p) {
            $actual = 0; $volCC = 0; $volMissing = false;
            foreach ($p['items'] as $it) {
                $w = $it['weight_g'] ?? null;
                if ($w === null) { $w = (int)($this->config['DEFAULT_ITEM_WEIGHT_G'] ?? 50); $warnings[] = 'missing_weight:'.$it['product_id']; }
                $actual += $w;
                if (($it['length_cm'] ?? null) && ($it['width_cm'] ?? null) && ($it['height_cm'] ?? null)) {
                    $volCC += ($it['length_cm'] * $it['width_cm'] * $it['height_cm']);
                } else {
                    $volMissing = true;
                }
            }
            $volumetricG = $volCC > 0 ? (int) round(($volCC / $divisor) * 1000) : 0;
            $billable = max($actual, $volumetricG);
            if ($volMissing && $volCC === 0) $warnings[] = 'volume_incomplete_package';
            $p['actual_weight_g'] = $actual;
            $p['volumetric_weight_g'] = $volumetricG;
            $p['billable_weight_g'] = $billable;
        }
    }

    private function pricePackages(array &$packages, string $zone, array &$warnings): void
    {
        foreach ($packages as &$p) {
            $rate = $this->findRate($zone, $p['billable_weight_g']);
            if (!$rate) { $warnings[] = 'missing_rate:'.$zone; $p['service_code'] = null; $p['rate_price'] = 0; continue; }
            $p['service_code'] = $rate['service_code'];
            $base = (float)$rate['price'];
            $surchargePct = (float)($rate['fuel_surcharge_pct'] ?? 0);
            $fuel = $base * ($surchargePct/100);
            $p['rate_price'] = $base;
            $p['fuel_surcharge'] = round($fuel,2);
            $p['total_package_price'] = round($base + $fuel,2);
            $p['zone'] = $zone;
        }
    }

    private function summarize(array $packages, array $warnings): array
    {
        $totActual = 0; $totBillable = 0; $ship = 0; $count = count($packages);
        foreach ($packages as $p) {
            $totActual += $p['actual_weight_g'] ?? 0;
            $totBillable += $p['billable_weight_g'] ?? 0;
            $ship += $p['total_package_price'] ?? 0;
        }
        return [
            'actual_weight_g' => $totActual,
            'billable_weight_g' => $totBillable,
            'package_count' => $count,
            'shipping_subtotal' => round($ship,2),
            'warning_count' => count($warnings)
        ];
    }

    private function loadPackagingOptions(): void
    {
        // Placeholder - expect DB table packaging_options
        try {
            $stmt = $this->db->query("SELECT code, max_weight_g FROM packaging_options WHERE active=1 ORDER BY max_weight_g ASC");
            $this->packagingOptions = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $this->logger && $this->logger->warning('packaging_options_load_failed', ['err'=>$e->getMessage()]);
            $this->packagingOptions = [];
        }
        if (empty($this->packagingOptions)) {
            // Fallback minimal ladder
            $this->packagingOptions = [
                ['code'=>'BAG-S','max_weight_g'=>500],
                ['code'=>'BAG-M','max_weight_g'=>2000],
                ['code'=>'BAG-L','max_weight_g'=>5000],
                ['code'=>'CARTON-A','max_weight_g'=>15000],
            ];
        }
    }

    private function loadRateTable(): void
    {
        try {
            $stmt = $this->db->query("SELECT zone, weight_bracket_g, service_code, price, fuel_surcharge_pct FROM courier_rates WHERE active=1 ORDER BY zone, weight_bracket_g ASC");
            $this->rateTable = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $this->logger && $this->logger->warning('courier_rates_load_failed', ['err'=>$e->getMessage()]);
            $this->rateTable = [];
        }
    }

    private function findRate(string $zone, int $billableWeight): ?array
    {
        $candidates = array_filter($this->rateTable, fn($r)=>$r['zone']===$zone);
        foreach ($candidates as $r) {
            if ($billableWeight <= (int)$r['weight_bracket_g']) return $r;
        }
        return null;
    }
}
