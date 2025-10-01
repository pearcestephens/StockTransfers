// Event bus integration mixin
import { createFallbackEventBus } from './core.js';
export const EventBusMixin = Base => class extends Base {
  initEventSystem(){ if(window.PackBus){ this.modules.eventBus=window.PackBus; this.debug('✅ Using existing PackBus event system'); } else { this.modules.eventBus=createFallbackEventBus(msg=>this.debug(msg)); this.debug('✅ Created fallback event system'); }
    this.modules.eventBus.on('quantity:changed', data=>{ this.debug('Quantity changed:',data); this.updateRowStatus?.(data.input); this.updateTotals?.(); });
    this.modules.eventBus.on('save:requested', ()=>{ this.saveData?.(this.gatherFormData?.()); });
    this.debug('✅ Event system integrated (modular)'); }
};
