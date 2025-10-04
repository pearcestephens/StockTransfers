<?php
/**
 * Lock Handover Modal Component
 * 
 * Modal for lock handover acceptance
 * Client-side managed
 */
?>

<!-- Handover modal injected dynamically by JS when needed -->
<template id="lockHandoverModalTemplate">
    <div id="lockHandoverModalBackdrop">
        <div id="lockHandoverModal" role="dialog" aria-modal="true">
            <h3>Lock Handover Request</h3>
            <p>Another user is requesting control of this transfer. Accept?</p>
            <div class="actions">
                <button type="button" 
                        id="lockDeclineBtn" 
                        data-action="decline-handover"
                        style="background:#4c1d95; border:1px solid #6d28d9;">
                    Decline
                </button>
                <button type="button" 
                        id="lockAcceptBtn" 
                        data-action="accept-handover"
                        style="background:#dc2626; border:1px solid #f87171;">
                    Accept
                </button>
            </div>
        </div>
    </div>
</template>
