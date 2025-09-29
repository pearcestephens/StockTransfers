/**
 * Pack Transfer Auto-Save System (No Status Pills)
 * 
 * Handles automatic saving of transfer pack data without UI indicators
 */

// Prevent duplicate loading
if (window.PackAutoSaveLoaded) {
    console.warn('PackAutoSave already loaded, skipping...');
} else {
    window.PackAutoSaveLoaded = true;
    (function(){

    class PackAutoSave {
        constructor(transferId, initialDraftData = null) {
            this.transferId = transferId;
            this.saveTimeout = null;
            this.saveDelay = 1000; // 1 second delay
            this.isDirty = false;
            this._consecErrors = 0;
            this.isSaving = false;
            
            console.log('PackAutoSave initialized for transfer:', transferId);
            this.init();
        }
        
        init() {
            this.bindEvents();
            // Multiple passes to catch late-rendered rows or async injections
            [40, 140, 400, 900].forEach(delay => setTimeout(()=>{ try { this.updateAllQuantityStatuses(); } catch(e){} }, delay));
        }
        
        updateAllQuantityStatuses() {
            console.log('Updating all quantity statuses...');
            const inputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
            console.log('Found inputs to update:', inputs.length);
            
            inputs.forEach(input => {
                this.updateQuantityStatus(input);
            });
        }
        
        bindEvents() {
            // Auto-save on quantity changes
            document.addEventListener('input', (e) => {
                if (e.target.matches('.qty-input, input[name^="counted_qty"]')) {
                    console.log('Quantity changed:', e.target.value, 'for input:', e.target);
                    if (!e.target.dataset.touched) e.target.dataset.touched = '1';
                    this.updateQuantityStatus(e.target);
                    this.markDirty();
                    this.scheduleAutoSave();
                }
            });
            
            // Also handle focus events for lazy loading
            document.addEventListener('focus', (e) => {
                if (e.target.matches('.qty-input, input[name^="counted_qty"]')) {
                    if (!e.target.dataset.touched && e.target.value.trim() !== '') e.target.dataset.touched='1';
                    this.updateQuantityStatus(e.target);
                }
            }, true);
            
            // Manual save button
            document.addEventListener('click', (e) => {
                if (e.target.matches('#savePack, [data-action="save"]')) {
                    e.preventDefault();
                    this.saveNow();
                }
            });
        }
        
        updateQuantityStatus(input) {
            const countedValue = parseInt(input.value) || 0;
            const plannedValueRaw = input.dataset.planned || input.getAttribute('data-planned');
            const plannedValue = parseInt(plannedValueRaw) || 0;
            const row = input.closest('tr');
            if (!row) { console.warn('No row found for input:', input); return; }
            row.classList.remove('qty-match', 'qty-mismatch', 'qty-empty', 'qty-neutral');
            const hasValue = input.value.trim() !== '';
            const touched = hasValue || input.dataset.touched === '1';
            // Neutral: both zero
            if (plannedValue === 0 && countedValue === 0) { row.classList.add('qty-neutral'); return; }
            // Suppress initial red until user touches the field
            if (!touched && plannedValue > 0 && countedValue === 0) { row.classList.add('qty-neutral'); return; }
            // Exact match (non-zero)
            if (countedValue === plannedValue) { if (countedValue !== 0) { row.classList.add('qty-match'); } else { row.classList.add('qty-neutral'); } return; }
            // Anything else mismatch
            row.classList.add('qty-mismatch');
        }
        
        markDirty() {
            this.isDirty = true;
        }
        
        scheduleAutoSave() {
            if (this.saveTimeout) {
                clearTimeout(this.saveTimeout);
            }
            
            this.saveTimeout = setTimeout(() => {
                this.saveNow();
            }, this.saveDelay);
        }
        
        async saveNow() {
            if (this.isSaving) return;
            const draftData = this.collectDraftData();
            const payloadHash = JSON.stringify(draftData.counted_qty||{}) + '|' + draftData.notes;
            if(this._lastHash === payloadHash){ // no material change
                this.isDirty = false; try { document.dispatchEvent(new CustomEvent('packautosave:state',{detail:{state:'noop'}})); }catch(e){}
                return;
            }
            this._lastHash = payloadHash;
            
            this.isSaving = true;
            console.log('Auto-save: Starting save...');
            try { document.dispatchEvent(new CustomEvent('packautosave:state', {detail:{state:'saving'}})); } catch(e){}
            
            try {
                const response = await fetch('/modules/transfers/stock/api/draft_save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(draftData)
                });
                
                const result = await response.json();
                
                if (response.ok && result.success) {
                    console.log('Auto-save: Success');
                    this.isDirty = false;
                    try { document.dispatchEvent(new CustomEvent('packautosave:state', {detail:{state:'saved', payload: result}})); } catch(e){}
                    if(window.PackToast){ PackToast.success('Draft saved',{timeout:2200, force:false}); }
                } else {
                    console.error('Auto-save: Failed', result);
                    this._consecErrors = (this._consecErrors||0)+1;
                    try { document.dispatchEvent(new CustomEvent('packautosave:state', {detail:{state:'error', payload: result}})); } catch(e){}
                    if(window.PackToast){
                        PackToast.error('Draft save failed', {action:{label:'Retry', onClick:()=>this.saveNow()}, force: this._consecErrors>1});
                    }
                    // Backoff schedule (1s,2s,4s capped 15s)
                    const backoff = Math.min(15000, Math.pow(2, Math.min(4,this._consecErrors))*1000);
                    clearTimeout(this.saveTimeout);
                    this.saveTimeout = setTimeout(()=> this.saveNow(), backoff);
                }
                
            } catch (error) {
                console.error('Auto-save: Error', error);
                this._consecErrors = (this._consecErrors||0)+1;
                try { document.dispatchEvent(new CustomEvent('packautosave:state', {detail:{state:'error', payload: {message: error?.message}}})); } catch(e){}
                if(window.PackToast){ PackToast.error('Draft save network error', {action:{label:'Retry', onClick:()=>this.saveNow()}, force: this._consecErrors>1}); }
                const backoff = Math.min(15000, Math.pow(2, Math.min(4,this._consecErrors))*1000);
                clearTimeout(this.saveTimeout);
                this.saveTimeout = setTimeout(()=> this.saveNow(), backoff);
            } finally {
                this.isSaving = false;
            }
        }
        
        collectDraftData() {
            const data = {
                transfer_id: this.transferId,
                counted_qty: {},
                notes: '',
                timestamp: new Date().toISOString()
            };
            
            console.log('Collecting draft data...');
            
            // Collect counted quantities - try multiple selectors
            const quantityInputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
            console.log('Found quantity inputs:', quantityInputs.length);
            
            quantityInputs.forEach(input => {
                let productId = null;
                
                // Try different ways to get product ID
                if (input.dataset.productId) {
                    productId = input.dataset.productId;
                } else if (input.name && input.name.includes('[') && input.name.includes(']')) {
                    productId = input.name.replace('counted_qty[', '').replace(']', '');
                } else if (input.dataset.item) {
                    productId = input.dataset.item;
                } else {
                    // Try to find product ID in the same row
                    const row = input.closest('tr');
                    if (row) {
                        const productInput = row.querySelector('input[name*="product_id"], .productID, [data-product-id]');
                        if (productInput) {
                            productId = productInput.value || productInput.dataset.productId;
                        }
                    }
                }
                
                if (productId && input.value) {
                    const value = parseInt(input.value) || 0;
                    if (value > 0) {
                        data.counted_qty[productId] = value;
                        console.log('Added to draft:', productId, '=', value);
                    }
                }
            });
            
            // Collect notes
            const notesField = document.querySelector('#notesForTransfer, [name="notes"]');
            if (notesField && notesField.value) {
                data.notes = notesField.value;
            }
            
            console.log('Final draft data:', data);
            return data;
        }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAutoSave);
    } else {
        initAutoSave();
    }
    
    function initAutoSave() {
        const transferIdEl = document.querySelector('[data-txid]') || document.querySelector('[data-transfer-id]') || document.body;
        let transferId = 0;
        if (transferIdEl) {
            transferId = parseInt(transferIdEl.getAttribute('data-txid') || transferIdEl.getAttribute('data-transfer-id') || '0', 10);
        }
        if (!transferId) {
            const p = new URLSearchParams(location.search);
            transferId = parseInt(p.get('transfer') || p.get('t') || '0', 10) || 0;
        }
        console.log('[PackAutoSave] Found transfer ID:', transferId);
        if (transferId > 0) {
            window.packAutoSave = new PackAutoSave(transferId);
            console.log('[PackAutoSave] Initialized');
        } else {
            console.warn('[PackAutoSave] No valid transfer ID; disabled');
        }
    }

    // Lightweight status helper
    function setInlineStatus(txt){
        const el = document.getElementById('autosaveStatus');
        if (el) el.textContent = txt;
    }
    
    // patch saveNow to show inline status (monkey patch after class defined)
    const _origProtoSave = PackAutoSave.prototype.saveNow;
    PackAutoSave.prototype.saveNow = async function(){
        if (this.isSaving) return;
        setInlineStatus('Saving...');
        await _origProtoSave.apply(this, arguments);
        if (!this.isDirty) setInlineStatus('Saved'); else setInlineStatus('Pending changes');
    };

    })();
}
// (Removed duplicated legacy fragment appended after IIFE to resolve syntax error)