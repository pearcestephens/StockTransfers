<?php
/**
 * Transfer Header Component
 * 
 * Displays from/to outlet information with contact details
 * 
 * Required variables:
 * @var int    $txId           Transfer ID
 * @var string $fromLbl        From outlet name
 * @var string $toLbl          To outlet name
 * @var array  $fromOutlet     From outlet data (optional)
 * @var array  $toOutlet       To outlet data (optional)
 * @var array  $lockStatus     Lock status data (optional)
 */

if (!isset($txId, $fromLbl, $toLbl)) {
    throw new \RuntimeException('Transfer header component requires: $txId, $fromLbl, $toLbl');
}

$lockStatus = $lockStatus ?? ['state' => 'unlocked'];
$lockBadgeText = 'UNLOCKED';
$lockBadgeClass = 'badge-secondary';

if ($lockStatus['state'] === 'acquired' || $lockStatus['state'] === 'alive') {
    $lockBadgeText = 'LOCKED';
    $lockBadgeClass = 'badge-success';
} elseif ($lockStatus['state'] === 'blocked') {
    $holderName = $lockStatus['holder_name'] ?? 'OTHER';
    $lockBadgeText = 'LOCKED BY ' . strtoupper($holderName);
    $lockBadgeClass = 'badge-danger';
}
?>

<div class="card mb-3">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0">
                    <i class="fas fa-exchange-alt mr-2"></i>
                    Store Transfer #<?= (int)$txId ?>
                    <span class="badge <?= htmlspecialchars($lockBadgeClass, ENT_QUOTES) ?> ml-2" 
                          id="lockStatusBadge" 
                          data-state="<?= htmlspecialchars($lockStatus['state'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($lockBadgeText, ENT_QUOTES) ?>
                    </span>
                </h4>
            </div>
            <div class="d-flex align-items-center">
                <button class="btn btn-light btn-sm mr-2" 
                        id="lockDiagnosticBtn" 
                        data-action="open-diagnostic"
                        title="System Diagnostic">
                    <i class="fas fa-cog"></i>
                </button>
                <button type="button" 
                        class="btn btn-light btn-sm" 
                        id="headerAddProductBtn"
                        data-action="add-product">
                    <i class="fas fa-plus mr-1"></i>Add Product
                </button>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <div class="row">
            <!-- From Outlet -->
            <div class="col-md-6">
                <div class="d-flex align-items-start">
                    <div class="mr-3">
                        <i class="fas fa-store fa-2x text-primary"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="mb-2">
                            <strong>From:</strong> 
                            <span class="h5 mb-0"><?= htmlspecialchars($fromLbl, ENT_QUOTES) ?></span>
                        </div>
                        <?php if (!empty($fromOutlet) && is_array($fromOutlet)): ?>
                            <?php if (!empty($fromOutlet['google_review_rating'])): ?>
                                <div>
                                    <span class="badge badge-warning mr-2">
                                        <i class="fas fa-star"></i> 
                                        <?= number_format((float)$fromOutlet['google_review_rating'], 1) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($fromOutlet['physical_address_1'])): ?>
                                <div class="text-muted" style="font-size: 0.85rem;">
                                    <i class="fas fa-map-marker-alt text-secondary mr-1"></i>
                                    <?= htmlspecialchars($fromOutlet['physical_address_1'], ENT_QUOTES) ?>
                                    <?php if (!empty($fromOutlet['physical_city'])): ?>
                                        , <?= htmlspecialchars($fromOutlet['physical_city'], ENT_QUOTES) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- To Outlet -->
            <div class="col-md-6">
                <div class="d-flex align-items-start">
                    <div class="mr-3">
                        <i class="fas fa-map-marker-alt fa-2x text-success"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="mb-2">
                            <strong>To:</strong> 
                            <span class="h5 mb-0"><?= htmlspecialchars($toLbl, ENT_QUOTES) ?></span>
                        </div>
                        <?php if (!empty($toOutlet) && is_array($toOutlet)): ?>
                            <?php if (!empty($toOutlet['google_review_rating'])): ?>
                                <div>
                                    <span class="badge badge-warning mr-2">
                                        <i class="fas fa-star"></i> 
                                        <?= number_format((float)$toOutlet['google_review_rating'], 1) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($toOutlet['physical_address_1'])): ?>
                                <div class="text-muted" style="font-size: 0.85rem;">
                                    <i class="fas fa-map-marker-alt text-secondary mr-1"></i>
                                    <?= htmlspecialchars($toOutlet['physical_address_1'], ENT_QUOTES) ?>
                                    <?php if (!empty($toOutlet['physical_city'])): ?>
                                        , <?= htmlspecialchars($toOutlet['physical_city'], ENT_QUOTES) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($toOutlet['physical_postcode'])): ?>
                                        <?= htmlspecialchars($toOutlet['physical_postcode'], ENT_QUOTES) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($toOutlet['physical_phone_number'])): ?>
                                <div class="mt-2">
                                    <a href="tel:<?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>"
                                       class="btn btn-sm btn-outline-success" 
                                       style="padding: 4px 12px; font-size: 0.85rem;">
                                        <i class="fas fa-phone mr-1"></i>
                                        <?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
