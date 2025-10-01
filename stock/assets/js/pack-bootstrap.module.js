/*
 * pack-bootstrap.module.js
 * Modular bootstrap that composes the new mixin-based Pack System without
 * removing the legacy monolith immediately. Include this with:
 * <script type="module" src="/modules/transfers/stock/assets/js/pack-bootstrap.module.js"></script>
 * after any legacy scripts. It will prefer the modular build and fall back
 * to existing global TransfersPackSystem if imports fail.
 */

import { PackCoreBase } from './pack/core.js';
import { LockMixin } from './pack/lock.js';
import { AutoSaveMixin } from './pack/autosave.js';
import { UIMixin } from './pack/ui.js';
import { ProductsMixin } from './pack/products.js';
import { ToastMixin } from './pack/toast.js';
import { EventBusMixin } from './pack/events.js';
import { LockSafetyMixin } from './pack/locksafety.js';
import { ActionsMixin } from './pack/actions.js';

// Compose mixins (inner-most first)
const Composed = ActionsMixin(
  LockSafetyMixin(
    EventBusMixin(
      ToastMixin(
        ProductsMixin(
          UIMixin(
            AutoSaveMixin(
              LockMixin(PackCoreBase)
            )
          )
        )
      )
    )
  )
);

/**
 * New modular class.
 * Mirrors the legacy public API of TransfersPackSystem while using modular mixins.
 */
class ModularTransfersPackSystem extends Composed {
  constructor(config = {}) {
    // Claim legacy autosave guard immediately (matching legacy behavior)
    if (!window.PackAutoSaveLoaded) window.PackAutoSaveLoaded = true;
    super(config);
    this.isInitialized = false;
    this.debug('🚀 ModularTransfersPackSystem initializing...', config);
    this._beginInit();
  }

  _beginInit(){
    if (this.isInitialized) return;
    this._waitForDependencies(()=>{
      // Attach existing global lock system instance if present
      if (!this.modules.lockSystem) {
        if (window.PackLockSystemInstance) this.modules.lockSystem = window.PackLockSystemInstance;
        else if (window.PackLockSystem) this.modules.lockSystem = window.PackLockSystem;
      }
      this.initLockSystem?.();
      this.initToastSystem?.();
      this.initAutoSave?.();
      this.initEventSystem?.();
      this.bindEvents?.();
      this.updateLockStatusDisplay?.();
      this.isInitialized = true;
      this.emit?.('ready', this);
      this.debug('✅ ModularTransfersPackSystem fully initialized');
      this._startRescueLoop();
    });
  }

  _waitForDependencies(cb){
    const check=()=>{ const hasBoot=typeof window.DISPATCH_BOOT !== 'undefined'; if(hasBoot) cb(); else { this.debug('Waiting for DISPATCH_BOOT...'); setTimeout(check,100); } }; check();
  }

  _startRescueLoop(){
    if (this._lockRescueLoopStarted) return;
    this._lockRescueLoopStarted = true;
    let attempts = 0;
    this._lockRescueTimer = setInterval(()=>{
      const st = this.getLockStatus?.() || {};
      const has = !!(st.hasLock || st.has_lock);
      const other = !!(st.isLockedByOther || st.is_locked_by_other);
      if (has || other || attempts >= 6) {
        if (attempts >= 6 && !has && !other) this.debug('🔒 Lock rescue loop gave up (no lock acquired)');
        clearInterval(this._lockRescueTimer); return;
      }
      attempts++;
      this.debug(`🔒 Lock rescue attempt #${attempts}`, st);
      if (this.modules.lockSystem?.acquireLock) {
        this.modules.lockSystem.acquireLock().then(r=>{
          this.debug('🔒 Rescue acquire result', r);
          if(r?.success){ this.modules.autoSave && (this.modules.autoSave.hasPendingChanges = true); setTimeout(()=>{ if(this.hasLock?.() && this.modules.autoSave?.hasPendingChanges) this.performAutoSave?.('rescue_acquire'); },1200); }
        }).catch(e=> this.debug('🔒 Rescue acquire error', e));
      }
    },4000);
  }
}

// Global bridge: Prefer modular, fall back gracefully.
(function establishGlobal(){
  const boot = ()=>{
    if (window.packSystem && window.packSystem instanceof ModularTransfersPackSystem) return; // already active
    if (typeof window.DISPATCH_BOOT === 'undefined' || !window.DISPATCH_BOOT) {
      console.warn('[ModularPack] DISPATCH_BOOT not present yet – delaying init');
      setTimeout(boot,100);
      return;
    }
    const cfg = {
      transferId: window.DISPATCH_BOOT.transfer_id,
      userId: window.DISPATCH_BOOT.user_id,
      debug: window.DISPATCH_BOOT.debug || false
    };
    try {
      window.packSystem = new ModularTransfersPackSystem(cfg);
      window.TransfersPackSystem = ModularTransfersPackSystem; // maintain legacy symbol
      console.log('✅ ModularTransfersPackSystem initialized (modular build)');
    } catch (e) {
      console.error('[ModularPack] initialization failed, falling back if legacy available', e);
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else setTimeout(boot,50);
})();

// Legacy diagnostic bridge (idempotent)
if (!window.__packLockDiagBridgeInstalled) {
  const prior = typeof window.showLockDiagnostic === 'function' ? window.showLockDiagnostic : null;
  window.showLockDiagnostic = function(){
    if (window.packSystem?.showLockDiagnostic) return window.packSystem.showLockDiagnostic();
    if (prior) try { return prior(); } catch(e){ console.warn('Legacy diagnostic error', e); }
    console.warn('No lock diagnostic available');
  };
  window.__packLockDiagBridgeInstalled = true;
}
