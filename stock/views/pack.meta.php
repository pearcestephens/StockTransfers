<?php
declare(strict_types=1);

$tid = (int)($_GET['transfer'] ?? 0);

return [
  'title'      => $tid > 0 ? "Pack Transfer #{$tid}" : 'Pack Transfer',
  'breadcrumb' => [
    ['label' => 'Transfers', 'href' => '/modules/transfers'],
    ['label' => 'Stock'],
    ['label' => $tid > 0 ? "Pack #{$tid}" : 'Pack'],
  ],
  'assets'     => [
    // keep your existing styles, add the unified ship UI
    'css' => [
      '/assets/css/transfers-common.css',
      '/assets/css/transfers-pack.css',
      '/assets/css/stock-transfers/ship-ui.css',
    ],
    // keep pack/common; replace legacy shipping with ship-ui.js
    'js'  => [
      '/assets/js/transfers-common.js',
      '/assets/js/transfers-pack.js',
      '/assets/js/stock-transfers/ship-ui.js',
    ],
  ],
];
