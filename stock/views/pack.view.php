<?php
/**
 * Pack Transfer View
 * 
 * Main view template for the pack transfer page using modular components
 * 
 * This replaces the monolithic pack.php with reusable view components
 * All business logic should be handled before including this view
 * 
 * Expected variables:
 * - $transfer - Transfer data array
 * - $items - Transfer items array  
 * - $txId - Transfer ID
 * - $fromLbl, $toLbl - Outlet labels
 * - $isPackaged - Package status
 * - $sourceStockMap - Stock levels
 * - $bootPayload - JS boot configuration
 * - All helper functions (tfx_render_product_cell, etc.)
 */

// Ensure required variables are set
$txId = $txId ?? ($transfer['id'] ?? 0);
$items = $items ?? [];
$fromLbl = $fromLbl ?? 'Unknown';
$toLbl = $toLbl ?? 'Unknown';
$isPackaged = $isPackaged ?? false;
$sourceStockMap = $sourceStockMap ?? [];
?>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show" data-page="transfer-pack" data-txid="<?= (int)$txId ?>">
  <div class="app-body">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>
    <main class="main" id="main">
      
      <?php
      // Breadcrumb configuration
      $breadcrumb_config = [
        'active_page' => 'Pack',
        'show_transfer_id' => true,
        'transfer_id' => $txId
      ];
      include __DIR__ . '/components/breadcrumb.php';
      ?>

      <div class="container-fluid animated fadeIn">
        <?php if ($isPackaged): ?>
          <?php
          // Status alert for packaged transfers
          $alert_config = [
            'type' => 'warning',
            'icon' => 'fa-exclamation-triangle',
            'title' => 'Heads up: this transfer is in PACKAGED mode',
            'message' => '"Mark as Packed" already ran. You can still make last-minute edits, but dispatch isn\'t locked until you send it.',
            'details' => [
              'Adjusting counts, parcels, or notes will update the existing packed shipment record.',
              'No data has been pushed to Lightspeed/Vend yet; that only happens when you mark it as sent.',
              'Accidental sends can\'t be undone here—grab Ops if you need a rollback before dispatch.'
            ],
            'footer_message' => 'Ready to hand over? Use "Mark as Packed & Send" from the Pack console when the consignment is actually leaving.'
          ];
          include __DIR__ . '/components/status-alert.php';
          ?>
        <?php endif; ?>

        <?php
        // Product search panel
        $search_config = [
          'input_placeholder' => 'Search products by name, SKU, handle, ID… (use * wildcard)',
          'show_bulk_actions' => true,
          'bulk_actions' => [
            ['id' => 'bulk-add-selected', 'label' => 'Add Selected', 'class' => 'btn-outline-primary'],
            ['id' => 'bulk-add-to-other', 'label' => 'Add to Other…', 'class' => 'btn-outline-secondary', 'title' => 'Add selected to other transfers (same origin outlet)']
          ]
        ];
        include __DIR__ . '/components/product-search.php';
        ?>

        <?php
        // Transfer header and actions
        $header_config = [
          'transfer_id' => $txId,
          'title' => 'Pack Transfer',
          'title_id' => 'pack-title',
          'subtitle' => $fromLbl . ' → ' . $toLbl,
          'description' => 'Count, label and finalize this consignment',
          'actions' => [
            [
              'id' => 'savePack',
              'label' => 'Save Pack',
              'class' => 'btn-primary',
              'icon' => 'fa-save'
            ],
            [
              'id' => 'autofillFromPlanned',
              'label' => 'Autofill',
              'class' => 'btn-outline-secondary',
              'icon' => 'fa-magic',
              'title' => 'Counted = Planned'
            ]
          ],
          'metrics' => [
            ['label' => 'Items', 'id' => 'itemsToTransfer', 'value' => count($items)],
            ['label' => 'Planned total', 'id' => 'plannedTotal', 'value' => '0'],
            ['label' => 'Counted total', 'id' => 'countedTotal', 'value' => '0'],
            ['label' => 'Diff', 'id' => 'diffTotal', 'value' => '0']
          ]
        ];

        // Combined unified view (header + table together)
        $unified_config = array_merge($header_config, [
          'items' => $items,
          'destination_label' => $toLbl,
          'source_stock_map' => $sourceStockMap,
          'draft_status' => [
            'state' => 'idle',
            'text' => 'IDLE',
            'last_saved' => 'Last saved: Last saved: 8:48:43 PM'
          ]
        ]);
        include __DIR__ . '/components/pack-transfer-unified.php';
        ?>

        <?php
        // Pack & Ship Console
        $dispatch_config = [
          'transfer_id' => $txId,
          'from_outlet' => $fromLbl,
          'to_outlet' => $toLbl,
          'from_line' => $fromLine ?? '',
          'to_line' => $toLine ?? '',
          'show_courier_detail' => $showCourierDetail ?? false,
          'print_pool' => [
            'online' => $printPoolOnline ?? false,
            'status_text' => $printPoolOnline ? 'Print pool online' : 'Print pool offline',
            'meta_text' => $printPoolMetaText ?? 'Awaiting printer status'
          ],
          'freight_metrics' => $freightMetrics ?? ['total_weight_kg' => 0.0, 'total_items' => 0],
          'manual_summary' => [
            'weight_label' => $manualSummaryWeightLabel ?? '—',
            'boxes_label' => $manualSummaryBoxesLabel ?? '—'
          ]
        ];
        
        // Load CSS assets here (where they were in the original pack.php)
        echo load_transfer_css();
        
        include __DIR__ . '/components/pack-ship-console.php';
        ?>

      </div>
    </main>
  </div>

  <?php include $DOCUMENT_ROOT . '/assets/template/html-footer.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/footer.php'; ?>

  <!-- Boot payload for JS -->
  <script>
  window.DISPATCH_BOOT = <?= json_encode($bootPayload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  
  <?= load_transfer_js(); ?>

</body>

</html>