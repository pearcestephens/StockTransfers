// Lock system related mixin
export const LockMixin = Base => class extends Base {
  initLockSystem(){
    if(!this.config.transferId || !this.config.userId){ console.warn('Lock system disabled - missing transferId or userId'); return; }
    const lockStatus = this.getLockStatus();
    const hasLock = !!(lockStatus.hasLock || lockStatus.has_lock);
    const lockedByOther = !!(lockStatus.lockedBy || lockStatus.is_locked_by_other);
    if(hasLock){ this.showToast?.('You hold the lock','info'); }
    else if(lockedByOther){ this.showToast?.(`Locked by ${lockStatus.lockedByName||lockStatus.holderName||'another user'}`,'warning'); }
    else {
      this.showToast?.('No active lock','info');
      if (this.modules.lockSystem?.acquireLock) {
        this.modules.lockSystem.acquireLock().then(r=>{ if(r?.success){ this.showToast?.('Lock acquired','success'); this.updateLockStatusDisplay(); }});
      }
    }
    if(!this._pendingAutosaveWatcher){
      this._pendingAutosaveWatcher=setInterval(()=>{ try{ if(!this.modules.autoSave) return; if(this.modules.autoSave.isSaving) return; if(!this.modules.autoSave.hasPendingChanges) return; if(!this.hasLock()) return; this.debug('Pending changes detected post-lock; performing auto-save'); this.performAutoSave('post_lock_flush'); }catch(e){ console.warn('Pending autosave watcher error',e);} },5000);
    }
    if (window.PackBus?.listen) {
      try { window.PackBus.listen('pack:lock:acquired', () => { if(this.modules?.autoSave?.hasPendingChanges && this.hasLock()){ this.debug('Lock acquired event -> flushing pending autosave'); this.performAutoSave('lock_event'); }}); } catch{}
    }
  }
  getLockStatus(){
    const raw = this.modules.lockSystem?.lockStatus || window.lockStatus || null;
    if(!raw){ return { hasLock:false, has_lock:false, is_locked:false, isLockedByOther:false, is_locked_by_other:false }; }
    const hasLock = !!(raw.hasLock||raw.has_lock);
    const isLockedByOther = !!(raw.isLockedByOther||raw.is_locked_by_other);
    const holderName = raw.holder_name||raw.holderName||raw.lockedByName||null;
    const holderId = raw.holder_id||raw.holderId||raw.lockedBy||raw.locked_by||null;
    return { ...raw, hasLock, has_lock:hasLock, isLockedByOther, is_locked_by_other:isLockedByOther, lockedBy:holderId, lockedByName:holderName, holderName, holderId };
  }
  hasLock(){ const ls=this.getLockStatus(); return !!(ls && (ls.hasLock||ls.has_lock)); }
  updateLockStatusDisplay(){
    const badge=document.querySelector('#lockStatusBadge'); if(!badge){ return; }
    const lockStatus=this.getLockStatus();
    // UI lock blur + toolbar
    try {
      const body=document.body;
      const toolbar=document.getElementById('lockRequestToolbar');
      if(lockStatus.hasLock){
        body.classList.remove('pack-locked-out');
        if(toolbar) toolbar.style.display='none';
      } else if(lockStatus.is_locked_by_other || lockStatus.isLockedByOther){
        body.classList.add('pack-locked-out');
        if(toolbar){
          const holder = lockStatus.holderName||lockStatus.lockedByName||'another user';
            const hn=document.getElementById('lockHolderName'); if(hn) hn.textContent=holder;
          toolbar.style.display='block';
        }
      } else { // unlocked but not owned
        body.classList.add('pack-locked-out');
        if(toolbar){ toolbar.style.display='block'; const hn=document.getElementById('lockHolderName'); if(hn) hn.textContent=''; }
      }
    } catch(e){ console.warn('Lock UI toggle failed', e); }
    if(lockStatus.hasLock){ badge.textContent='LOCKED'; badge.style.background='rgba(40,167,69,0.8)'; badge.style.borderColor='rgba(40,167,69,1)'; badge.title=`Locked by you (${lockStatus.userName||'Unknown'})`; badge.style.cursor='default'; badge.onclick=null; }
    else if(lockStatus.lockedBy){ badge.textContent='LOCKED'; badge.style.background='rgba(220,53,69,0.8)'; badge.style.borderColor='rgba(220,53,69,1)'; badge.title=`Locked by ${lockStatus.lockedByName||'another user'}`; badge.style.cursor='pointer'; badge.onclick=()=>this.requestLockAccess(); }
    else { badge.textContent='UNLOCKED'; badge.style.background='rgba(255,193,7,0.8)'; badge.style.borderColor='rgba(255,193,7,1)'; badge.title='No active lock - click to acquire'; badge.style.cursor='pointer'; badge.onclick=()=>this.requestLockAccess(); }
    if(!lockStatus.has_lock && !lockStatus.is_locked_by_other && (lockStatus.can_request||lockStatus.canRequest)){
      if(!this._autoAcquireTried){ this._autoAcquireTried=true; this.debug('Attempting automatic lock acquisition'); if(this.modules.lockSystem?.acquireLock){ this.modules.lockSystem.acquireLock().then(r=>{ this.debug('Auto-acquire result',r); if(r?.success){ this.modules.autoSave && (this.modules.autoSave.hasPendingChanges=true); setTimeout(()=>{ if(this.hasLock() && this.modules.autoSave?.hasPendingChanges) this.performAutoSave('auto_acquire'); },1500); }}).catch(e=>this.debug('Auto-acquire error',e)); } }
    }
  }
  requestLockAccess(){ if(window.showOwnershipRequestModal?.()) return; if(this.modules.lockSystem?.requestOwnership){ this.modules.lockSystem.requestOwnership(); } else { this.showToast?.('Lock request system not available','warning'); } }
};
