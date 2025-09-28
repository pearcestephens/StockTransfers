<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;
use Throwable;

/**
 * ProductSearchService
 * Lightweight search across vend_products (and optional inventory table) for transfer packing UI.
 *
 * Assumptions (adjust if schema differs):
 * - Table vend_products(id, name, variant_name, sku, handle, brand, price, image_url)
 * - Optional inventory table vend_inventory(product_id, outlet_id, quantity)
 * - Price stored inclusive of tax in vend_products.price (decimal or numeric)
 * - image_url may be NULL; provide placeholder if missing.
 */
final class ProductSearchService
{
    private PDO $db;
    private static ?bool $hasUpdatedAt = null; // cache detection so we only probe once
    /**
     * Simple in-memory static cache for recent queries.
     * @var array<string,array{ts:int,result:array}>
     */
    private static array $cache = [];
    private const CACHE_TTL = 45;          // seconds
    private const CACHE_MAX_ENTRIES = 50;  // soft limit
    private static ?string $inventoryQtyCol = null; // detected inventory quantity column

    public function __construct()
    {
        if (class_exists('\\Core\\DB') && method_exists('\\Core\\DB', 'instance')) {
            $pdo = \Core\DB::instance();
        } elseif (function_exists('cis_pdo')) {
            $pdo = cis_pdo();
        } elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            throw new \RuntimeException('DB not initialized');
        }
        if (!$pdo instanceof PDO) throw new \RuntimeException('No PDO instance');
        $this->db = $pdo;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Search products by free-text token(s).
     * @param string $q Raw search query
     * @param int $limit Max rows
     * @param int|null $outletId If provided, join inventory to show stock at that outlet
     * @return array{success:bool,products:array<int,array<string,mixed>>,error?:string}
     */
    public function search(string $q, int $limit = 25, ?int $outletId = null, bool $includeOld = false): array
    {
        $q = trim($q);
        if ($q === '') return ['success' => true, 'products' => []];
        $limit = max(1, min(100, $limit));

        // --- CACHE LOOKUP --- (includeOld always false currently)
        $cacheKey = strtolower($q).'|'.($outletId ?? 0).'|'.$limit;
        if (isset(self::$cache[$cacheKey])) {
            $entry = self::$cache[$cacheKey];
            if (($entry['ts'] + self::CACHE_TTL) > time()) {
                // Return cached copy (deep copy not necessary for read-only usage)
                return $entry['result'];
            }
            unset(self::$cache[$cacheKey]);
        }

        // --- SCHEMA DISCOVERY (cache per request) ---
        static $schemaCols = null;
        if ($schemaCols === null) {
            $schemaCols = [];
            try {
                $colStmt = $this->db->query('SHOW COLUMNS FROM vend_products');
                foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
                    $schemaCols[strtolower($cRow['Field'])] = true;
                }
            } catch (Throwable $e) {
                // If show columns fails we proceed with assumed base set
            }
        }
        $has = static function(string $c) use ($schemaCols): bool { return isset($schemaCols[strtolower($c)]); };

        // Determine selectable columns safely
        $colName       = $has('name');
        $colVariant    = $has('variant_name');
        $colSku        = $has('sku');
        $colHandle     = $has('handle');
        $colBrand      = $has('brand');
        $colImage      = $has('image_url') || $has('image');
        // Price & RRP candidate lists (auto-detect first match)
        $priceField = null; $rrpField = null;
        foreach (['price','price_incl','price_inc','retail_price','sell_price','sellprice','price_nz','price_inc_gst'] as $pf) {
            if ($has($pf)) { $priceField = $pf; break; }
        }
        foreach (['rrp','rrp_price','msrp','recommended_retail','retail_rrp','list_price'] as $rf) {
            if ($has($rf)) { $rrpField = $rf; break; }
        }

        // Build SELECT columns dynamically
        $selectCols = ['vp.id'];
        if ($colName)    $selectCols[] = 'vp.name'; else $selectCols[] = 'NULL AS name';
        if ($colVariant) $selectCols[] = 'vp.variant_name'; else $selectCols[] = 'NULL AS variant_name';
        if ($colSku)     $selectCols[] = 'vp.sku'; else $selectCols[] = 'NULL AS sku';
        if ($colHandle)  $selectCols[] = 'vp.handle'; else $selectCols[] = 'NULL AS handle';
        if ($colBrand)   $selectCols[] = 'vp.brand'; else $selectCols[] = 'NULL AS brand';
    if ($priceField) $selectCols[] = 'vp.`'.$priceField.'` AS price'; else $selectCols[] = 'NULL AS price';
    if ($rrpField)   $selectCols[] = 'vp.`'.$rrpField.'` AS rrp';   else $selectCols[] = 'NULL AS rrp';
        if ($colImage)   $selectCols[] = ($has('image_url') ? 'vp.image_url' : 'vp.image AS image_url'); else $selectCols[] = 'NULL AS image_url';

    // Prepare dynamic WHERE for tokens based on existing columns
        $tokens  = preg_split('/\s+/', $q) ?: [];
        $wheres  = [];
        $params  = [];
        $i       = 0;
        $scoreParts = [];
        // Columns we can search
        $searchCols = [];
        if ($colName)    $searchCols[] = 'vp.name';
        if ($colVariant) $searchCols[] = 'vp.variant_name';
        if ($colSku)     $searchCols[] = 'vp.sku';
        if ($colHandle)  $searchCols[] = 'vp.handle';
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $i++;
            $param = ':t'.$i;
            $pattern = str_replace('*', '%', $tok);
            if (strpos($pattern, '%') === false) {
                $pattern = '%'.$pattern.'%';
            } else {
                $pattern = preg_replace('/%{2,}/','%',$pattern);
            }
            $params[$param] = $pattern;
            if ($searchCols) {
                $likeParts = [];
                foreach ($searchCols as $sc) { $likeParts[] = "$sc LIKE $param"; }
                $wheres[] = '('.implode(' OR ', $likeParts).')';
            }
            if (ctype_digit(str_replace(['%'], '', $pattern)) && strpos($tok,'*')===false) {
                $params[':id'.$i] = (int)$tok;
                $wheres[] = "(vp.id = :id$i)";
                $scoreParts[] = "(CASE WHEN vp.id = :id$i THEN 120 ELSE 0 END)";
            }
            $rawToken = trim(str_replace(['%'], '', $pattern));
            $eqToken  = $rawToken;
            $likeTok  = $param; // reuse LIKE param for substring scoring

            // Only create equality/prefix params if at least one relevant column exists
            if ($colSku) {
                $eqSku = ':eq_sku_'.$i; $params[$eqSku] = $eqToken; $scoreParts[] = "(CASE WHEN vp.sku = $eqSku THEN 100 ELSE 0 END)"; }
            if ($colHandle) {
                $eqHandle=':eq_handle_'.$i; $params[$eqHandle]=$eqToken; $scoreParts[] = "(CASE WHEN vp.handle = $eqHandle THEN 90 ELSE 0 END)"; }
            if ($colName || $colVariant) {
                $prefix = ':pf_'.$i; $params[$prefix] = $eqToken.'%';
                if ($colName)    $scoreParts[] = "(CASE WHEN vp.name LIKE $prefix THEN 70 ELSE 0 END)";
                if ($colVariant) $scoreParts[] = "(CASE WHEN vp.variant_name LIKE $prefix THEN 55 ELSE 0 END)";
            }
            if ($colSku)     $scoreParts[] = "(CASE WHEN vp.sku LIKE $likeTok THEN 40 ELSE 0 END)";
            if ($colName)    $scoreParts[] = "(CASE WHEN vp.name LIKE $likeTok THEN 30 ELSE 0 END)";
            if ($colVariant) $scoreParts[] = "(CASE WHEN vp.variant_name LIKE $likeTok THEN 22 ELSE 0 END)";
            if ($colHandle)  $scoreParts[] = "(CASE WHEN vp.handle LIKE $likeTok THEN 18 ELSE 0 END)";
        }
        if (!$wheres) return ['success' => true, 'products' => []];

        // --- FILTER OLD / ARCHIVED PRODUCTS ---
        // We attempt to detect archival/inactive columns. Common patterns: is_active, active, archived, is_archived, status
        // Heuristic: exclude rows where (archived=1 OR is_archived=1 OR status IN('archived','deleted','inactive')) OR active/is_active = 0
        // Only applied when $includeOld is false.
        if (!$includeOld) {
            $archiveConds = [];
            foreach (['is_archived','archived'] as $col) if ($has($col)) $archiveConds[] = "(vp.`$col` = 1)";
            foreach (['is_active','active'] as $col) if ($has($col)) $archiveConds[] = "(vp.`$col` = 0)";
            if ($has('status')) $archiveConds[] = "(LOWER(vp.`status`) IN ('archived','deleted','inactive'))";
            if ($archiveConds) {
                $wheres[] = '(NOT (' . implode(' OR ', $archiveConds) . '))';
            }
            // If we have an updated_at column and also a created_at, treat extremely old products (no update in > 540 days) as stale if not recently updated.
            if ($has('updated_at')) {
                $wheres[] = '(vp.updated_at IS NULL OR vp.updated_at > (NOW() - INTERVAL 540 DAY))';
            }
        }

        $selectInv = '';
        $joinInv   = '';
        if ($outletId !== null && $outletId > 0) {
            // Try detect inventory table & quantity column once
            if (self::$inventoryQtyCol === null) {
                self::$inventoryQtyCol = ''; // sentinel for attempted
                try {
                    $colStmt = $this->db->query('SHOW COLUMNS FROM vend_inventory');
                    $cols = [];
                    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
                        $cols[strtolower($cRow['Field'])] = true;
                    }
                    foreach (['inventory_level','quantity','qty','stock_qty','on_hand','onhand','level'] as $candidate) {
                        if (isset($cols[$candidate])) { self::$inventoryQtyCol = $candidate; break; }
                    }
                    if (self::$inventoryQtyCol === '') self::$inventoryQtyCol = null; // none found
                } catch (Throwable $e) {
                    self::$inventoryQtyCol = null; // table missing
                }
            }
            if (self::$inventoryQtyCol) {
                $col = self::$inventoryQtyCol;
                // Aggregate inventory by product/outlet to avoid duplicate rows when multiple inventory rows exist
                $selectInv = ', vi.stock_qty AS stock_qty';
                $joinInv   = 'LEFT JOIN (SELECT product_id, outlet_id, SUM(`'.$col.'`) AS stock_qty FROM vend_inventory GROUP BY product_id, outlet_id) vi ON vi.product_id = vp.id AND vi.outlet_id = :outletId';
                $params[':outletId'] = $outletId;
            }
        }

        // Determine ordering (MariaDB/MySQL do not support "NULLS LAST" keyword). Detect updated_at column once.
        if (self::$hasUpdatedAt === null) {
            try {
                $this->db->query('SELECT `updated_at` FROM `vend_products` LIMIT 1');
                self::$hasUpdatedAt = true;
            } catch (Throwable $e) {
                self::$hasUpdatedAt = false;
            }
        }
        $baseOrderParts = self::$hasUpdatedAt
            ? ['(vp.updated_at IS NULL) ASC', 'vp.updated_at DESC', 'vp.id DESC']
            : ['vp.id DESC'];

        $scoreExpr = $scoreParts ? implode(' + ', $scoreParts) : '0';
        // Prepend relevance_score DESC before base ordering
        $orderClause = ' ORDER BY relevance_score DESC, '.implode(', ', $baseOrderParts);

    $sql = 'SELECT DISTINCT '.implode(', ', $selectCols).', (' . $scoreExpr . ') AS relevance_score'
             . $selectInv . ' FROM vend_products vp '
             . $joinInv
             . ' WHERE ' . implode(' AND ', $wheres)
             . $orderClause
             . ' LIMIT ' . $limit;

        // Rewrite duplicate placeholders (MySQL native prepared statements do not support reuse reliably)
        $dupMap = [];
        if (preg_match_all('/:([a-zA-Z0-9_]+)/', $sql, $allPH, PREG_OFFSET_CAPTURE)) {
            $counts = [];
            $repls = [];
            foreach ($allPH[0] as $entry) {
                $ph = $entry[0];
                $counts[$ph] = ($counts[$ph] ?? 0) + 1;
                if ($counts[$ph] > 1) {
                    $newPh = $ph.'_r'.$counts[$ph];
                    $repls[] = [$entry[1], strlen($ph), $newPh, $ph];
                }
            }
            if ($repls) {
                usort($repls, static function($a,$b){ return $b[0] <=> $a[0]; });
                foreach ($repls as [$pos,$len,$new,$orig]) {
                    $sql = substr($sql,0,$pos).$new.substr($sql,$pos+$len);
                    $params[$new] = $params[$orig] ?? '';
                }
            }
        }
        // Final placeholder list (ordered) and binding
        $placeholdersOrdered = [];
        if (preg_match_all('/:[a-zA-Z0-9_]+/', $sql, $finalPH)) {
            foreach ($finalPH[0] as $p) $placeholdersOrdered[] = $p;
        }
        // Prune any unused params
        $usedSet = array_flip($placeholdersOrdered);
        foreach (array_keys($params) as $k) if (!isset($usedSet[$k])) unset($params[$k]);
        // Ensure defaults
        foreach ($placeholdersOrdered as $ph) if (!isset($params[$ph])) $params[$ph] = '';
        $st = $this->db->prepare($sql);
        foreach ($placeholdersOrdered as $ph) {
            $v = $params[$ph];
            $st->bindValue($ph, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $products = [];
        foreach ($rows as $r) {
            $products[] = [
                'id'          => (int)$r['id'],
                'name'        => $r['name'] ?? null,
                'variant'     => $r['variant_name'] ?? null,
                'sku'         => $r['sku'] ?? null,
                'handle'      => $r['handle'] ?? null,
                'brand'       => $r['brand'] ?? null,
                'price'       => isset($r['price']) ? (float)$r['price'] : null,
                'rrp'         => isset($r['rrp']) ? (float)$r['rrp'] : null,
                'image_url'   => $r['image_url'] ?? null,
                'stock_qty'   => isset($r['stock_qty']) ? (int)$r['stock_qty'] : null,
                'score'       => isset($r['relevance_score']) ? (int)$r['relevance_score'] : 0,
            ];
        }
        $result = ['success' => true, 'products' => $products];

        // --- STORE IN CACHE --- (evict oldest if over max entries)
        self::$cache[$cacheKey] = ['ts' => time(), 'result' => $result];
        if (count(self::$cache) > self::CACHE_MAX_ENTRIES) {
            // Evict oldest
            asort(array_column(self::$cache, 'ts')); // can't directly sort sub array; custom pass
            // Simpler manual prune
            $byTs = [];
            foreach (self::$cache as $k => $v) { $byTs[$k] = $v['ts']; }
            asort($byTs, SORT_NUMERIC);
            $excess = count($byTs) - self::CACHE_MAX_ENTRIES;
            foreach ($byTs as $k => $_) { if ($excess-- <= 0) break; unset(self::$cache[$k]); }
        }

        return $result;
    }
}
