<?php
/**
 * Diagnostic Modal Component
 * 
 * Lock system diagnostics modal
 * Client-side managed and populated
 */
?>

<!-- Diagnostic modal injected dynamically by JS -->
<div id="lockDiagModal" style="display: none;">
    <div id="lockDiagContent">
        <h3>
            <span><i class="fas fa-stethoscope"></i> Lock System Diagnostics</span>
            <button data-action="refresh-diagnostics">Refresh</button>
        </h3>
        <div id="lockDiagBody">Loading...</div>
        <div class="diag-actions">
            <button class="btn-copy" data-action="copy-diagnostics">
                <i class="fas fa-copy"></i> Copy All
            </button>
            <button class="btn-refresh" data-action="refresh-diagnostics">
                <i class="fas fa-sync"></i> Refresh
            </button>
            <button class="btn-close" data-action="close-diagnostic">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>
