/**
 * Transfers Pack System - Production Shim (v2.0.0-shim)
 * --------------------------------------------------------------------------
 * Purpose: Ultra-thin compatibility layer replacing the historical monolith.
 * Strategy: Defer completely to ModularTransfersPackSystem when it loads.
 * Backups:  /modules/transfers/stock/BACKUPS/pack-unified.v2.0.0.full.js
 * Rollback: Replace this file with the backup & hard refresh (Ctrl+Shift+R).
 * Safety:   No business logic lives here; only bootstrap & diagnostic bridge.
 */
(function(){
  'use strict';

  // Guard legacy autosave duplication early
  if(!window.PackAutoSaveLoaded) window.PackAutoSaveLoaded = true;

  const SHIM_VERSION = '2.0.0-shim';

  class TransfersPackSystem {
    constructor(cfg={}) {
      this._shim = true;
      this.config = { ...cfg };
      this.debug('Shim constructed', this.config);
    }
    debug(...a){ if(this.config.debug) console.log('🔧 PackSystem(Shim):', ...a); }
    getVersion(){ return SHIM_VERSION; }
    isReady(){ return !!window.packSystem && window.packSystem !== this && typeof window.packSystem.isReady === 'function' ? window.packSystem.isReady() : false; }
    showLockDiagnostic(){ console.log('🔒 Shim diagnostic (modular system not yet active)'); }
  }

  // Expose constructor (legacy code may new it manually, retain API surface)
  window.TransfersPackSystem = TransfersPackSystem;

  function buildConfig(){
    const boot = window.DISPATCH_BOOT || {};
    return {
      transferId: boot.transfer_id,
      userId: boot.user_id,
      debug: !!boot.debug
    };
  }

  function instantiate(cfg){
    if(window.ModularTransfersPackSystem){
      window.packSystem = new window.ModularTransfersPackSystem(cfg);
      console.log('✅ Pack system (modular) initialized via shim');
    } else {
      window.packSystem = new TransfersPackSystem(cfg);
      console.log('✅ Pack system (shim placeholder) initialized – awaiting modular upgrade');
      upgradePoll(cfg);
    }
  }

  function upgradePoll(cfg){
    let waited = 0;
    const max = 3000; // 3s window
    const int = setInterval(()=>{
      waited += 100;
      if(window.ModularTransfersPackSystem){
        try {
          const prev = window.packSystem;
            window.packSystem = new window.ModularTransfersPackSystem(cfg);
            console.log('🔁 Upgraded shim to modular implementation');
            if(prev && prev._shim) {/* nothing to cleanup currently */}
        } catch(e){ console.warn('Modular upgrade failed', e); }
        clearInterval(int);
      } else if(waited >= max){
        clearInterval(int);
      }
    }, 100);
  }

  function boot(){
    if(!window.DISPATCH_BOOT){
      // Keep retrying briefly until DISPATCH_BOOT present (early script ordering)
      let attempts = 0;
      const retry = setInterval(()=>{
        attempts++;
        if(window.DISPATCH_BOOT){ clearInterval(retry); instantiate(buildConfig()); }
        else if(attempts >= 40){ clearInterval(retry); console.warn('PackSystem shim: DISPATCH_BOOT not found after 2s'); }
      },50);
      return;
    }
    instantiate(buildConfig());
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // One-time global diagnostic bridge (legacy consumers call showLockDiagnostic())
  if(!window.__packLockDiagBridgeInstalled){
    const prior = typeof window.showLockDiagnostic === 'function' ? window.showLockDiagnostic : null;
    window.showLockDiagnostic = function(){
      if(window.packSystem?.showLockDiagnostic) return window.packSystem.showLockDiagnostic();
      if(prior) { try { return prior(); } catch(e){ console.warn('Legacy diagnostic error', e); } }
      console.warn('No lock diagnostic available (shim)');
    };
    window.__packLockDiagBridgeInstalled = true;
  }

  if(window.DISPATCH_BOOT?.debug) console.log(`📦 Transfer Pack System ${SHIM_VERSION} active`);
})();