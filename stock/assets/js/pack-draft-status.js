/* ============================================================================
 * File: assets/js/stock-transfers/pack-draft-status.js
 * Purpose: Handles the visual state transitions for the draft status pill on
 *          the Transfer Pack page (migrated from inline <script> in pack.php).
 * Scope:   Executed on pages with body[data-page="transfer-pack"].
 * Notes:   Keeps footprint tiny; no dependencies. Exposes window.DraftStatus.set(state, auxTextTimestamp)
 * Author:  Refactor Automation
 * Last Modified: 2025-09-26
 * ========================================================================== */
(function(){
  if(document.body.getAttribute('data-page') !== 'transfer-pack') return; // guard
  var pill = document.getElementById('draft-indicator');
  var label = document.getElementById('draft-indicator-text');
  if(!pill || !label) return;
  var saveTimer = null;
  function applyState(state){
    var states = ['idle','saving','saved','error'];
    for(var i=0;i<states.length;i++){ pill.classList.remove('status-'+states[i]); }
    if(states.indexOf(state) === -1) state='idle';
    pill.classList.add('status-'+state);
    pill.dataset.state = state;
    switch(state){
      case 'saving': label.textContent='Saving…'; pill.ariaLabel='Draft status: saving'; break;
      case 'saved': label.textContent='Saved'; pill.ariaLabel='Draft status: saved'; break;
      case 'error': label.textContent='Error'; pill.ariaLabel='Draft status: error'; break;
      default: label.textContent='Idle'; pill.ariaLabel='Draft status: idle';
    }
  }
  function setState(state, aux){
    if(state==='saved'){
      if(saveTimer) clearTimeout(saveTimer);
      saveTimer = setTimeout(function(){ applyState('saved'); saveTimer=null; }, 1000);
    } else {
      if(saveTimer){ clearTimeout(saveTimer); saveTimer=null; }
      applyState(state);
    }
    if(aux && typeof aux==='string'){
      var last=document.getElementById('draft-last-saved');
      if(last) last.textContent=aux;
    }
  }
  window.DraftStatus = { set: setState };
})();
