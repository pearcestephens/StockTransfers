<?php
/**
 * Pack Transfer Helper Functions
 * 
 * Utility functions used by pack transfer views
 * These functions were extracted from the original monolithic pack.php
 */

declare(strict_types=1);

/**
 * Clean arbitrary mixed->string without HTML; flatten JSON-ish text if detected.
 */
function tfx_clean_text(mixed $value): string
{
  $text = trim((string)$value);
  if ($text === '') return '';
  $first = $text[0] ?? '';
  if ($first === '{' || $first === '[') {
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
      $flat = [];
      array_walk_recursive($decoded, static function ($node) use (&$flat): void {
        if ($node === null) return;
        $node = trim((string)$node);
        if ($node !== '') $flat[] = $node;
      });
      if ($flat) $text = implode(', ', $flat);
    }
  }
  return trim($text);
}

/**
 * Return first non-empty (after clean) candidate.
 */
function tfx_first(array $candidates): string
{
  foreach ($candidates as $c) {
    $t = tfx_clean_text($c);
    if ($t !== '') return $t;
  }
  return '';
}

/**
 * Render product cell HTML (escaped).
 */
function tfx_render_product_cell(array $item): string
{
  $name    = tfx_first([$item['product_name'] ?? null, $item['name'] ?? null, $item['title'] ?? null]);
  $variant = tfx_first([$item['product_variant'] ?? null, $item['variant_name'] ?? null, $item['variant'] ?? null]);
  $sku     = tfx_clean_text($item['product_sku'] ?? $item['sku'] ?? $item['variant_sku'] ?? '');
  $id      = tfx_clean_text($item['product_id'] ?? $item['vend_product_id'] ?? $item['variant_id'] ?? '');
  if ($sku === '' && $id !== '') $sku = $id;

  $primary = $name !== '' ? $name : ($variant !== '' ? $variant : ($sku !== '' ? $sku : 'Product'));
  $primary = htmlspecialchars($primary, ENT_QUOTES, 'UTF-8');
  $skuLine = $sku !== '' ? '<div class="tfx-product-sku text-muted small">SKU: ' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '</div>' : '';

  return '<div class="tfx-product-cell"><strong class="tfx-product-name">' . $primary . '</strong>' . $skuLine . '</div>';
}

/**
 * Format outlet information line
 */
function tfx_outlet_line(array $outlet): string
{
  $parts = array_filter([
    $outlet['name']   ?? null,
    $outlet['city']   ?? null,
    $outlet['postcode'] ?? null,
    $outlet['country']  ?? 'New Zealand',
    $outlet['phone']    ?? null,
    $outlet['email']    ?? null,
  ], static function ($value): bool {
    return $value !== null && trim((string)$value) !== '';
  });

  return $parts ? implode(' | ', array_map(static fn($v) => trim((string)$v), $parts)) : '';
}

/**
 * Build an automatic package plan for the Dispatch console based on the aggregate transfer weight.
 *
 * @param int               $totalWeightGrams Approximate total consignment weight in grams (goods only).
 * @param int               $totalItems       Total item count being shipped.
 * @param FreightCalculator $freight          Freight calculator instance for capacity helpers.
 *
 * @return array<string,mixed>|null Structure describing the preferred container type and package breakdown.
 */
function tfx_build_dispatch_autoplan(int $totalWeightGrams, int $totalItems, $freight): ?array
{
  $totalWeightGrams = max(0, $totalWeightGrams);
  $totalItems       = max(0, $totalItems);

  if ($totalWeightGrams === 0 && $totalItems === 0) {
    return null;
  }

  $totalWeightKg = $totalWeightGrams / 1000;

  // Heuristic: satchels for lighter consignments, boxes otherwise (provide extra headroom below 15 kg cap).
  $preferSatchel = $totalWeightKg <= 12.0 && $totalItems <= 20;
  $container     = $preferSatchel ? 'satchel' : 'box';
  $capKg         = $container === 'satchel' ? 15.0 : 25.0;
  $tareReserveKg = $container === 'satchel' ? 0.25 : 2.5;
  $goodsCapKg    = max(1.0, $capKg - $tareReserveKg);
  $capGrams      = (int)round($goodsCapKg * 1000);

  $splitWeights = $freight->planParcelsByCap($totalWeightGrams > 0 ? $totalWeightGrams : 1, $capGrams);
  if (!$splitWeights) {
    $splitWeights = [$totalWeightGrams > 0 ? $totalWeightGrams : 1000];
  }

  $packageCount   = count($splitWeights);
  $baseItemsPer   = $packageCount > 0 ? intdiv($totalItems, $packageCount) : 0;
  $itemsRemainder = $packageCount > 0 ? $totalItems - ($baseItemsPer * $packageCount) : 0;

  $presetMeta = [
    'nzp_s' => ['w' => 22, 'l' => 31, 'h' => 4],
    'nzp_m' => ['w' => 28, 'l' => 39, 'h' => 4],
    'nzp_l' => ['w' => 33, 'l' => 42, 'h' => 4],
    'vs_m'  => ['w' => 30, 'l' => 40, 'h' => 20],
    'vs_l'  => ['w' => 35, 'l' => 45, 'h' => 25],
    'vs_xl' => ['w' => 40, 'l' => 50, 'h' => 30],
  ];

  $packages = [];
  foreach ($splitWeights as $index => $grams) {
    $goodsWeightKg = round(max(0, $grams) / 1000, 3);
    $presetId      = tfx_pick_autoplan_preset($container, $goodsWeightKg);
    $tareKg        = tfx_autoplan_tare($presetId);
    $shipWeightKg  = round($goodsWeightKg + $tareKg, 3);
    $itemsForBox   = $baseItemsPer + ($index < $itemsRemainder ? 1 : 0);

    $dims = $presetMeta[$presetId] ?? ($container === 'box'
      ? ['w' => 30, 'l' => 40, 'h' => 20]
      : ['w' => 28, 'l' => 39, 'h' => 4]);

    $packages[] = [
      'sequence'        => $index + 1,
      'preset_id'       => $presetId,
      'goods_weight_kg' => $goodsWeightKg,
      'ship_weight_kg'  => $shipWeightKg,
      'items'           => $itemsForBox,
      'dimensions'      => $dims,
    ];
  }

  return [
    'source'             => 'auto',
    'shouldHydrate'      => true,
    'container'          => $container,
    'cap_kg'             => $capKg,
    'goods_cap_kg'       => $goodsCapKg,
    'package_count'      => $packageCount,
    'total_weight_kg'    => round($totalWeightKg, 3),
    'total_weight_grams' => $totalWeightGrams,
    'total_items'        => $totalItems,
    'packages'           => $packages,
  ];
}

/**
 * Pick a default preset identifier for the calculated parcel weight.
 */
function tfx_pick_autoplan_preset(string $container, float $weightKg): string
{
  $weightKg = max(0, $weightKg);
  if ($container === 'box') {
    if ($weightKg > 20.0) return 'vs_xl';
    if ($weightKg > 14.0) return 'vs_l';
    return 'vs_m';
  }

  if ($weightKg > 9.0) return 'nzp_l';
  if ($weightKg > 5.0) return 'nzp_m';
  return 'nzp_s';
}

/**
 * Known tare weights for courier packaging presets (kg).
 */
function tfx_autoplan_tare(string $presetId): float
{
  return match ($presetId) {
    'nzp_s' => 0.15,
    'nzp_m' => 0.20,
    'nzp_l' => 0.25,
    'vs_m'  => 2.0,
    'vs_l'  => 2.5,
    'vs_xl' => 3.1,
    default => 0.0,
  };
}