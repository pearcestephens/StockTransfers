// lock-selftest.js
// Lightweight runtime verification of lock UI & diagnostics presence.
// Outputs results to console and window.__LOCK_SELFTEST
(function(){
  'use strict';
  if(window.LockSelfTestLoaded) return; window.LockSelfTestLoaded=true;
  function exists(sel){ return !!document.querySelector(sel); }
  function late(fn){ if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
  late(()=>{
    const results = {
      timestamp: new Date().toISOString(),
      elements: {
        lockStatusBadge: exists('#lockStatusBadge'),
        lockDiagnosticBtn: exists('#lockDiagnosticBtn'),
        lockRequestBarStatic: exists('#lockRequestBar'), // may be injected later
        diagModalShell: exists('#lockDiagModal'),
        announcer: exists('#lockStatusAnnounce')
      },
      scripts: {
        simpleLock: typeof window.SimpleLock === 'function',
        lockUiState: typeof window.__lockUiState === 'object',
        diagFns: !!(window.showLockDiagnostic && window.hideLockDiagnostic && window.copyDiagnostics),
        lockInstance: !!window.lockInstance
      }
    };
    // Attempt delayed bar check (it is injected on boot)
    setTimeout(()=>{ results.elements.lockRequestBarAfterDelay = exists('#lockRequestBar'); window.__LOCK_SELFTEST = results; console.log('[LOCK SELFTEST]', results); }, 500);
  });
})();