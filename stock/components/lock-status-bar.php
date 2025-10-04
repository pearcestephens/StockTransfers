<?php
/**
 * Advanced Lock Status Bar Component
 * 
 * Comprehensive lock ownership system with:
 * - Same-user tab detection (red bar)
 * - Different-user lock detection (purple bar)
 * - Instant tab switching
 * - 60-second takeover countdown
 * - Input field disabling
 * - Visual blur effects
 * 
 * No required variables (client-side managed via SSE + BroadcastChannel)
 */
?>

<!-- Same User Multiple Tabs - Red Bar -->
<div id="sameuserLockBar" 
     class="lock-bar lock-bar-red" 
     role="alert" 
     aria-live="assertive"
     style="display: none;">
    <div class="lock-bar-content">
        <div class="lock-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="lock-info">
            <span class="status-label">Multiple Tabs Detected</span>
            <span class="lock-message">You have this transfer open in another tab. Only one tab can be active at a time.</span>
        </div>
        <div class="lock-actions">
            <button type="button" 
                    id="takeControlBtn" 
                    class="btn btn-sm btn-danger"
                    data-action="take-control">
                <i class="fas fa-hand-rock"></i> Take Control Here
            </button>
            <button type="button" 
                    id="releaseHereBtn" 
                    class="btn btn-sm btn-secondary"
                    data-action="release-control">
                <i class="fas fa-sign-out-alt"></i> Use Other Tab
            </button>
        </div>
    </div>
</div>

<!-- Different User Lock - Purple Bar -->
<div id="otheruserLockBar" 
     class="lock-bar lock-bar-purple" 
     role="alert" 
     aria-live="assertive"
     style="display: none;">
    <div class="lock-bar-content">
        <div class="lock-icon">
            <i class="fas fa-user-lock"></i>
        </div>
        <div class="lock-info">
            <span class="status-label">Transfer Locked by <span id="lockOwnerName">Another User</span></span>
            <span class="lock-message">This transfer is currently being edited. You can view but cannot make changes.</span>
            <span id="takeoverCountdown" class="countdown-timer d-none">Request sent - taking over in <strong>60</strong>s</span>
        </div>
        <div class="lock-actions">
            <button type="button" 
                    id="requestTakeoverBtn" 
                    class="btn btn-sm btn-warning"
                    data-action="request-takeover">
                <i class="fas fa-hand-paper"></i> Request Control
            </button>
            <button type="button" 
                    id="cancelRequestBtn" 
                    class="btn btn-sm btn-secondary d-none"
                    data-action="cancel-request">
                <i class="fas fa-times"></i> Cancel Request
            </button>
        </div>
    </div>
    <div class="spectator-note">
        <i class="fas fa-eye"></i> <span>Read-only mode: Live updates enabled, editing disabled</span>
    </div>
</div>

<!-- Lock Acquisition Success Banner -->
<div id="lockAcquiredBanner" 
     class="lock-bar lock-bar-success" 
     role="alert" 
     aria-live="assertive"
     style="display: none;">
    <div class="lock-bar-content">
        <div class="lock-icon">
            <i class="fas fa-unlock"></i>
        </div>
        <div class="lock-info">
            <span class="status-label">Control Acquired</span>
            <span class="lock-message">You now have control of this transfer. You can edit all fields.</span>
        </div>
        <div class="lock-actions">
            <button type="button" 
                    id="dismissSuccessBtn" 
                    class="btn btn-sm btn-outline-success"
                    data-action="dismiss-success">
                <i class="fas fa-check"></i> Continue
            </button>
        </div>
    </div>
</div>

<!-- Lock Request Incoming Banner (for current owner) -->
<div id="lockRequestIncoming" 
     class="lock-bar lock-bar-warning" 
     role="alert" 
     aria-live="assertive"
     style="display: none;">
    <div class="lock-bar-content">
        <div class="lock-icon">
            <i class="fas fa-bell"></i>
        </div>
        <div class="lock-info">
            <span class="status-label">Lock Request from <span id="requesterName">Another User</span></span>
            <span class="lock-message">Someone wants to take control of this transfer.</span>
            <span id="responseCountdown" class="countdown-timer">Respond within <strong>60</strong>s or control will transfer automatically</span>
        </div>
        <div class="lock-actions">
            <button type="button" 
                    id="allowTakeoverBtn" 
                    class="btn btn-sm btn-success"
                    data-action="allow-takeover">
                <i class="fas fa-check"></i> Allow
            </button>
            <button type="button" 
                    id="denyTakeoverBtn" 
                    class="btn btn-sm btn-danger"
                    data-action="deny-takeover">
                <i class="fas fa-times"></i> Keep Control
            </button>
        </div>
    </div>
</div>
