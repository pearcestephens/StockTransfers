// pack-toast.js: unified toast system (depends on PackBus if present)
(function(){
  'use strict';
  const MAX = 5;
  const AUTO_MS = 4200;
  const containerId = 'packToastContainer';

  function ensureContainer(){
    let c = document.getElementById(containerId);
    if(!c){
      c = document.createElement('div');
      c.id = containerId;
      c.setAttribute('role','region');
      c.setAttribute('aria-live','polite');
      c.style.position='fixed';
  // Positioned bottom-right per request
  c.style.bottom='12px';
  c.style.right='12px';
  c.style.top='';
      c.style.zIndex='2500';
      c.style.display='flex';
      c.style.flexDirection='column';
      c.style.gap='8px';
      document.body.appendChild(c);
    }
    return c;
  }

  function cls(type){
    switch(type){
      case 'success': return 'pack-toast-success';
      case 'error': return 'pack-toast-error';
      case 'warn': return 'pack-toast-warn';
      default: return 'pack-toast-info';
    }
  }

  function dismiss(el){
    if(!el) return;
    el.classList.add('pack-toast-hide');
    setTimeout(()=> el.remove(), 160);
  }

  const recent = [];// track recent messages for dedupe
  function show(message, type='info', opts={}){
    const now = Date.now();
    // Dedupe identical (same type+msg) within 3s unless force
    for(let i=recent.length-1;i>=0;i--){ if(now - recent[i].t > 3000) recent.splice(i,1); }
    if(!opts.force && recent.some(r=> r.msg===message && r.type===type)){ return null; }
    recent.push({msg:message,type,t:now});

    const c = ensureContainer();
    while(c.children.length >= MAX){ c.firstChild && c.firstChild.remove(); }
    const div = document.createElement('div');
    div.className = 'pack-toast '+cls(type);
    const actionHtml = opts.action && opts.action.label ? '<button class="pack-toast-act" type="button">'+opts.action.label+'</button>' : '';
    div.innerHTML = '<span class="pack-toast-msg"></span>'+actionHtml+'<button class="pack-toast-close" aria-label="Dismiss' + '">&times;</button>';
    div.querySelector('.pack-toast-msg').textContent = message;
    if(actionHtml){
      const actBtn = div.querySelector('.pack-toast-act');
      actBtn.addEventListener('click', ()=> { try{ opts.action.onClick && opts.action.onClick(); }catch(e){} dismiss(div); });
    }
    div.querySelector('.pack-toast-close').addEventListener('click', ()=> dismiss(div));
    c.appendChild(div);
    if(opts.sticky){ return div; }
    const ms = typeof opts.timeout === 'number' ? opts.timeout : AUTO_MS;
    const to = setTimeout(()=> dismiss(div), ms);
    // Pause on hover
    div.addEventListener('mouseenter', ()=> clearTimeout(to), {once:true});
    return div;
  }

  function wireGlobalShortcuts(){
    document.addEventListener('keydown', e=>{
      if(e.key==='Escape'){
        const c=document.getElementById(containerId); if(c && c.lastChild) dismiss(c.lastChild);
      }
      if((e.ctrlKey||e.metaKey) && e.key==='k'){ show('Keyboard shortcut triggered', 'info'); }
    });
  }

  function injectCss(){
    if(document.getElementById('packToastCSS')) return;
    const s = document.createElement('style');
    s.id='packToastCSS';
    s.textContent = `
      .pack-toast{font:13px/1.4 system-ui,Segoe UI,Roboto,Arial;background:#1e2630;color:#fff;padding:10px 14px;border-radius:8px;box-shadow:0 4px 14px -2px rgba(0,0,0,.4);position:relative;overflow:hidden;display:flex;align-items:center;gap:10px;min-width:240px;max-width:340px;}
      .pack-toast-info{background:linear-gradient(135deg,#2d3748,#1a202c);} 
      .pack-toast-success{background:linear-gradient(135deg,#1b7f4d,#15965d);} 
      .pack-toast-error{background:linear-gradient(135deg,#b02733,#7d1627);} 
      .pack-toast-warn{background:linear-gradient(135deg,#b07207,#865603);} 
      .pack-toast-hide{opacity:0;transform:translateY(-6px);transition:all .16s ease;} 
      .pack-toast-close{background:none;border:none;color:#fff;opacity:.65;font-size:16px;line-height:1;padding:0 2px;margin-left:auto;cursor:pointer;} 
      .pack-toast-close:hover{opacity:1;} 
    `;
    document.head.appendChild(s);
  }

  function ready(){
    injectCss();
    wireGlobalShortcuts();
    // Register with PackBus & drain queue
    if(window.PackBus){ window.PackBus.emit('toast:ready', api); }
  }

  const api = { show, success:(m,o)=>show(m,'success',o), error:(m,o)=>show(m,'error',o), warn:(m,o)=>show(m,'warn',o), info:(m,o)=>show(m,'info',o) };
  window.PackToast = Object.assign(window.PackToast||{}, api);

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
