// lock-diagnostics.js
// Extracted diagnostic modal + event logging from inline pack.view.php (2025-10-02)
// Depends on window.lockInstance (SimpleLock) & state object (in lock-ui.js) if present.

(function(){
  'use strict';
  if(window.LockDiagnosticsLoaded) return; window.LockDiagnosticsLoaded = true;

  const diagEventLog = [];
  const maxEventLog = 50;
  function logDiagEvent(type, detail){
    diagEventLog.unshift({time:new Date().toISOString(), type, detail});
    if(diagEventLog.length>maxEventLog) diagEventLog.pop();
  }
  window.__logLockDiagEvent = logDiagEvent; // bridge for external modules

  function ensureModal(){
    if(document.getElementById('lockDiagModal')) return;
    const diagModal=document.createElement('div'); diagModal.id='lockDiagModal';
    diagModal.innerHTML=`<div id="lockDiagContent"><h3><span><i class=\"fas fa-stethoscope\"></i> Lock System Diagnostics</span><button type="button" onclick="refreshDiagnostics()">Refresh</button></h3><div id="lockDiagBody">Loading...</div><div class="diag-actions"><button class="btn-copy" type="button" onclick="copyDiagnostics()"><i class="fas fa-copy"></i> Copy All</button><button class="btn-refresh" type="button" onclick="refreshDiagnostics()"><i class="fas fa-sync"></i> Refresh</button><button class="btn-close" type="button" onclick="hideLockDiagnostic()"><i class="fas fa-times"></i> Close</button></div></div>`;
    document.body.appendChild(diagModal);
    diagModal.addEventListener('click', (e)=>{ if(e.target===diagModal) hideLockDiagnostic(); });
  }

  async function showLockDiagnostic(){
    ensureModal();
    const modal = document.getElementById('lockDiagModal');
    const body = document.getElementById('lockDiagBody');
    if(!modal || !body) return;
    const state = window.__lockUiState || {}; // injected by lock-ui.js
    const lockInstance = window.lockInstance || null;

    const transferId = state.transferId || document.getElementById('main')?.getAttribute('data-txid');
    const userId = state.userId || window.DISPATCH_BOOT?.user_id;

    const diag = {
      timestamp: new Date().toISOString(),
      page: { transferId, userId, mode:state.mode, sameOwner:state.sameOwner, restored:state.restored },
      lock: lockInstance ? {
        resourceKey: lockInstance.resourceKey,
        ownerId: lockInstance.ownerId,
        tabId: lockInstance.tabId,
        token: lockInstance.token || 'none',
        alive: lockInstance.alive,
        endpoint: lockInstance.endpoint
      } : null,
      sse: lockInstance?.eventSource ? {
        readyState: lockInstance.eventSource.readyState,
        readyStateText: ['CONNECTING','OPEN','CLOSED'][lockInstance.eventSource.readyState] || 'UNKNOWN',
        url: lockInstance.eventSource.url,
        reconnectAttempts: lockInstance._sseReconnectAttempts || 0,
        connectedAt: lockInstance._sseConnectedAt ? new Date(lockInstance._sseConnectedAt).toISOString() : 'never',
        duration: lockInstance._sseConnectedAt ? Math.floor((Date.now()-lockInstance._sseConnectedAt)/1000)+'s' : 'n/a'
      } : { status: 'not_connected' },
      state: { mode:state.mode, sameOwner:state.sameOwner, countdownActive:!!state.countdownTimer, requestEndsAt:state.requestEndsAt },
      lastBlockedInfo: state.lastBlockedInfo || null,
      eventLog: diagEventLog.slice(0,20)
    };

    // Server status
    let serverStatus=null;
    try { const resp= await fetch('/modules/transfers/stock/api/simple_lock.php?action=status&resource_key='+encodeURIComponent('transfer:'+transferId)); serverStatus=await resp.json(); } catch(e){ serverStatus={error:e.message}; }

    // Build HTML (kept 1:1 with prior inline for consistency)
    let html='';
    html += `<div class="diag-section"><h4><i class="fas fa-desktop"></i> Client State</h4><div class="diag-grid">`;
    html += `<div class="diag-label">Transfer ID:</div><div class="diag-value">${diag.page.transferId||'unknown'}</div>`;
    html += `<div class="diag-label">User ID:</div><div class="diag-value">${diag.page.userId||'unknown'}</div>`;
    html += `<div class="diag-label">Tab ID:</div><div class="diag-value">${diag.lock?.tabId||'none'}</div>`;
    html += `<div class="diag-label">Mode:</div><div class="diag-value ${diag.page.mode==='owning'?'good':diag.page.mode==='spectator'?'warn':''}">${diag.page.mode}</div>`;
    html += `<div class="diag-label">Same Owner:</div><div class="diag-value ${diag.page.sameOwner?'warn':''}">${diag.page.sameOwner?'YES (RED BAR)':'NO (PURPLE BAR)'}</div>`;
    html += `<div class="diag-label">Lock Token:</div><div class="diag-value">${diag.lock?.token||'none'}</div>`;
    html += `<div class="diag-label">Lock Alive:</div><div class="diag-value ${diag.lock?.alive?'good':'bad'}">${diag.lock?.alive?'YES':'NO'}</div>`;
    html += `</div></div>`;

    html += `<div class="diag-section"><h4><i class="fas fa-satellite-dish"></i> SSE Connection</h4><div class="diag-grid">`;
    if(diag.sse.status==='not_connected'){
      html += `<div class="diag-label">Status:</div><div class="diag-value warn">NOT CONNECTED</div>`;
    } else {
      html += `<div class="diag-label">Ready State:</div><div class="diag-value ${diag.sse.readyState===1?'good':diag.sse.readyState===0?'warn':'bad'}">${diag.sse.readyState} (${diag.sse.readyStateText})</div>`;
      html += `<div class="diag-label">URL:</div><div class="diag-value" style="font-size:11px;">${diag.sse.url||'unknown'}</div>`;
      html += `<div class="diag-label">Connected At:</div><div class="diag-value">${diag.sse.connectedAt}</div>`;
      html += `<div class="diag-label">Duration:</div><div class="diag-value">${diag.sse.duration}</div>`;
      html += `<div class="diag-label">Reconnect Attempts:</div><div class="diag-value ${diag.sse.reconnectAttempts>3?'warn':''}">${diag.sse.reconnectAttempts}/5</div>`;
    }
    html += `</div></div>`;

    html += `<div class="diag-section"><h4><i class="fas fa-server"></i> Server Lock Status</h4><div class="diag-grid">`;
    if(serverStatus?.error){
      html += `<div class="diag-label">Error:</div><div class="diag-value bad">${serverStatus.error}</div>`;
    } else if(serverStatus?.ok){
      html += `<div class="diag-label">Locked:</div><div class="diag-value ${serverStatus.locked?'warn':'good'}">${serverStatus.locked?'YES':'NO'}</div>`;
      if(serverStatus.locked){
        html += `<div class="diag-label">Owner ID:</div><div class="diag-value">${serverStatus.owner_id||'unknown'}</div>`;
        html += `<div class="diag-label">Tab ID:</div><div class="diag-value">${serverStatus.tab_id||'unknown'}</div>`;
        html += `<div class="diag-label">Expires In:</div><div class="diag-value ${serverStatus.expires_in<10?'warn':''}">${serverStatus.expires_in}s</div>`;
        const isMyLock = serverStatus.owner_id===String(userId) && serverStatus.tab_id===diag.lock?.tabId;
        html += `<div class="diag-label">Is My Lock:</div><div class="diag-value ${isMyLock?'good':'warn'}">${isMyLock?'YES':'NO'}</div>`;
      }
    } else { html += `<div class="diag-label">Status:</div><div class="diag-value bad">Invalid response</div>`; }
    html += `</div></div>`;

    if(diag.lastBlockedInfo){
      const lb=diag.lastBlockedInfo;
      html += `<div class="diag-section"><h4><i class="fas fa-lock"></i> Last Blocked Info</h4><div class="diag-grid">`;
      html += `<div class="diag-label">Same Owner:</div><div class="diag-value">${lb.same_owner?'YES':'NO'}</div>`;
      html += `<div class="diag-label">Same Tab:</div><div class="diag-value">${lb.same_tab?'YES':'NO'}</div>`;
      html += `<div class="diag-label">Locked By:</div><div class="diag-value">${lb.locked_by||'unknown'}</div>`;
      html += `<div class="diag-label">Locked Tab:</div><div class="diag-value">${lb.locked_tab||'unknown'}</div>`;
      html += `<div class="diag-label">Expires In:</div><div class="diag-value">${lb.expires_in||0}s</div>`;
      html += `</div></div>`;
    }

    html += `<div class="diag-section"><h4><i class="fas fa-list"></i> Recent Events (Last 20)</h4>`;
    if(diag.eventLog.length===0){
      html += `<div style="color:#6b7280;font-style:italic;padding:10px;">No events logged yet</div>`;
    } else {
      html += `<div class="diag-events">`;
      diag.eventLog.forEach(ev=>{
        const time = new Date(ev.time).toLocaleTimeString();
        const detail = JSON.stringify(ev.detail).substring(0,100);
        html += `<div class="diag-event"><span class="diag-event-time">${time}</span> <span class="diag-event-type">${ev.type}</span>: ${detail}</div>`;
      });
      html += `</div>`;
    }
    html += `</div>`;

    const sseHealthy = diag.sse.status!=='not_connected' && diag.sse.readyState===1;
    const lockHealthy = diag.lock?.alive && diag.lock?.token!=='none';
    const stateHealthy = diag.page.mode==='owning' || diag.page.mode==='spectator';
    html += `<div class="diag-section"><h4><i class="fas fa-heartbeat"></i> System Health</h4><div class="diag-grid">`;
    html += `<div class="diag-label">SSE Connection:</div><div class="diag-value ${sseHealthy?'good':'bad'}">${sseHealthy?'✓ HEALTHY':'✗ UNHEALTHY'}</div>`;
    html += `<div class="diag-label">Lock State:</div><div class="diag-value ${lockHealthy?'good':'warn'}">${lockHealthy?'✓ ACTIVE':'○ INACTIVE'}</div>`;
    html += `<div class="diag-label">Page State:</div><div class="diag-value ${stateHealthy?'good':'warn'}">${stateHealthy?'✓ NORMAL':'⚠ ABNORMAL'}</div>`;
    html += `<div class="diag-label">Timestamp:</div><div class="diag-value">${diag.timestamp}</div>`;
    html += `</div></div>`;

    body.innerHTML = html;
    modal.classList.add('visible');
    window._lastDiagData = diag; window._lastServerStatus = serverStatus;
  }

  function hideLockDiagnostic(){ const modal=document.getElementById('lockDiagModal'); if(modal) modal.classList.remove('visible'); }
  function refreshDiagnostics(){ showLockDiagnostic(); }
  function copyDiagnostics(){ const data={ client: window._lastDiagData, server: window._lastServerStatus }; const text=JSON.stringify(data,null,2); navigator.clipboard.writeText(text).then(()=>{ alert('✓ Diagnostics copied to clipboard!'); }).catch(err=>{ console.error('Copy failed:', err); alert('Copy failed. See console.'); console.log('LOCK DIAGNOSTICS:', text); }); }

  window.showLockDiagnostic = showLockDiagnostic;
  window.hideLockDiagnostic = hideLockDiagnostic;
  window.refreshDiagnostics = refreshDiagnostics;
  window.copyDiagnostics = copyDiagnostics;
})();
