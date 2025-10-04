// lock-ui.js
// Spectator/request bar & simple lock integration extracted from pack.view.php (2025-10-02)
// Depends on /modules/transfers/stock/assets/js/simple-lock.js (SimpleLock class)
// Exposes window.lockInstance and window.__lockUiState

(function(){
  'use strict';
  if(window.LockUiModuleLoaded) return; window.LockUiModuleLoaded = true;

  const boot = window.DISPATCH_BOOT || {};
  const transferId = boot.transfer_id || document.getElementById('main')?.getAttribute('data-txid');
  const userId = boot.user_id || boot.staff_id || boot.userId || window.CIS_USER_ID || null;
  const badgeEl = document.getElementById('lockStatusBadge');
  const announceEl = document.getElementById('lockStatusAnnounce');
  // Actionable controls disabled in spectator mode.
  // NOTE: Added '#savePackBtn' (actual ID) and kept '#packSaveBtn' (legacy/misnamed) for safety.
  const actionableSelectors = [ '#headerAddProductBtn', 'button[data-save]', 'button.save-transfer', '#savePackBtn' ];

  const state = window.__lockUiState = { mode:'checking', countdownTimer:null, requestEndsAt:null, requestDurationSec:60, restored:false, sameOwner:false, lastBlockedInfo:null };
  let offlineBadge=null;

  function log(type, payload){ if(window.__logLockDiagEvent) window.__logLockDiagEvent(type, payload); }

  function ensureStructure(){
    if(document.getElementById('lockRequestBar')) return;
    const bar=document.createElement('div');
    bar.id='lockRequestBar';
    bar.innerHTML=`<div class="left d-flex align-items-center flex-wrap"><span class="status-label mr-3">Watching Only</span><span class="spectator-note">View live updates; editing requires lock.</span><span id="lockRequestCountdown" class="ml-3 d-none"></span><span id="lockOfflineIndicator" class="ml-3 d-none" style="color:#dc3545;font-weight:600;">OFFLINE</span></div><div class="right d-flex align-items-center" style="gap:10px;"><button id="lockReconnectBtn" type="button" class="d-none">Reconnect</button><button id="lockRequestBtn" type="button">Request Lock</button><button id="lockCancelRequestBtn" type="button" class="d-none">Cancel</button></div>`;
    document.body.appendChild(bar);
    offlineBadge = document.getElementById('lockOfflineIndicator');
  }
  function ensureDiagButton(){
    const btn = document.getElementById('lockDiagnosticBtn');
    if(btn && !btn.dataset.bound){ btn.dataset.bound='1'; btn.addEventListener('click', ()=> window.showLockDiagnostic && window.showLockDiagnostic()); }
  }
  function setDisabled(disabled){ actionableSelectors.forEach(sel=>{ document.querySelectorAll(sel).forEach(btn=>{ btn.disabled=!!disabled; btn.classList.toggle('disabled', !!disabled); }); }); }
  function updateBadge(st, info){ if(!badgeEl) return; badgeEl.classList.remove('pulse','lock-badge--mine','lock-badge--other','lock-badge--unlocked','lock-badge--conflict'); let text='UNLOCKED', cls='lock-badge--unlocked'; switch(st){ case 'acquired': case 'alive': text='LOCKED'; cls='lock-badge--mine'; break; case 'blocked': text='LOCKED BY '+(info && info.holder_name ? info.holder_name.toUpperCase():'OTHER'); cls='lock-badge--other'; break; case 'lost': text='EXPIRED'; cls='lock-badge--conflict'; break; case 'released': text='UNLOCKED'; cls='lock-badge--unlocked'; } badgeEl.textContent=text; badgeEl.title=text; badgeEl.dataset.state=text; badgeEl.classList.add(cls,'pulse'); if(announceEl) announceEl.textContent='Lock status: '+text; }
  function setSpectatorUi(on){ const body=document.body; const pageContainer=document.getElementById('packPageContainer'); if(on){ body.classList.add('spectator-mode'); pageContainer&&pageContainer.classList.add('spectator-blur-wrap'); } else { body.classList.remove('spectator-mode'); pageContainer&&pageContainer.classList.remove('spectator-blur-wrap'); } }
  function applyMode(){ const bar=document.getElementById('lockRequestBar'); if(!bar) return; const reqBtn=document.getElementById('lockRequestBtn'); const cancelBtn=document.getElementById('lockCancelRequestBtn'); const cd=document.getElementById('lockRequestCountdown'); if(state.mode==='owning'){ bar.classList.remove('visible','lock-same-owner'); bar.classList.add('lock-owned'); bar.querySelector('.status-label').textContent='You Have Control'; reqBtn.textContent='Release'; reqBtn.classList.remove('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); } else if(state.mode==='checking'){ bar.classList.remove('visible','lock-owned','lock-same-owner'); bar.querySelector('.status-label').textContent='Loading...'; reqBtn.classList.add('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); } else if(state.mode==='acquiring'){ bar.classList.remove('visible','lock-owned','lock-same-owner'); bar.querySelector('.status-label').textContent='Acquiring Control...'; reqBtn.classList.add('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); } else if(state.mode==='requesting'){ bar.classList.add('visible'); bar.classList.remove('lock-owned'); bar.classList.toggle('lock-same-owner', state.sameOwner); bar.querySelector('.status-label').textContent= state.sameOwner ? 'Your Other Tab Has Control' : 'Requesting Access'; reqBtn.classList.add('d-none'); cancelBtn.classList.remove('d-none'); cd.classList.remove('d-none'); setSpectatorUi(true); setDisabled(true); } else { bar.classList.add('visible'); bar.classList.remove('lock-owned'); bar.classList.toggle('lock-same-owner', state.sameOwner); bar.querySelector('.status-label').textContent= state.sameOwner ? 'Your Other Tab Has Control' : 'Another User Has Control'; reqBtn.textContent= state.sameOwner ? 'Take Control' : 'Request Lock'; reqBtn.classList.remove('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(true); setDisabled(true); } }
  function startCountdown(seconds){ const cd=document.getElementById('lockRequestCountdown'); state.requestEndsAt=Date.now()+seconds*1000; cd.classList.remove('d-none'); if(state.countdownTimer) clearInterval(state.countdownTimer); state.countdownTimer=setInterval(()=>{ const r=Math.max(0,Math.ceil((state.requestEndsAt-Date.now())/1000)); cd.textContent=r+'s'; if(r<=0){ clearInterval(state.countdownTimer); state.countdownTimer=null; finalizeRequestTimeout(); } },1000); }
  function finalizeRequestTimeout(){ if(state.mode==='requesting'){ if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } state.requestEndsAt=null; state.mode='spectator'; applyMode(); } }

  function bindClicks(){ document.addEventListener('click', async function(e){ if(e.target.id==='lockRequestBtn'){ if(state.mode==='owning'){ releaseUnderlying(); state.mode='spectator'; applyMode(); return; } if(state.lastBlockedInfo && state.lastBlockedInfo.same_owner && !state.lastBlockedInfo.same_tab){ log('instant_steal_attempt', state.lastBlockedInfo); const stolen=await stealUnderlying(); if(stolen){ log('instant_steal_success', {}); return; } } state.mode='requesting'; applyMode(); startCountdown(state.requestDurationSec); acquireUnderlying(); } if(e.target.id==='lockCancelRequestBtn'){ if(state.mode==='requesting'){ if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } state.mode='spectator'; applyMode(); } } }); }
  function bindReconnect(){ const btn=document.getElementById('lockReconnectBtn'); if(!btn||btn.dataset.bound) return; btn.dataset.bound='1'; btn.addEventListener('click',()=>{ log('lock_reconnect_clicked',{}); if(window.lockInstance) window.lockInstance.refresh(); }); }

  let lockInstance=null;
  function initUnderlying(){ if(!transferId||!userId){ console.warn('Lock UI: missing identifiers'); return; } lockInstance=new window.SimpleLock({ endpoint:'/modules/transfers/stock/api/simple_lock.php', resourceKey:'transfer:'+transferId, ownerId:String(userId), ttl:90, onChange:function(ev){ const st=ev.state; log('lock_event', {state:st, info:ev.info||{}, error:ev.error||null}); const reconnectBtn=document.getElementById('lockReconnectBtn'); if(st==='acquired'||st==='alive'){ state.mode='owning'; if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } reconnectBtn && reconnectBtn.classList.add('d-none'); offlineBadge && offlineBadge.classList.add('d-none'); } else if(st==='blocked'){ if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } state.lastBlockedInfo=ev.info; state.sameOwner = ev.info && ev.info.same_owner; state.mode='spectator'; reconnectBtn && reconnectBtn.classList.add('d-none'); } else if(st==='lost'){ if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } state.mode='spectator'; reconnectBtn && reconnectBtn.classList.remove('d-none'); } else if(st==='released'){ state.mode='spectator'; reconnectBtn && reconnectBtn.classList.remove('d-none'); } else if(st==='error'){ state.mode='spectator'; if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } reconnectBtn && reconnectBtn.classList.remove('d-none'); } if(ev.info && ev.info.offline){ offlineBadge && offlineBadge.classList.remove('d-none'); } else { offlineBadge && offlineBadge.classList.add('d-none'); } updateBadge(st, ev.info||{}); applyMode(); } }); lockInstance.start(); window.lockInstance=lockInstance; }
  function acquireUnderlying(){ lockInstance && lockInstance.acquire(); }
  function releaseUnderlying(){ lockInstance && lockInstance.release(); }
  function stealUnderlying(){ return lockInstance && lockInstance.steal(); }

  function boot(){ ensureStructure(); ensureDiagButton(); bindClicks(); bindReconnect(); applyMode(); initUnderlying(); window.addEventListener('pagehide', ()=>{ try{ releaseUnderlying(); }catch(e){} }); }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
