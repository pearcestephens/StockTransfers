<?php
declare(strict_types=1);

/**
 * File: dispatch_boot.php
 * Purpose: Emit boot payload for the pack/send interface
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: transfer + outlet arrays provided by controller
 */

$transfer = $transfer ?? [];
$fromOutlet = $fromOutlet ?? [];
$toOutlet = $toOutlet ?? [];

$transferId = (int)($_GET['transfer'] ?? $transfer['id'] ?? 0);

$fromOutletUuid = isset($transfer['outlet_from_uuid']) && is_string($transfer['outlet_from_uuid'])
  ? strtolower(trim($transfer['outlet_from_uuid']))
  : (isset($_GET['from_outlet_uuid']) ? strtolower(trim((string)$_GET['from_outlet_uuid'])) : (isset($fromOutlet['id']) ? strtolower(trim((string)$fromOutlet['id'])) : ''));
$toOutletUuid = isset($transfer['outlet_to_uuid']) && is_string($transfer['outlet_to_uuid'])
  ? strtolower(trim($transfer['outlet_to_uuid']))
  : (isset($_GET['to_outlet_uuid']) ? strtolower(trim((string)$_GET['to_outlet_uuid'])) : (isset($toOutlet['id']) ? strtolower(trim((string)$toOutlet['id'])) : ''));

$fromLine = sprintf(
  '%s | %s | %s | New Zealand | %s | %s',
  $fromOutlet['name'] ?? 'Hamilton East',
  $fromOutlet['city'] ?? 'Hamilton',
  $fromOutlet['postcode'] ?? '3216',
  preg_replace('/\s+/', ' ', (string)($fromOutlet['phone'] ?? '')),
  $fromOutlet['email'] ?? ''
);

$tokens = [
  'apiKey' => getenv('CIS_API_KEY') ?: '',
  'nzPost' => $fromOutlet['nz_post_token'] ?? '',
  'gss'    => $fromOutlet['gss_token'] ?? '',
];

$BOOT = [
  'transferId' => $transferId,
  'fromOutletUuid' => $fromOutletUuid,
  'toOutletUuid' => $toOutletUuid,
  'fromLine' => $fromLine,
  'tokens' => $tokens,
  'legacy' => [
    'fromOutletId' => (int)($transfer['outlet_from'] ?? $fromOutlet['website_outlet_id'] ?? 0),
    'toOutletId'   => (int)($transfer['outlet_to']   ?? $toOutlet['website_outlet_id'] ?? 0),
  ],
  'modes' => [
    'pack_only' => true,
  ],
  'urls' => [
    'after_pack' => '/transfers',
  ],
  'capabilities' => [
    'modes' => [
      'PACKED_NOT_SENT',
      'COURIER_MANUAL_NZC',
      'COURIER_MANUAL_NZP',
      'PICKUP',
      'INTERNAL_DRIVE',
      'DEPOT_DROP',
    ],
  ],
  'modes' => [
    'pack_only' => true,
  ],
  'endpoints' => [
    'pack_send'     => '/modules/transfers/stock/api/pack_send.php',
    'rates'         => '/modules/transfers/stock/api/rates.php',
    'create'        => '/modules/transfers/stock/api/create_label.php',
    'address_facts' => '/modules/transfers/stock/api/address_facts.php',
  ],
];
?>
<script>window.DISPATCH_BOOT = <?= json_encode($BOOT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
