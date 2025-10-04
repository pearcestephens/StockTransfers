// autosave-bridge.js
// Extracted inline autosave status bridge from pack.view.php (2025-10-02)
(function(){
  'use strict';
  if(window.AutosaveBridgeLoaded) return; window.AutosaveBridgeLoaded = true;
  function set(txt){ var el=document.getElementById('autosaveStatus'); if(el) el.textContent=txt||''; }
  function setLast(ts){ var el=document.getElementById('autosaveLastSaved'); if(!el) return; var d = ts? new Date(ts): new Date(); if(!isNaN(d.getTime())) el.textContent='Last saved: '+d.toLocaleTimeString(); }
  document.addEventListener('packautosave:state', function(ev){ var st=ev.detail && ev.detail.state; var p=ev.detail && ev.detail.payload; if(st==='saving') set('Saving…'); else if(st==='saved'){ set('Saved'); setLast(p && (p.saved_at||p.savedAt)); } else if(st==='error') set('Retry'); else if(st==='noop'){ /* no change */ } });
})();
