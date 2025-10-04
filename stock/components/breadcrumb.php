<?php
/**
 * Breadcrumb Component
 * 
 * Navigation breadcrumbs for transfer pages
 * 
 * Required variables:
 * @var string $activePage      Current page name
 * @var bool   $showTransferId  Show transfer ID in breadcrumb
 * @var int    $transferId      Transfer ID (if showTransferId = true)
 */

$activePage = $activePage ?? 'Transfers';
$showTransferId = $showTransferId ?? false;
$transferId = $transferId ?? null;
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="/modules/transfers/">
                <i class="fas fa-home"></i> Transfers
            </a>
        </li>
        <li class="breadcrumb-item">
            <a href="/modules/transfers/stock/">Stock Transfers</a>
        </li>
        <?php if ($showTransferId && $transferId): ?>
            <li class="breadcrumb-item">
                <a href="/modules/transfers/stock/?tx=<?= (int)$transferId ?>">
                    #<?= (int)$transferId ?>
                </a>
            </li>
        <?php endif; ?>
        <li class="breadcrumb-item active" aria-current="page">
            <?= htmlspecialchars($activePage, ENT_QUOTES) ?>
        </li>
    </ol>
</nav>
