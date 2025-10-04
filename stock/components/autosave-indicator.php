<?php
/**
 * Autosave Indicator Component
 * 
 * Shows autosave status with animated pill
 * 
 * No required variables (client-side managed)
 */
?>

<div class="d-flex align-items-center">
    <!-- Autosave Status Pill -->
    <div id="autosavePill" 
         class="autosave-pill status-idle" 
         role="status"
         aria-live="polite"
         style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:15px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; background-color:#6c757d; color:white;">
        <span class="pill-icon" 
              style="width:6px; height:6px; border-radius:50%; margin-right:6px; background-color:currentColor;"></span>
        <span id="autosavePillText">Idle</span>
    </div>
    
    <span id="autosaveStatus" 
          class="ml-2 small text-muted" 
          style="font-size:11px;">&nbsp;</span>
    
    <span id="autosaveLastSaved" 
          class="ml-2 small text-muted" 
          style="font-size:11px;"></span>
    
    <div id="lastSaveTime" 
         style="font-size: 0.75rem; color: #6c757d; margin-left: 8px; min-height: 14px;"></div>
</div>
