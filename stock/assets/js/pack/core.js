// Core base class providing common utilities (debug, event bus integration, config)
export class PackCoreBase {
  constructor(config = {}) {
    this.config = { debug: false, ...config };
    this.modules = this.modules || {};
  }
  debug(...args) { if (this.config.debug) console.log('\uD83D\uDD27 PackSystem:', ...args); }
  emit(eventName, data) { this.modules.eventBus?.emit?.(`pack:${eventName}`, data); }
  on(eventName, cb) { this.modules.eventBus?.on?.(`pack:${eventName}`, cb); }
}

// Fallback event bus (used if global PackBus missing)
export function createFallbackEventBus(debugFn){
  const listeners = {};
  return {
    on(ev,cb){ (listeners[ev]||(listeners[ev]=[])).push(cb); return this; },
    listen(ev,cb){ return this.on(ev,cb); },
    off(ev,cb){ if(listeners[ev]) listeners[ev]=listeners[ev].filter(f=>f!==cb); return this; },
    emit(ev,payload){
      (listeners[ev]||[]).slice().forEach(fn=>{ try{ fn(payload); }catch(e){ debugFn?.('FallbackEventBus handler error',e);} });
      if(!ev.startsWith('pack:')){ const p=`pack:${ev}`; (listeners[p]||[]).slice().forEach(fn=>{ try{ fn(payload);}catch(e){ debugFn?.('FallbackEventBus handler error',e);} }); }
      return this;
    }
  };
}
