/**
 * ============================================================================
 * TRANSFERS PACK SYSTEM - UNIFIED JAVASCRIPT MODULE (v2.0)
 * ============================================================================
 * 
 * Consolidated pack system JavaScript that integrates with existing modules
 * and provides a clean, unified interface.
 * 
 * Integrates with:
 * - pack-core.js (PackBus event system)
 * - pack-toast.js (Toast notifications)
 * - pack-autosave.js (Auto-save functionality) 
 * - pack-lock.js (PackLockSystem)
 * 
 * Version: 2.0.0 (Clean Architecture + Existing Integration)
 */

// Hardening: Claim the legacy autosave guard flag so pack-autosave.js (older system)
// will early-exit if still included elsewhere, preventing duplicate save attempts
// and duplicate toast notifications.
if (!window.PackAutoSaveLoaded) {
  window.PackAutoSaveLoaded = true;
}

class TransfersPackSystem {
  constructor(config = {}) {
    this.config = {
      transferId: config.transferId || null,
      userId: config.userId || null,
      autoSaveInterval: config.autoSaveInterval || 30000,
      lockPollInterval: config.lockPollInterval || 5000,
      debug: config.debug || false,
      ...config
    };
    
    this.modules = {};
    this.isInitialized = false;
    
    this.debug('🚀 TransfersPackSystem initializing...', this.config);
    this.init();
  }
  
  init() {
    if (this.isInitialized) {
      console.warn('TransfersPackSystem already initialized');
      return;
    }
    
    // Wait for existing modules to be ready
    this.waitForDependencies(() => {
      this.initLockSystem();
      this.initToastSystem();
      this.initAutoSave();
      this.initEventSystem();
      this.bindEvents();
      
      // Initialize lock status display
      this.updateLockStatusDisplay();
      
      this.isInitialized = true;
      this.debug('✅ TransfersPackSystem fully initialized');
      this.emit('ready', this);
      // Rescue loop: if lock not acquired after initial init, retry a few times
      if (!this._lockRescueLoopStarted) {
        this._lockRescueLoopStarted = true;
        let attempts = 0;
        this._lockRescueTimer = setInterval(() => {
          const st = this.getLockStatus();
            const has = !!(st.hasLock || st.has_lock);
            const other = !!(st.isLockedByOther || st.is_locked_by_other);
            if (has || other || attempts >= 6) {
              if (attempts >= 6 && !has && !other) this.debug('🔒 Lock rescue loop gave up (no lock acquired)');
              clearInterval(this._lockRescueTimer);
              return;
            }
            attempts++;
            this.debug(`🔒 Lock rescue attempt #${attempts} (state:`, st, ')');
            if (this.modules.lockSystem && typeof this.modules.lockSystem.acquireLock === 'function') {
              this.modules.lockSystem.acquireLock().then(r => {
                this.debug('🔒 Rescue acquire result', r);
                if (r && r.success) {
                  this.modules.autoSave && (this.modules.autoSave.hasPendingChanges = true);
                  setTimeout(()=>{ if (this.hasLock() && this.modules.autoSave?.hasPendingChanges) this.performAutoSave('rescue_acquire'); }, 1200);
                }
              }).catch(e=> this.debug('🔒 Rescue acquire error', e));
            }
        }, 4000);
      }
    });
  }
  
  waitForDependencies(callback) {
    const checkDependencies = () => {
      // Check if core dependencies are ready
      const hasBootData = typeof window.DISPATCH_BOOT !== 'undefined';
      
      // PackBus and PackToast are optional - create fallbacks if they don't exist
      if (hasBootData) {
        callback();
      } else {
        this.debug('Waiting for dependencies...', { hasBootData });
        setTimeout(checkDependencies, 100);
      }
    };
    
    checkDependencies();
  }
  
  // =========================================================================
  // LOCK SYSTEM INTEGRATION
  // =========================================================================
  
  initLockSystem() {
    if (!this.config.transferId || !this.config.userId) {
      console.warn('Lock system disabled - missing transferId or userId');
      return;
    }
    const lockStatus = this.getLockStatus();
    const hasLock = !!(lockStatus.hasLock || lockStatus.has_lock);
    const lockedByOther = !!(lockStatus.lockedBy || lockStatus.is_locked_by_other);
    console.groupCollapsed('🔒 Lock Diagnostic (fallback)');
    console.log(lockStatus);
    console.groupEnd();
    if (hasLock) {
      this.showToast('You hold the lock', 'info');
    } else if (lockedByOther) {
      this.showToast(`Locked by ${lockStatus.lockedByName || lockStatus.holderName || 'another user'}`, 'warning');
    } else {
      this.showToast('No active lock', 'info');
      if (this.modules.lockSystem?.acquireLock) {
        this.modules.lockSystem.acquireLock().then(r=>{
          if (r?.success) {
            this.showToast('Lock acquired via diagnostic', 'success');
            this.updateLockStatusDisplay();
          }
        }).catch(()=>this.showToast('Lock acquire failed','error'));
      }
    }
      if (!this._pendingAutosaveWatcher) {
        this._pendingAutosaveWatcher = setInterval(() => {
          try {
            if (!this.modules.autoSave) return;
            if (this.modules.autoSave.isSaving) return;
            if (!this.modules.autoSave.hasPendingChanges) return;
            if (!this.hasLock()) return; // still no lock
            this.debug('Pending changes detected post-lock; performing auto-save');
            this.performAutoSave('post_lock_flush');
          } catch(e){ console.warn('Pending autosave watcher error', e); }
        }, 5000);
      }

      // If PackBus exists, attempt immediate flush on lock event
      if (window.PackBus && typeof window.PackBus.listen === 'function') {
        try {
          window.PackBus.listen('pack:lock:acquired', () => {
            if (this.modules?.autoSave?.hasPendingChanges && this.hasLock()) {
              this.debug('Lock acquired event -> flushing pending autosave');
              this.performAutoSave('lock_event');
            }
          });
        } catch(_) {}
      }
    } else {
      // Retry after delay if PackLockSystem isn't loaded yet
      setTimeout(() => this.initLockSystem(), 500);
    }
  }
  
  // Lock system API
  requestOwnership() {
    return this.modules.lockSystem?.requestOwnership();
  }
  
  releaseOwnership() {
    return this.modules.lockSystem?.releaseOwnership();
  }
  
  getLockStatus() {
    const raw = this.modules.lockSystem?.lockStatus || window.lockStatus || null;
    if (!raw) {
      return { hasLock:false, has_lock:false, is_locked:false, isLockedByOther:false, is_locked_by_other:false };
    }
    // Normalise variants coming from legacy + universal systems
    const hasLock = !!(raw.hasLock || raw.has_lock);
    const isLockedByOther = !!(raw.isLockedByOther || raw.is_locked_by_other);
    const holderName = raw.holder_name || raw.holderName || raw.lockedByName || null;
    const holderId = raw.holder_id || raw.holderId || raw.lockedBy || raw.locked_by || null;
    const norm = {
      ...raw,
      hasLock,
      has_lock: hasLock,
      isLockedByOther,
      is_locked_by_other: isLockedByOther,
      lockedBy: holderId,
      lockedByName: holderName,
      holderName,
      holderId
    };
    return norm;
  }
  
  hasLock() {
    const ls = this.getLockStatus();
    return !!(ls && (ls.hasLock || ls.has_lock));
  }
  
  getToastIcon(type) {
    const icons = {
      success: 'fa-check-circle',
      error: 'fa-exclamation-circle',
      warning: 'fa-exclamation-triangle',
      info: 'fa-info-circle'
    };
    return icons[type] || icons.info;
  }
  
  getToastBgColor(type) {
    const colors = {
      success: '#d4edda',
      error: '#f8d7da',
      warning: '#fff3cd',
      info: '#d1ecf1'
    };
    return colors[type] || colors.info;
  }
  
  getToastTextColor(type) {
    const colors = {
      success: '#155724',
      error: '#721c24',
      warning: '#856404',
      info: '#0c5460'
    };
    return colors[type] || colors.info;
  }

  // =========================================================================
  // FALLBACK EVENT BUS (added to resolve missing createFallbackEventBus error)
  // =========================================================================
  createFallbackEventBus() {
    const listeners = {};
    const bus = {
      on(event, cb) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(cb);
        return bus;
      },
      listen(event, cb) { // compatibility alias
        return bus.on(event, cb);
      },
      off(event, cb) {
        if (!listeners[event]) return bus;
        listeners[event] = listeners[event].filter(fn => fn !== cb);
        return bus;
      },
      emit(event, payload) {
        // direct event
        if (listeners[event]) {
          listeners[event].forEach(fn => {
            try { fn(payload); } catch (e) { console.warn('FallbackEventBus handler error', e); }
          });
        }
        // If caller emits without pack: prefix but prefixed listeners exist
        if (!event.startsWith('pack:')) {
          const prefixed = `pack:${event}`;
            if (listeners[prefixed]) {
              listeners[prefixed].forEach(fn => {
                try { fn(payload); } catch (e) { console.warn('FallbackEventBus handler error', e); }
              });
            }
        }
        return bus;
      }
    };
    if (this.config?.debug) console.log('🔧 PackSystem: FallbackEventBus created');
    return bus;
  }
  
  getToastBorderColor(type) {
    const colors = {
      success: '#28a745',
      error: '#dc3545',
      warning: '#ffc107',
      info: '#17a2b8'
    };
    return colors[type] || colors.info;
  }
  
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  showToast(message, type = 'info', options = {}) {
    if (!this.modules.toastSystem) return;
    const now = Date.now();
    if (!this._toastRecent) this._toastRecent = [];
    // Extend dedupe window for identical error messages (especially autosave failures)
    const dedupeWindow = (type === 'error') ? 8000 : 2500;
    this._toastRecent = this._toastRecent.filter(r => now - r.t < dedupeWindow);
    // Specific throttle for recurring autosave failure message
    if (message === 'Auto-save failed - please save manually') {
      if (!this._lastAutoSaveErrorAt) this._lastAutoSaveErrorAt = 0;
      if (now - this._lastAutoSaveErrorAt < 8000) {
        this.debug('Suppress duplicate autosave error toast');
        return;
      }
      this._lastAutoSaveErrorAt = now;
    }
    if (!options.force && this._toastRecent.some(r => r.m === message && r.ty === type)) return;
    this._toastRecent.push({ m: message, ty: type, t: now });
    return this.modules.toastSystem.show(message, type, options);
  }
  
  // =========================================================================
  // AUTO-SAVE INTEGRATION (Enhanced with Visual Feedback)
  // =========================================================================
  
  initAutoSave() {
    this.modules.autoSave = {
      timer: null,
      debounceTimer: null,
      lastSaveData: null,
      lastSaveTime: 0,
      isEnabled: true,
      isSaving: false,
      saveDelay: 2000, // 2 seconds after last input
      maxInterval: 30000, // Maximum 30 seconds between saves
      hasPendingChanges: false,
      
      start: () => {
        this.setAutoSaveStatus('idle');
        this.debug('Auto-save started (input-driven only)');
      },
      
      stop: () => {
        if (this.modules.autoSave.timer) {
          clearInterval(this.modules.autoSave.timer);
          this.modules.autoSave.timer = null;
        }
        
        if (this.modules.autoSave.debounceTimer) {
          clearTimeout(this.modules.autoSave.debounceTimer);
          this.modules.autoSave.debounceTimer = null;
        }
        
        this.setAutoSaveStatus('idle');
        this.debug('Auto-save stopped');
      },
      
      triggerSave: () => {
        this.modules.autoSave.hasPendingChanges = true;
        
        // Clear existing debounce timer
        if (this.modules.autoSave.debounceTimer) {
          clearTimeout(this.modules.autoSave.debounceTimer);
        }
        
        // Set new debounce timer for 2 seconds
        this.modules.autoSave.debounceTimer = setTimeout(() => {
          if (this.modules.autoSave.isEnabled && !this.modules.autoSave.isSaving && this.modules.autoSave.hasPendingChanges) {
            // Check if enough time has passed since last save (30-second limit)
            const timeSinceLastSave = Date.now() - this.modules.autoSave.lastSaveTime;
            if (timeSinceLastSave >= this.modules.autoSave.maxInterval || this.modules.autoSave.lastSaveTime === 0) {
              this.performAutoSave('input');
            } else {
              this.debug(`Auto-save postponed - only ${Math.round(timeSinceLastSave/1000)}s since last save`);
            }
          }
        }, this.modules.autoSave.saveDelay);
        
        this.debug('Auto-save triggered (will save in 2s if no more input)');
      }
    };
    
    // Start auto-save
    this.modules.autoSave.start();
  }
  
  setAutoSaveStatus(status, extraInfo = '') {
    const statusElement = document.getElementById('autoSaveStatus');
    const timeElement = document.getElementById('lastSaveTime');
    
    if (statusElement) {
      statusElement.setAttribute('data-status', status);
      
      switch (status) {
        case 'idle':
          statusElement.textContent = 'Idle';
          statusElement.style.color = 'rgba(255,255,255,0.8)';
          break;
        case 'saving':
          statusElement.textContent = 'Saving...';
          statusElement.style.color = '#ffc107';
          break;
        case 'saved':
          statusElement.textContent = 'Saved';
          statusElement.style.color = '#28a745';
          
          // Update last save time with "Last updated" label
          if (timeElement) {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { 
              hour12: false, 
              hour: '2-digit', 
              minute: '2-digit',
              second: '2-digit'
            });
            timeElement.textContent = `Last updated: ${timeStr}`;
          }
          
          // Show saved state for 3 seconds, then return to idle
          setTimeout(() => {
            if (statusElement.getAttribute('data-status') === 'saved') {
              this.setAutoSaveStatus('idle');
            }
          }, 3000);
          break;
        case 'error':
          statusElement.textContent = 'Error';
          statusElement.style.color = '#dc3545';
          
          // Return to idle after 5 seconds
          setTimeout(() => {
            if (statusElement.getAttribute('data-status') === 'error') {
              this.setAutoSaveStatus('idle');
            }
          }, 5000);
          break;
      }
    }
    
    // Also update the pill status if present
    const autosavePill = document.getElementById('autosavePill');
    const autosavePillText = document.getElementById('autosavePillText');
    
    if (autosavePill && autosavePillText) {
      autosavePill.className = `autosave-pill status-${status}`;
      
      switch (status) {
        case 'idle':
          autosavePillText.textContent = 'Idle';
          autosavePill.style.backgroundColor = '#6c757d';
          break;
        case 'saving':
          autosavePillText.textContent = 'Saving';
          autosavePill.style.backgroundColor = '#ffc107';
          break;
        case 'saved':
          autosavePillText.textContent = 'Saved';
          autosavePill.style.backgroundColor = '#28a745';
          break;
        case 'error':
          autosavePillText.textContent = 'Error';
          autosavePill.style.backgroundColor = '#dc3545';
          break;
      }
    }
  }
  
  performAutoSave(trigger = 'unknown') {
    if (!this._lastAutoSaveAttempt) this._lastAutoSaveAttempt = 0;
    const since = Date.now() - this._lastAutoSaveAttempt;
    if (since < 500) { this.debug(`Auto-save suppressed (throttle ${since}ms)`); return; }
    this._lastAutoSaveAttempt = Date.now();

    // NEW: hard guard – only proceed if we definitively hold the lock
    if (!this.hasLock()) {
      this.debug('Auto-save skipped - lock not yet acquired');
      // keep pending changes so first post-lock heartbeat triggers save
      this.modules.autoSave.hasPendingChanges = true;
      return;
    }

    if (this.modules.autoSave.isSaving || this._inFlightSave) {
      this.debug('Auto-save already in progress (module or inFlight), skipping');
      return;
    }
    const lockStatus = this.getLockStatus();
    // legacy double-check (redundant with hasLock) but retain for clarity
    if (!lockStatus?.has_lock && this.modules.lockSystem && !this.modules.lockSystem.lockStatus?.has_lock) {
      this.debug('Auto-save skipped - no lock (legacy check)');
      this.modules.autoSave.hasPendingChanges = true;
      return;
    }
    
    // Get current form data
    const currentData = this.gatherFormData();
    
    // Skip if no meaningful data
    if (!currentData.counted_qty || Object.keys(currentData.counted_qty).length === 0) {
      this.debug('Auto-save skipped - no quantity data');
      this.modules.autoSave.hasPendingChanges = false;
      return;
    }
    
    // Skip if no changes from last save
    if (JSON.stringify(currentData) === JSON.stringify(this.modules.autoSave.lastSaveData)) {
      this.debug('Auto-save skipped - no changes');
      this.modules.autoSave.hasPendingChanges = false;
      return;
    }
    
    this.modules.autoSave.isSaving = true;
    this.modules.autoSave.hasPendingChanges = false;
    this.setAutoSaveStatus('saving');
    
    this.debug(`Performing auto-save (trigger: ${trigger})`);
    
    // Insert a debug marker for visibility when save actually proceeds
    this.debug(`Auto-save proceeding (trigger=${trigger}) with ${Object.keys(currentData.counted_qty||{}).length} item(s)`);
    this.saveData(currentData, { isAutoSave: true })
      .then((response) => {
        this.modules.autoSave.lastSaveData = currentData;
        this.modules.autoSave.lastSaveTime = Date.now();
        this.modules.autoSave.isSaving = false;
        
        const itemCount = Object.keys(currentData.counted_qty).length;
        this.setAutoSaveStatus('saved', `${itemCount} items`);
        
        this.debug('Auto-save successful');
        
        // Show subtle toast notification for manual saves
        if (trigger === 'manual') {
          this.showToast('Progress saved successfully', 'success', { duration: 2000 });
        }
      })
      .catch(error => {
        this.modules.autoSave.isSaving = false;
        this.modules.autoSave.hasPendingChanges = true; // Restore pending changes flag on error
        this.setAutoSaveStatus('error');
        
        console.error('Auto-save failed:', error);
        
        // Check for lock violations
        if (this.isLockViolation(error)) {
          this.handleLockViolation(error, 'auto-save');
        } else {
          this.showToast('Auto-save failed - please save manually', 'error', { duration: 5000 });
        }
      });
  }
  
  gatherFormData() {
    if (window.PackModules && window.PackModules.FormSerializer) {
      try { return window.PackModules.FormSerializer.gatherFormData(this.config.transferId); } catch(e){ console.warn('FormSerializer error, fallback', e); }
    }
    const fallback = { transfer_id: this.config.transferId, timestamp: new Date().toISOString() };
    const quantities = {};
    document.querySelectorAll('.pack-quantity-input, .qty-input, input[name^="counted_qty"]').forEach(input => {
      let productId = input.dataset.productId || input.getAttribute('data-product-id');
      if (!productId) { const row = input.closest('tr'); if (row && row.dataset.productId) productId = row.dataset.productId; }
      if (!productId) { const m = input.name && input.name.match(/counted_qty\[([^\]]+)\]/); if (m) productId = m[1]; }
      if (productId && input.value && input.value.trim() !== '') { const n = parseInt(input.value,10); quantities[productId] = Number.isFinite(n)?n:0; }
    });
    fallback.counted_qty = quantities;
    const notesInput = document.querySelector('#notesForTransfer, [name="notes"]');
    if (notesInput && notesInput.value) fallback.notes = notesInput.value;
    return fallback;
  }
  
  async saveData(data, options = {}) {
    // Single-flight guard (prevents duplicate overlapping requests)
    if (this._inFlightSave) {
      this.debug('Save suppressed (in-flight)');
      return this._inFlightSavePromise || Promise.reject(new Error('Save suppressed'));
    }
    if (!this._saveRequestSeq) this._saveRequestSeq = 0;
    const seq = ++this._saveRequestSeq;
    const url = options.isAutoSave
      ? '/modules/transfers/stock/api/draft_save_api.php'
      : '/modules/transfers/stock/api/pack_save.php';
    const payload = JSON.stringify(data);
    this.debug(`[saveData#${seq}] POST ${url} bytes=${payload.length} autoSave=${!!options.isAutoSave}`);
    this._inFlightSave = true;
    let resolveRef, rejectRef;
    this._inFlightSavePromise = new Promise((res, rej)=>{ resolveRef = res; rejectRef = rej; });
    (async () => {
      let response;
      try {
        response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: payload,
          credentials: 'same-origin'
        });
      } catch (netErr) {
        this.debug(`[saveData#${seq}] network error`, netErr);
        throw new Error('Network failure during save');
      }
      if (!response.ok) {
        let detail = '';
        try { detail = await response.text(); } catch (_) {}
        console.warn(`[draft-save#${seq}] non-OK`, response.status, detail.slice(0,300));
        throw new Error(`Save failed: ${response.status}`);
      }
      let json;
      try { json = await response.json(); } catch (parseErr) {
        console.warn(`[draft-save#${seq}] parse error`, parseErr);
        throw new Error('Save response parse error');
      }
      this.debug(`[saveData#${seq}] success`, json);
      resolveRef(json);
    })().catch(err => {
      rejectRef(err);
    }).finally(()=>{
      this._inFlightSave = false;
      this._inFlightSavePromise = null;
    });
    return this._inFlightSavePromise;
  }
  
  // =========================================================================
  // EVENT SYSTEM INTEGRATION
  // =========================================================================
  
  initEventSystem() {
    // Integrate with PackBus if available, otherwise create fallback
    if (window.PackBus) {
      this.modules.eventBus = window.PackBus;
      this.debug('✅ Using existing PackBus event system');
    } else {
      // Create fallback event system
      this.modules.eventBus = this.createFallbackEventBus();
      this.debug('✅ Created fallback event system');
    }
    
    // Subscribe to useful events
    this.modules.eventBus.on('quantity:changed', (data) => {
      this.debug('Quantity changed:', data);
      this.updateRowStatus(data.input);
      this.updateTotals(); // Update footer totals when quantities change
    });
    
    this.modules.eventBus.on('save:requested', () => {
      this.saveData(this.gatherFormData());
    });
    
    this.debug('✅ Event system integrated');
  }
  
  /**
   * Binds all DOM event listeners (inputs, buttons, search, etc.)
   * Wrapped inside a method (previously stray code that caused syntax error).
   */
  bindEvents() {
    // Guard against double binding
    if (this._eventsBound) return; 
    this._eventsBound = true;

    // Delegate quantity + notes input changes
    document.addEventListener('input', (e) => {
      const t = e.target;
      if (t.matches('.qty-input, input[name^="counted_qty"]')) {
        this.updateRowStatus(t);
        this.updateTotals();
        this.modules.autoSave?.triggerSave();
      } else if (t.id === 'notesForTransfer' || t.name === 'notes') {
        this.modules.autoSave?.triggerSave();
      } else if (t.id === 'productSearchInput') {
        // Product search debounce handled in handler
        this.handleProductSearch(t.value.trim());
      }
    });

    // Button clicks (event delegation)
    document.addEventListener('click', (e) => {
      if (e.target.matches('#autofillBtn') || e.target.closest('#autofillBtn')) {
        e.preventDefault();
        this.autofillQuantities();
      } else if (e.target.matches('#resetBtn') || e.target.closest('#resetBtn')) {
        e.preventDefault();
        this.resetQuantities();
      } else if (e.target.matches('#lockDiagnosticBtn') || e.target.closest('#lockDiagnosticBtn')) {
        e.preventDefault();
        this.showLockDiagnostic();
      } else if (e.target.matches('#headerAddProductBtn') || e.target.closest('#headerAddProductBtn')) {
        e.preventDefault();
        this.openAddProductModal();
      } else if (e.target.matches('#clearSearchBtn') || e.target.closest('#clearSearchBtn')) {
        e.preventDefault();
        this.clearProductSearch();
      } else if (e.target.matches('.add-product-btn')) {
        e.preventDefault();
        this.addProductToTransfer(e.target);
      }
    });

    // Initial validation for any pre-filled values
    document.querySelectorAll('.qty-input, input[name^="counted_qty"]').forEach(input => {
      if (input.value) {
        const row = input.closest('tr');
        this.validateRowColors(row);
      }
    });

    // Attempt immediate totals calculation (in case page rendered with values)
    try { this.updateTotals(); } catch (_) {}

    this.debug('✅ Event handlers bound');
  }
  
  // =========================================================================
  // ADD PRODUCT FUNCTIONALITY
  // =========================================================================
  
  openAddProductModal() {
    // Check lock status
    if (this.modules.lockSystem && !this.modules.lockSystem.lockStatus?.has_lock) {
      this.showToast('You need lock access to add products', 'warning');
      return;
    }
    
    const modal = document.getElementById('addProdModal');
    if (modal) {
      $(modal).modal('show');
      
      // Focus search input when modal opens
      $(modal).on('shown.bs.modal', () => {
        const searchInput = document.getElementById('productSearchInput');
        if (searchInput) {
          searchInput.focus();
        }
      });
    }
  }
  
  handleProductSearch(query) {
    if (query.length < 2) {
      this.clearProductSearchResults();
      return;
    }
    
    // Debounce search requests
    if (this.productSearchTimer) {
      clearTimeout(this.productSearchTimer);
    }
    
    this.productSearchTimer = setTimeout(() => {
      this.performProductSearch(query);
    }, 300);
  }
  
  async performProductSearch(query) {
    const resultsContainer = document.getElementById('productSearchResults');
    if (!resultsContainer) return;
    
    // Show loading state
    resultsContainer.innerHTML = `
      <div class="text-center text-muted py-4">
        <i class="fa fa-spinner fa-spin fa-2x mb-2"></i>
        <p>Searching products...</p>
      </div>
    `;
    
    try {
      const response = await fetch('/modules/transfers/stock/api/search_products.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include',
        body: JSON.stringify({
          query: query,
          transfer_id: this.config.transferId
        })
      });
      
      const data = await response.json();
      
      if (data.success && data.products) {
        this.displayProductSearchResults(data.products);
      } else {
        resultsContainer.innerHTML = `
          <div class="text-center text-muted py-4">
            <i class="fa fa-exclamation-triangle fa-2x mb-2"></i>
            <p>No products found matching "${query}"</p>
          </div>
        `;
      }
    } catch (error) {
      console.error('Product search failed:', error);
      resultsContainer.innerHTML = `
        <div class="text-center text-danger py-4">
          <i class="fa fa-exclamation-triangle fa-2x mb-2"></i>
          <p>Search failed. Please try again.</p>
        </div>
      `;
    }
  }
  
  displayProductSearchResults(products) {
    const resultsContainer = document.getElementById('productSearchResults');
    if (!resultsContainer) return;
    
    if (products.length === 0) {
      resultsContainer.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="fa fa-info-circle fa-2x mb-2"></i>
          <p>No products found</p>
        </div>
      `;
      return;
    }
    
    const resultsHtml = products.map(product => `
      <div class="border-bottom p-3">
        <div class="d-flex align-items-center">
          <div class="mr-3">
            ${product.image_url ? 
              `<img src="${product.image_url}" class="rounded" style="width: 50px; height: 50px; object-fit: cover;">` :
              `<div class="bg-light rounded d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;"><i class="fa fa-image text-muted"></i></div>`
            }
          </div>
          <div class="flex-grow-1">
            <h6 class="mb-1">${product.name}</h6>
            <small class="text-muted">SKU: ${product.sku || 'N/A'}</small>
            ${product.brand ? `<small class="text-muted"> • ${product.brand}</small>` : ''}
            <div class="mt-1">
              <span class="badge badge-info">Stock: ${product.stock_qty || 0}</span>
              ${product.price ? `<span class="badge badge-secondary ml-1">$${parseFloat(product.price).toFixed(2)}</span>` : ''}
            </div>
          </div>
          <div>
            <input type="number" class="form-control form-control-sm d-inline-block mr-2" 
                   style="width: 80px;" value="1" min="1" max="${product.stock_qty || 999}" 
                   id="qty-${product.id}">
            <button type="button" class="btn btn-primary btn-sm add-product-btn" 
                    data-product-id="${product.id}" data-product-name="${product.name}">
              <i class="fa fa-plus mr-1"></i>Add
            </button>
          </div>
        </div>
      </div>
    `).join('');
    
    resultsContainer.innerHTML = resultsHtml;
  }
  
  async addProductToTransfer(button) {
    const productId = button.dataset.productId;
    const productName = button.dataset.productName;
    const qtyInput = document.getElementById(`qty-${productId}`);
    const quantity = parseInt(qtyInput?.value || 1);
    
    if (!productId || quantity < 1) {
      this.showToast('Invalid product or quantity', 'error');
      return;
    }
    
    // Disable button and show loading
    button.disabled = true;
    button.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i>Adding...';
    
    try {
      const response = await fetch('/modules/transfers/stock/api/add_product.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'include',
        body: JSON.stringify({
          transfer_id: this.config.transferId,
          product_id: productId,
          quantity: quantity
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        this.showToast(`Added ${quantity}x ${productName} to transfer`, 'success');
        
        // Close modal and refresh page to show new product
        $('#addProdModal').modal('hide');
        setTimeout(() => {
          window.location.reload();
        }, 1000);
      } else {
        throw new Error(data.message || 'Failed to add product');
      }
    } catch (error) {
      console.error('Add product failed:', error);
      this.showToast(`Failed to add product: ${error.message}`, 'error');
      
      // Restore button
      button.disabled = false;
      button.innerHTML = '<i class="fa fa-plus mr-1"></i>Add';
    }
  }
  
  clearProductSearch() {
    const searchInput = document.getElementById('productSearchInput');
    if (searchInput) {
      searchInput.value = '';
      searchInput.focus();
    }
    this.clearProductSearchResults();
  }
  
  clearProductSearchResults() {
    const resultsContainer = document.getElementById('productSearchResults');
    if (resultsContainer) {
      resultsContainer.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="fa fa-search fa-2x mb-2"></i>
          <p>Start typing to search for products...</p>
        </div>
      `;
    }
  }
  
  showLockDiagnostic() {
    // Prefer direct PackLockSystem instance method to avoid recursive global wrapper loops
    if (this.modules.lockSystem && typeof this.modules.lockSystem.showLockDiagnostic === 'function') {
      return this.modules.lockSystem.showLockDiagnostic();
    }
    // Fallback to preserved original (if any)
    const lockStatus = this.getLockStatus();
    const hasLock = !!(lockStatus.hasLock || lockStatus.has_lock);
    const lockedByOther = !!(lockStatus.isLockedByOther || lockStatus.is_locked_by_other);
    console.log('🔒 Updating lock status display (normalized):', { hasLock, lockedByOther, lockStatus });
    
    if (hasLock) {
    console.warn('Lock diagnostic implementation missing');
  }
  
      badge.title = `Locked by you (${lockStatus.userName || lockStatus.holderName || 'Unknown'})`;
    // Show confirmation for unsaved changes
    if (this.hasUnsavedChanges()) {
    } else if (lockedByOther) {
        return;
      }
    }
      badge.title = `Locked by ${lockStatus.lockedByName || lockStatus.holderName || 'another user'}`;
    // Show loading state briefly
    const refreshBtn = document.getElementById('refreshPageBtn');
    if (refreshBtn) {
      const originalHtml = refreshBtn.innerHTML;
      refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      refreshBtn.disabled = true;
      
      setTimeout(() => {
        window.location.reload();
      }, 500);
    } else {
      window.location.reload();
    if (!hasLock && !lockedByOther && (lockStatus.can_request || lockStatus.canRequest || typeof this.modules.lockSystem?.acquireLock === 'function')) {
  }
  
  hasUnsavedChanges() {
    // Check if there are any modified inputs since last save
    const currentData = this.gatherFormData();
    return JSON.stringify(currentData) !== JSON.stringify(this.modules.autoSave?.lastSaveData || {});
  }
  
  updateRowStatus(input) {
    if (!input) return;
    
              // allow a retry later if it failed (e.g., race condition)
              this._autoAcquireTried = false;
    const counted = parseInt(input.value, 10) || 0;
    const planned = parseInt(input.dataset.planned || input.getAttribute('data-planned'), 10) || 0;
    const row = input.closest('tr');
    
    if (!row) return;
    
    // Remove existing classes
    row.classList.remove('qty-match', 'qty-mismatch', 'qty-neutral');
    
    // Add appropriate class
    if (counted === planned && counted > 0) {
      row.classList.add('qty-match');
    } else if (counted !== planned && (counted > 0 || planned > 0)) {
      row.classList.add('qty-mismatch');
    } else {
      row.classList.add('qty-neutral');
    }
    
    // Update status badge
    const statusBadge = row.querySelector('.badge');
    if (statusBadge) {
      if (counted === planned && counted > 0) {
        statusBadge.className = 'badge badge-success';
        statusBadge.textContent = 'Complete';
      } else if (counted > planned) {
        statusBadge.className = 'badge badge-warning';
        statusBadge.textContent = 'Over';
      } else if (counted > 0) {
        statusBadge.className = 'badge badge-info';
        statusBadge.textContent = 'Partial';
      } else {
        statusBadge.className = 'badge badge-secondary';
        statusBadge.textContent = 'Pending';
      }
    }
  }
  
  // =========================================================================
  // PACK ACTIONS
  // =========================================================================
  
  async completePack() {
    const lockStatus = this.getLockStatus();
    if (!lockStatus?.has_lock) {
      this.showToast('You need to have the transfer locked to complete packing', 'error');
      return;
    }
    
    try {
      const data = this.gatherFormData();
      await this.saveData(data, { complete: true });
      this.showToast('Pack completed successfully', 'success');
      
      // Redirect or refresh
      setTimeout(() => {
        window.location.href = `/modules/transfers/stock/pack.php?id=${this.config.transferId}&completed=1`;
      }, 2000);
      
    } catch (error) {
      console.error('Complete pack failed:', error);
      this.showToast('Failed to complete pack', 'error');
    }
  }
  
  async generateLabels() {
    try {
      const response = await fetch(`/modules/transfers/stock/api/generate_labels.php?transfer_id=${this.config.transferId}`);
      const data = await response.json();
      
      if (data.success) {
        this.showToast('Labels generated successfully', 'success');
        if (data.print_url) {
          window.open(data.print_url, '_blank');
        }
      } else {
        throw new Error(data.error || 'Label generation failed');
      }
    } catch (error) {
      console.error('Generate labels failed:', error);
      this.showToast('Failed to generate labels', 'error');
    }
  }
  
  async saveProgress() {
    try {
      this.setAutoSaveStatus('saving');
      
      const data = this.gatherFormData();
      await this.saveData(data);
      
      this.setAutoSaveStatus('saved', 'manual');
      this.showToast('Progress saved successfully', 'success');
      
      this.debug('Manual save completed successfully');
    } catch (error) {
      this.setAutoSaveStatus('error');
      console.error('Save progress failed:', error);
      this.showToast('Failed to save progress - ' + (error.message || 'Unknown error'), 'error');
    }
  }
  
  editItem(productId) {
    this.debug('Edit item:', productId);
    this.showToast('Edit functionality coming soon', 'info');
  }
  
  removeItem(productId) {
    if (!confirm('Are you sure you want to remove this item?')) return;
    
    this.debug('Remove item:', productId);
    this.showToast('Remove functionality coming soon', 'info');
  }
  
  // =========================================================================
  // LOCK VIOLATION DETECTION & HANDLING  
  // =========================================================================
  
  isLockViolation(error) {
    if (!error) return false;
    
    // Check various error indicators
    return error.lock_violation === true ||
           error?.error?.type === 'LOCK_VIOLATION' ||
           error?.error?.code?.includes('LOCK') ||
           (error.status === 423) ||
           (typeof error === 'string' && error.toLowerCase().includes('lock'));
  }
  
  handleLockViolation(error, context = '') {
    console.warn('🔒 Lock Violation Detected:', { error, context });
    
    const details = error?.details || {};
    const lockHolder = details?.locked_by_name || `User ${details?.locked_by_user_id || 'Unknown'}`;
    
    let message;
    let actionText = 'Request Access';
    
    switch (error?.error?.code) {
      case 'LOCK_REQUIRED':
        message = 'You need to acquire the transfer lock to make changes';
        actionText = 'Get Lock';
        break;
      case 'LOCK_DENIED':
        message = `This transfer is locked by ${lockHolder}`;
        actionText = 'Request Access';
        break;
      default:
        message = 'Transfer access denied - lock ownership required';
        actionText = 'Get Access';
    }
    
    // Show lock violation toast with action
    this.showToast(message, 'error', {
      duration: 10000,
      action: {
        label: actionText,
        onClick: () => this.requestLockAccess()
      }
    });
    
    // Update UI to reflect locked state
    this.updateUIForLockViolation(details);
    
    // Emit lock violation event
    this.emit('lock:violation', { error, context, details });
  }
  
  updateUIForLockViolation(details) {
    // Add visual indicators that the transfer is locked
    const badge = document.querySelector('#lockStatusBadge');
    if (badge) {
      badge.textContent = 'LOCKED';
      badge.style.background = 'rgba(220, 53, 69, 0.8)';
      badge.style.borderColor = 'rgba(220, 53, 69, 1)';
    }
    
    // Show subtle overlay message
    this.showLockOverlay(details);
  }
  
  showLockOverlay(details) {
    // Remove existing overlay
    const existing = document.querySelector('#lockViolationOverlay');
    if (existing) existing.remove();
    
    // Create new overlay
    const overlay = document.createElement('div');
    overlay.id = 'lockViolationOverlay';
    overlay.className = 'alert alert-warning border-warning shadow-sm';
    overlay.style.cssText = `
      position: fixed; 
      top: 80px; 
      right: 20px; 
      z-index: 1050; 
      max-width: 350px; 
      font-size: 0.9rem;
    `;
    
    const lockHolder = details?.locked_by_name || 'another user';
    
    overlay.innerHTML = `
      <div class="d-flex align-items-start">
        <i class="fas fa-lock text-warning mr-2 mt-1"></i>
        <div class="flex-grow-1">
          <strong>Transfer Locked</strong><br>
          <small class="text-muted">Locked by ${lockHolder}. Changes are disabled.</small>
        </div>
        <button type="button" class="close ml-2" onclick="this.parentElement.parentElement.remove()">
          <span>&times;</span>
        </button>
      </div>
      <div class="mt-2">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.packSystem?.requestLockAccess?.()">
          <i class="fas fa-key mr-1"></i>Request Access
        </button>
      </div>
    `;
    
    document.body.appendChild(overlay);
    
    // Auto-remove after 30 seconds
    setTimeout(() => {
      if (overlay.parentElement) overlay.remove();
    }, 30000);
  }
  
  requestLockAccess() {
    // Integrate with existing lock system
    if (window.showOwnershipRequestModal && typeof window.showOwnershipRequestModal === 'function') {
      window.showOwnershipRequestModal();
    } else if (this.modules.lockSystem?.requestOwnership) {
      this.modules.lockSystem.requestOwnership();
    } else {
      this.showToast('Lock request system not available', 'warning');
    }
  }
  
  updateLockStatusDisplay() {
    const badge = document.querySelector('#lockStatusBadge');
    if (!badge) {
      console.warn('Lock status badge not found');
      return;
    }
    const lockStatus = this.getLockStatus();
    console.log('🔒 Updating lock status display:', lockStatus);
    
    if (lockStatus.hasLock) {
      badge.textContent = 'LOCKED';
      badge.style.background = 'rgba(40, 167, 69, 0.8)';
      badge.style.borderColor = 'rgba(40, 167, 69, 1)';
      badge.title = `Locked by you (${lockStatus.userName || 'Unknown'})`;
      badge.style.cursor = 'default';
      badge.onclick = null;
    } else if (lockStatus.lockedBy) {
      badge.textContent = 'LOCKED';
      badge.style.background = 'rgba(220, 53, 69, 0.8)';
      badge.style.borderColor = 'rgba(220, 53, 69, 1)';
      badge.title = `Locked by ${lockStatus.lockedByName || 'another user'}`;
      badge.style.cursor = 'pointer';
      badge.onclick = () => this.requestLockAccess();
    } else {
      badge.textContent = 'UNLOCKED';
      badge.style.background = 'rgba(255, 193, 7, 0.8)';
      badge.style.borderColor = 'rgba(255, 193, 7, 1)';
      badge.title = 'No active lock - click to acquire';
      badge.style.cursor = 'pointer';
      badge.onclick = () => this.requestLockAccess();
    }
    
    // NEW: auto-attempt acquisition if we are unlocked and can_request
    if (!lockStatus.has_lock && !lockStatus.is_locked_by_other && (lockStatus.can_request || lockStatus.canRequest)) {
      if (!this._autoAcquireTried) {
        this._autoAcquireTried = true;
        this.debug('Attempting automatic lock acquisition');
        if (this.modules.lockSystem && typeof this.modules.lockSystem.acquireLock === 'function') {
          this.modules.lockSystem.acquireLock().then(r => {
            this.debug('Auto-acquire result', r);
            if (r && r.success) {
              this.modules.autoSave && (this.modules.autoSave.hasPendingChanges = true);
              // trigger save flush soon
              setTimeout(()=>{ if (this.hasLock() && this.modules.autoSave?.hasPendingChanges) this.performAutoSave('auto_acquire'); }, 1500);
            }
          }).catch(e=>{ this.debug('Auto-acquire error', e); });
        }
      }
    }
    console.log('✅ Lock badge updated:', badge.textContent, badge.style.background);
  }
  
  // =========================================================================
  // UTILITY METHODS
  // =========================================================================
  
  autofillQuantities() {
    this.debug('Autofill quantities requested');
    
    // Check lock status using our lock system
    if (this.modules.lockSystem && !this.modules.lockSystem.lockStatus?.has_lock) {
      this.showToast('You need lock access to modify quantities', 'warning');
      return;
    }
    
    // Visual feedback - disable button temporarily
    const autofillBtn = document.querySelector('#autofillBtn');
    if (autofillBtn) {
      autofillBtn.disabled = true;
      autofillBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i>Filling...';
    }
    
    const qtyInputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
    let filledCount = 0;
    
    qtyInputs.forEach(input => {
      const row = input.closest('tr');
      const plannedQty = parseInt(input.dataset.planned || 0);
      
      if (plannedQty > 0) {
        input.value = plannedQty;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        this.validateRowColors(row);
        filledCount++;
      }
    });
    
    // Restore button
    setTimeout(() => {
      if (autofillBtn) {
        autofillBtn.disabled = false;
        autofillBtn.innerHTML = '<i class="fa fa-magic mr-1"></i>Autofill';
      }
    }, 1000);
    
    this.showToast(`Autofilled ${filledCount} quantities with planned amounts`, 'success');
    
    // Update totals and trigger auto-save
    this.updateTotals();
    this.modules.autoSave?.triggerSave();
    
    this.debug(`Autofilled ${filledCount} quantities`);
  }
  
  resetQuantities() {
    this.debug('Reset quantities requested');
    
    // Check lock status using our lock system
    if (this.modules.lockSystem && !this.modules.lockSystem.lockStatus?.has_lock) {
      this.showToast('You need lock access to modify quantities', 'warning');
      return;
    }
    
    // Visual feedback - disable button temporarily
    const resetBtn = document.querySelector('#resetBtn');
    if (resetBtn) {
      resetBtn.disabled = true;
      resetBtn.innerHTML = '<i class="fa fa-spinner fa-spin mr-1"></i>Resetting...';
    }
    
    const qtyInputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
    let resetCount = 0;
    
    qtyInputs.forEach(input => {
      if (input.value && input.value !== '0') {
        input.value = '';
        input.placeholder = '0';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        const row = input.closest('tr');
        this.validateRowColors(row);
        resetCount++;
      }
    });
    
    // Restore button
    setTimeout(() => {
      if (resetBtn) {
        resetBtn.disabled = false;
        resetBtn.innerHTML = '<i class="fa fa-undo mr-1"></i>Reset';
      }
    }, 1000);
    
    this.showToast(`Reset ${resetCount} quantity fields`, 'info');
    
    // Update totals and trigger auto-save
    this.updateTotals();
    this.modules.autoSave?.triggerSave();
    
    this.debug(`Reset ${resetCount} quantities`);
  }
  
  updateTotals() {
    const qtyInputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
    let countedTotal = 0;
    let plannedTotal = 0;
    
    qtyInputs.forEach(input => {
      const qty = parseInt(input.value) || 0;
      countedTotal += qty;
      
      const plannedQty = parseInt(input.dataset.planned || 0);
      plannedTotal += plannedQty;
      
      // Validate row colors on each input
      const row = input.closest('tr');
      this.validateRowColors(row);
    });
    
    // Update footer totals
    const countedFooter = document.querySelector('#countedTotalFooter');
    const diffFooter = document.querySelector('#diffTotalFooter');
    
    if (countedFooter) {
      countedFooter.textContent = countedTotal;
    }
    
    if (diffFooter) {
      const diff = countedTotal - plannedTotal;
      const diffText = diff > 0 ? `+${diff}` : diff.toString();
      diffFooter.textContent = diffText;
      diffFooter.className = diff === 0 ? 'text-success' : (diff > 0 ? 'text-warning' : 'text-danger');
    }
    
    this.debug('Totals updated:', { countedTotal, plannedTotal, diff: countedTotal - plannedTotal });
  }
  
  validateRowColors(row) {
    if (!row) return;
    
    const input = row.querySelector('.qty-input, input[name^="counted_qty"]');
    if (!input) return;
    
    const countedQty = parseInt(input.value) || 0;
    const plannedQty = parseInt(input.dataset.planned || 0);
    
    // Remove all existing validation classes
    row.classList.remove('table-success', 'table-danger', 'table-warning');
    
    if (countedQty === 0) {
      // No color change for 0 counted
      return;
    } else if (countedQty === plannedQty) {
      // Perfect match - green
      row.classList.add('table-success');
    } else {
      // Under or over - red
      row.classList.add('table-danger');
    }
  }
  
  debug(...args) {
    if (this.config.debug) {
      console.log('🔧 PackSystem:', ...args);
    }
  }
  
  emit(eventName, data) {
    this.modules.eventBus.emit(`pack:${eventName}`, data);
  }
  
  on(eventName, callback) {
    this.modules.eventBus.on(`pack:${eventName}`, callback);
  }
  
  cleanup() {
    this.modules.autoSave?.stop();
    this.modules.lockSystem?.cleanup?.();
    this.debug('🧹 TransfersPackSystem cleaned up');
  }
  
  // Public API
  getModule(name) {
    return this.modules[name];
  }
  
  isReady() {
    return this.isInitialized;
  }
  
  getVersion() {
    return '2.0.0';
  }
}

// ============================================================================
// GLOBAL INITIALIZATION AND COMPATIBILITY
// ============================================================================

// Make system globally available
window.TransfersPackSystem = TransfersPackSystem;

// Auto-initialize when ready
function initializePackSystem() {
  if (typeof window.DISPATCH_BOOT !== 'undefined' && window.DISPATCH_BOOT) {
    const config = {
      transferId: window.DISPATCH_BOOT.transfer_id,
      userId: window.DISPATCH_BOOT.user_id,
      debug: window.DISPATCH_BOOT.debug || false
    };
    
    window.packSystem = new TransfersPackSystem(config);
    console.log('✅ Global pack system initialized');
  } else {
    console.warn('DISPATCH_BOOT not available - pack system initialization delayed');
  }
}

// Initialize when ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializePackSystem);
} else {
  // Delay slightly to ensure other scripts have loaded
  setTimeout(initializePackSystem, 100);
}

// ============================================================================
// LEGACY COMPATIBILITY AND GLOBAL FUNCTIONS
// ============================================================================

// Maintain compatibility with existing onclick handlers
// Preserve any pre-existing global diagnostic only once, then provide a bridge.
// Minimal legacy bridge only (remove bulky debug/test helpers in production)
if (!window.__packLockDiagBridgeInstalled) {
  const prior = typeof window.showLockDiagnostic === 'function' ? window.showLockDiagnostic : null;
  window.showLockDiagnostic = function(){
    if (window.packSystem?.showLockDiagnostic) return window.packSystem.showLockDiagnostic();
    if (prior) try { return prior(); } catch(e){ console.warn('Legacy diagnostic error', e); }
    console.warn('No lock diagnostic available');
  };
  window.__packLockDiagBridgeInstalled = true;
}

if (window.packSystem?.config?.debug) {
  console.log('📦 Transfer Pack System v2.0 (debug helpers suppressed in production mode)');
}