<?php
/**
 * Pack Header Component
 * Displays the main header for pack transfer page
 */
?>
<!-- Basic Header Component -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center text-white pack-header-gradient" style="position: relative; overflow: hidden;">
    <div style="position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%); animation: shine 20s infinite;"></div>
    <div>
      <h4 class="mb-0">
        <i class="fa fa-cube mr-2"></i>
        Pack Transfer #<?= (int)$txId ?>
      </h4>
      <small class="text-light">From <?= htmlspecialchars($fromLbl, ENT_QUOTES) ?> → <?= htmlspecialchars($toLbl, ENT_QUOTES) ?></small>
    </div>
    <div class="btn-group" role="group">
      <button type="button" id="addProductBtn" class="btn btn-light btn-sm">
        <i class="fa fa-plus mr-1"></i>Add Product
      </button>
    </div>
  </div>
</div>