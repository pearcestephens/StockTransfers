<?php
/**
 * Pack View Template (Component-Based)
 * 
 * Clean, modular view using reusable components
 * All variables passed via $viewData from controller
 * 
 * @package Modules\Transfers\Stock
 * @since   2025-10-03
 */

// Safely extract view data (NO user input here)
foreach ($viewData as $key => $value) {
    $$key = $value;
}
?>

<!-- Auto-loaded CSS -->
<?= $assets['css'] ?>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <main class="app-body">
    
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>

    <main class="main" 
          id="main"
          data-page="transfer-pack"
          data-txid="<?= (int)$txId ?>"
          data-txstring="<?= htmlspecialchars($txStringId, ENT_QUOTES) ?>">
      
      <!-- Breadcrumb Component -->
      <?php
      $activePage = 'Pack';
      $showTransferId = true;
      $transferId = $txId;
      include __DIR__ . '/../components/breadcrumb.php';
      ?>

      <div class="container-fluid animated fadeIn pack-container">
        
        <!-- Transfer Header Component -->
        <?php include __DIR__ . '/../components/header.php'; ?>

        <!-- Lock Status Bar Component -->
        <?php include __DIR__ . '/../components/lock-status-bar.php'; ?>

        <!-- Metrics Card Component -->
        <?php include __DIR__ . '/../components/metrics-card.php'; ?>

        <!-- Items Table Component -->
        <?php include __DIR__ . '/../components/items-table.php'; ?>

        <!-- Items Table Component -->
        <?php include __DIR__ . '/../components/courier_console.php'; ?>

        <!-- Shipping Section (if needed later) -->
        <!-- TODO: Create components/shipping-form.php -->
        
      </div><!-- /.container-fluid -->
      
    </main><!-- /.main -->
    
  </main><!-- /.app-body -->

  <!-- Product Image Modal Component -->
  <?php include __DIR__ . '/../components/modals/product-image.php'; ?>

  <!-- Diagnostic Modal Component -->
  <?php include __DIR__ . '/../components/modals/diagnostic.php'; ?>

  <!-- Lock Handover Modal Component -->
  <?php include __DIR__ . '/../components/modals/lock-handover.php'; ?>

  <!-- Footer Template -->
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>

<!-- Boot Payload for JavaScript -->
<script>
window.DISPATCH_BOOT = <?= json_encode($bootPayload, JSON_THROW_ON_ERROR) ?>;
console.log('[DEBUG] DISPATCH_BOOT:', window.DISPATCH_BOOT);
</script>

<!-- Auto-loaded JavaScript Modules -->
<?= $assets['js'] ?>


</body>
