// lock-diagnostic-probe.js
// Lightweight diagnostic overlay / event capture for manual browser testing.
(function(){
  if(window.__LockProbeLoaded) return; window.__LockProbeLoaded = true;
  window.__LOCK_EVENTS = window.__LOCK_EVENTS || [];
  function push(ev){
    ev.ts = new Date().toISOString();
    window.__LOCK_EVENTS.push(ev);
    if(window.__LOCK_EVENTS.length>500){ window.__LOCK_EVENTS.splice(0, window.__LOCK_EVENTS.length-500); }
    if(window.__lockProbePanel){ appendLine(ev); }
  }
  // Monkey-patch onChange after SimpleLock constructed (defer).
  function wrap(){
    if(window.lockInstance && !window.lockInstance.__probeWrapped){
      const orig = window.lockInstance.onChange;
      window.lockInstance.onChange = function(ev){
        try { push({state:ev.state, info:ev.info||{}, reason:ev.reason||ev.error||ev.op||null}); } catch(_){ }
        return orig && orig.apply(this, arguments);
      };
      window.lockInstance.__probeWrapped = true;
    }
  }
  setInterval(wrap, 500);

  // Minimal floating panel
  function createPanel(){
    const div = document.createElement('div');
    div.id='lockProbePanel';
    div.style.position='fixed'; div.style.bottom='10px'; div.style.left='10px'; div.style.width='320px'; div.style.maxHeight='240px';
    div.style.background='rgba(0,0,0,0.8)'; div.style.color='#fff'; div.style.fontSize='11px'; div.style.fontFamily='monospace';
    div.style.overflow='hidden'; div.style.zIndex='99999'; div.style.border='1px solid #444'; div.style.borderRadius='6px';
    div.innerHTML='<div style="padding:4px 6px;display:flex;justify-content:space-between;align-items:center;">'+
      '<strong>Lock Probe</strong><div><button id="lpExport" style="font-size:10px;margin-right:4px;">Export</button><button id="lpClose" style="font-size:10px;">×</button></div></div><div id="lpBody" style="overflow:auto;height:200px;padding:4px 6px;line-height:1.3"></div>';
    document.body.appendChild(div); window.__lockProbePanel = div;
    div.querySelector('#lpClose').onclick=()=>div.remove();
    div.querySelector('#lpExport').onclick=()=>{
      const blob = new Blob([JSON.stringify(window.__LOCK_EVENTS,null,2)], {type:'application/json'});
      const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='lock_events_'+Date.now()+'.json'; a.click();
    };
    window.__LOCK_EVENTS.forEach(appendLine);
  }
  function appendLine(ev){
    const body=document.getElementById('lpBody'); if(!body) return;
    const d=document.createElement('div');
    const color = ev.state==='acquired'||ev.state==='alive' ? '#4ade80' : (ev.state==='blocked' ? '#fbbf24' : (ev.state==='lost' ? '#f87171' : '#93c5fd'));
    d.innerHTML='<span style="color:'+color+'">'+ev.state+'</span> '+(ev.reason?('['+ev.reason+'] '):'')+ (ev.info && ev.info.same_owner!==undefined ? ('so:'+ev.info.same_owner+' st:'+ev.info.same_tab+' ') : '') + ev.ts;
    body.appendChild(d); body.scrollTop=body.scrollHeight;
  }
  createPanel();
})();