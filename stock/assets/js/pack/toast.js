// Toast system wrapper / dedupe
export const ToastMixin = Base => class extends Base {
  initToastSystem(){
    if(!this.modules.toastSystem){
      // Expect global PackToast or simple fallback
      this.modules.toastSystem = window.PackToast || {
        show:(msg,type='info')=>{ console.log(`[TOAST:${type}]`,msg); }
      };
    }
    this._toastRecent = [];
  }
  showToast(message,type='info',options={}){ if(!this.modules.toastSystem) return; const now=Date.now(); if(!this._toastRecent) this._toastRecent=[]; const dedupeWindow=(type==='error')?8000:2500; this._toastRecent=this._toastRecent.filter(r=> now-r.t < dedupeWindow); if(message==='Auto-save failed - please save manually'){ if(!this._lastAutoSaveErrorAt) this._lastAutoSaveErrorAt=0; if(now - this._lastAutoSaveErrorAt < 8000){ this.debug('Suppress duplicate autosave error toast'); return; } this._lastAutoSaveErrorAt=now; }
    if(!options.force && this._toastRecent.some(r=> r.m===message && r.ty===type)) return; this._toastRecent.push({m:message,ty:type,t:now}); return this.modules.toastSystem.show(message,type,options);
  }
};
