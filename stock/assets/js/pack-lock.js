class PackLock {// CLEAN LOCK SYSTEM - NO BULLSHIT'use strict';'use strict';'use strict';'use strict';

  constructor(transferId, userId) {

    this.transferId = transferId;class PackLock {

    this.userId = userId;

    this.fingerprint = this.makeFingerprint();  constructor(transferId, userId) {

    this.hasLock = false;

    this.timer = null;    this.transferId = transferId;

  }

    this.userId = userId;/**

  makeFingerprint() {

    let browser = localStorage.getItem('pack_fp');    this.fingerprint = this.makeFingerprint();

    if (!browser) {

      browser = Math.random().toString(36).slice(2, 10);    this.hasLock = false; * pack-lock.js - AESTHETIC SINGLE-TAB LOCK SYSTEM

      localStorage.setItem('pack_fp', browser);

    }    this.timer = null;

    let tab = sessionStorage.getItem('pack_tab');

    if (!tab) {    console.log('PackLock init:', transferId, userId); * /**

      tab = Math.random().toString(36).slice(2, 6);

      sessionStorage.setItem('pack_tab', tab);  }

    }

    return browser + '-' + tab; * Clean, beautiful lock system with smooth transitions and polished UI:

  }

  makeFingerprint() {

  async start() {

    await this.check();    let browser = localStorage.getItem('pack_fp'); * 1. Only 1 browser tab can edit at a time * pack-lock.js - AESTHETIC SINGLE-TAB LOCK SYSTEM

    this.timer = setInterval(() => this.check(), 8000);

  }    if (!browser) {



  async check() {      browser = Math.random().toString(36).slice(2, 10); * 2. Smooth blur transitions when locked out

    try {

      const url = '/modules/transfers/stock/api/lock_gateway.php?action=status&transfer_id=' + this.transferId + '&fingerprint=' + this.fingerprint;      localStorage.setItem('pack_fp', browser);

      const response = await fetch(url);

      const result = await response.json();    } * 3. Beautiful status pills and notifications * /**/**

      

      if (result.success) {    let tab = sessionStorage.getItem('pack_tab');

        const status = result.data;

        if (status.has_lock && status.user_id === this.userId) {    if (!tab) { * 4. Elegant lock overlay with nice typography

          this.hasLock = true;

          this.showUnlocked();      tab = Math.random().toString(36).slice(2, 6);

        } else if (status.is_locked_by_other) {

          this.hasLock = false;      sessionStorage.setItem('pack_tab', tab); * 5. Single API endpoint for simplicity * Clean, beautiful lock system with smooth transitions and polished UI:

          this.showLocked(status.holder_name || 'Another user');

        } else {    }

          await this.acquire();

        }    return browser + '-' + tab; */

      }

    } catch (e) {  }

      console.error('Lock check failed:', e);

    } * 1. Only 1 browser tab can edit at a time * pack-lock.js - LEAN SINGLE-TAB LOCK SYSTEM * pack-lock.js

  }

  async start() {

  async acquire() {

    try {    await this.check();class PackLock {

      const form = new FormData();

      form.append('action', 'acquire');    this.timer = setInterval(() => this.check(), 8000);

      form.append('transfer_id', this.transferId);

      form.append('fingerprint', this.fingerprint);  }  constructor(transferId, userId) { * 2. Smooth blur transitions when locked out

      

      const response = await fetch('/modules/transfers/stock/api/lock_gateway.php', {

        method: 'POST',

        body: form  async check() {    this.transferId = transferId;

      });

          try {

      const result = await response.json();

      if (result.success) {      const url = '/modules/transfers/stock/api/lock_gateway.php?action=status&transfer_id=' + this.transferId + '&fingerprint=' + this.fingerprint;    this.userId = userId; * 3. Beautiful status pills and notifications *  * Transfer Pack Lock System — Legacy Endpoint Adapter + UI

        this.hasLock = true;

        this.showUnlocked();      const response = await fetch(url);

      } else {

        this.hasLock = false;      const result = await response.json();    this.fingerprint = this.generateFingerprint();

        this.showLocked('Another user');

      }      

    } catch (e) {

      console.error('Lock acquire failed:', e);      if (result.success) {    this.hasLock = false; * 4. Elegant lock overlay with nice typography

    }

  }        const status = result.data;



  showUnlocked() {        if (status.has_lock && status.user_id === this.userId) {    this.pollTimer = null;

    document.body.style.filter = '';

    document.body.classList.remove('pack-locked');          this.hasLock = true;

    const overlay = document.getElementById('lockOverlay');

    if (overlay) overlay.style.display = 'none';          this.showUnlocked();     * 5. Single API endpoint for simplicity * Simple rules: * - Waits for UniversalLockSystem before defining the class

    

    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {        } else if (status.is_locked_by_other) {

      el.disabled = false;

    });          this.hasLock = false;    console.log('[PackLock] Init:', { transferId, userId, fingerprint: this.fingerprint });

  }

          this.showLocked(status.holder_name || 'Another user');

  showLocked(holder) {

    document.body.style.filter = 'blur(2px) brightness(0.8)';        } else {    this.injectStyles(); */

    document.body.classList.add('pack-locked');

              await this.acquire();

    const overlay = document.getElementById('lockOverlay');

    if (overlay) {        }  }

      overlay.style.display = 'block';

      overlay.innerHTML = '<i class="fa fa-lock mr-2"></i>Transfer locked by <strong>' + holder + '</strong>';      }

    }

        } catch (e) { * 1. Only 1 browser tab can edit at a time * - Uses legacy PHP endpoints via this.api (relative URLs)

    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {

      el.disabled = true;      console.error('Lock check failed:', e);

    });

  }    }  injectStyles() {



  destroy() {  }

    if (this.timer) clearInterval(this.timer);

  }    // Inject beautiful lock stylesclass PackLock {

}

  async acquire() {

function init() {

  const page = document.querySelector('[data-page="transfer-pack"]');    try {    const style = document.createElement('style');

  if (!page) return;

        const form = new FormData();

  const transferId = page.getAttribute('data-txid');

  const userId = window.DISPATCH_BOOT && window.DISPATCH_BOOT.user_id;      form.append('action', 'acquire');    style.textContent = `  constructor(transferId, userId) { * 2. Uses fingerprint to detect same browser/tab * - Single heartbeat (90s). No duplicate networking or polling loops.

  

  if (transferId && userId) {      form.append('transfer_id', this.transferId);

    window.packLock = new PackLock(transferId, parseInt(userId));

    window.packLock.start();      form.append('fingerprint', this.fingerprint);      .pack-locked {

    

    window.addEventListener('beforeunload', () => {      

      if (window.packLock) window.packLock.destroy();

    });      const response = await fetch('/modules/transfers/stock/api/lock_gateway.php', {        transition: filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;    this.transferId = transferId;

  }

}        method: 'POST',



function waitForBoot() {        body: form      }

  if (window.DISPATCH_BOOT) {

    init();      });

  } else {

    setTimeout(waitForBoot, 100);                this.userId = userId; * 3. Auto-acquire lock on page load * - Uses this.resourceId (never this.transferId)

  }

}      const result = await response.json();



if (document.readyState === 'loading') {      if (result.success) {      #lockOverlay {

  document.addEventListener('DOMContentLoaded', waitForBoot);

} else {        this.hasLock = true;

  setTimeout(waitForBoot, 50);

}        this.showUnlocked();        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);    this.fingerprint = this.generateFingerprint();

      } else {

        this.hasLock = false;        color: white;

        this.showLocked('Another user');

      }        border: none !important;    this.hasLock = false; * 4. Visual feedback: blur page when locked out * - Exposes window.PackLockSystem, showLockDiagnostic(), debugOwnership helpers

    } catch (e) {

      console.error('Lock acquire failed:', e);        border-radius: 12px;

    }

  }        padding: 20px 24px;    this.pollTimer = null;



  showUnlocked() {        margin: 16px 0;

    document.body.style.filter = '';

    document.body.classList.remove('pack-locked');        box-shadow: 0 8px 32px rgba(0,0,0,0.15);     * 5. Single API endpoint: lock_gateway.php */

    const overlay = document.getElementById('lockOverlay');

    if (overlay) overlay.style.display = 'none';        backdrop-filter: blur(10px);

    

    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {        border: 1px solid rgba(255,255,255,0.18);    console.log('[PackLock] Init:', { transferId, userId, fingerprint: this.fingerprint });

      el.disabled = false;

    });        transform: translateY(0);

  }

        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);    this.injectStyles(); */

  showLocked(holder) {

    document.body.style.filter = 'blur(2px) brightness(0.8)';      }

    document.body.classList.add('pack-locked');

            }

    const overlay = document.getElementById('lockOverlay');

    if (overlay) {      #lockOverlay.hiding {

      overlay.style.display = 'block';

      overlay.innerHTML = '<i class="fa fa-lock mr-2"></i>Transfer locked by <strong>' + holder + '</strong>';        opacity: 0;/**

    }

            transform: translateY(-12px);

    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {

      el.disabled = true;      }  injectStyles() {

    });

  }      



  destroy() {      .pack-status-pill {    // Inject beautiful lock stylesclass PackLock { * GATEWAY CONSOLIDATED VERSION (2025-10-02)

    if (this.timer) clearInterval(this.timer);

  }        position: fixed;

}

        top: 20px;    const style = document.createElement('style');

// AUTO START

function init() {        right: 20px;

  const page = document.querySelector('[data-page="transfer-pack"]');

  if (!page) return;        padding: 12px 20px;    style.textContent = `  constructor(transferId, userId) { * Replaces scattered lock_*_mod.php endpoints with single gateway surface:

  

  const transferId = page.getAttribute('data-txid');        border-radius: 25px;

  const userId = window.DISPATCH_BOOT && window.DISPATCH_BOOT.user_id;

          color: white;      .pack-locked {

  if (transferId && userId) {

    window.packLock = new PackLock(transferId, parseInt(userId));        font-weight: 600;

    window.packLock.start();

            font-size: 14px;        transition: filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;    this.transferId = transferId; *   /modules/transfers/stock/api/lock_gateway.php?action=...

    window.addEventListener('beforeunload', () => {

      if (window.packLock) window.packLock.destroy();        z-index: 9999;

    });

  }        box-shadow: 0 4px 20px rgba(0,0,0,0.15);      }

}

        backdrop-filter: blur(10px);

function waitForBoot() {

  if (window.DISPATCH_BOOT) {        border: 1px solid rgba(255,255,255,0.2);          this.userId = userId; * Removed SSE events reliance; we poll request_state when we hold the lock.

    init();

  } else {        transform: translateX(100%);

    setTimeout(waitForBoot, 100);

  }        transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);      #lockOverlay {

}

      }

if (document.readyState === 'loading') {

  document.addEventListener('DOMContentLoaded', waitForBoot);              background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);    this.fingerprint = this.generateFingerprint(); * Adds stable browser + tab fingerprint foundation for future single-tab enforcement.

} else {

  setTimeout(waitForBoot, 50);      .pack-status-pill.show {

}
        transform: translateX(0);        color: white;

      }

              border: none !important;    this.hasLock = false; */

      .pack-status-pill.unlocked {

        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);        border-radius: 12px;

      }

              padding: 20px 24px;    this.pollTimer = null;(function bootstrapPackLock(global) {

      .pack-status-pill.locked {

        background: linear-gradient(135deg, #ff6b6b 0%, #ffa726 100%);        margin: 16px 0;

      }

              box-shadow: 0 8px 32px rgba(0,0,0,0.15);      function define(Base) {

      .pack-locked input, .pack-locked button, .pack-locked select {

        transition: opacity 0.3s ease, filter 0.3s ease;        backdrop-filter: blur(10px);

        opacity: 0.4;

        filter: grayscale(100%);        border: 1px solid rgba(255,255,255,0.18);    console.log('[PackLock] Init:', { transferId, userId, fingerprint: this.fingerprint });    // ---------- Stable fingerprint helpers (browser + tab) ----------

        pointer-events: none;

      }        transform: translateY(0);

      

      .pack-unlock-fade input, .pack-unlock-fade button, .pack-unlock-fade select {        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);  }    function getBrowserFp(){

        transition: opacity 0.4s ease, filter 0.4s ease;

        opacity: 1;      }

        filter: grayscale(0%);

        pointer-events: auto;            try{ let fp = localStorage.getItem('PACK_BROWSER_FP'); if(!fp){ const a=new Uint8Array(16); crypto.getRandomValues(a); fp=Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join(''); localStorage.setItem('PACK_BROWSER_FP', fp);} return fp; }catch(_){ return 'anon'; }

      }

            #lockOverlay.hiding {

      .lock-pulse {

        animation: lockPulse 2s ease-in-out infinite;        opacity: 0;  generateFingerprint() {    }

      }

              transform: translateY(-12px);

      @keyframes lockPulse {

        0%, 100% { opacity: 0.7; }      }    // Browser fingerprint (persistent)    function getTabId(){

        50% { opacity: 1; }

      }      

    `;

    document.head.appendChild(style);      .pack-status-pill {    let browserFp = localStorage.getItem('pack_browser_fp');      try{ let id = sessionStorage.getItem('PACK_TAB_ID'); if(!id){ id = Math.random().toString(36).slice(2,12); sessionStorage.setItem('PACK_TAB_ID', id);} return id; }catch(_){ return 'tab'; }

  }

        position: fixed;

  generateFingerprint() {

    // Browser fingerprint (persistent)        top: 20px;    if (!browserFp) {    }

    let browserFp = localStorage.getItem('pack_browser_fp');

    if (!browserFp) {        right: 20px;

      browserFp = Math.random().toString(36).slice(2, 12);

      localStorage.setItem('pack_browser_fp', browserFp);        padding: 12px 20px;      browserFp = Math.random().toString(36).slice(2, 12);    function buildFingerprint(){ return `bfp:${getBrowserFp()}|tab:${getTabId()}`; }

    }

            border-radius: 25px;

    // Tab ID (session-specific)

    let tabId = sessionStorage.getItem('pack_tab_id');        color: white;      localStorage.setItem('pack_browser_fp', browserFp);

    if (!tabId) {

      tabId = Math.random().toString(36).slice(2, 8);        font-weight: 600;

      sessionStorage.setItem('pack_tab_id', tabId);

    }        font-size: 14px;    }    // Utility functions for robust UX

    

    return `${browserFp}-${tabId}`;        z-index: 9999;

  }

        box-shadow: 0 4px 20px rgba(0,0,0,0.15);        function trapFocus(container) {

  async start() {

    console.log('[PackLock] Starting...');        backdrop-filter: blur(10px);

    await this.checkAndAcquire();

    this.startPolling();        border: 1px solid rgba(255,255,255,0.2);    // Tab ID (session-specific)      const focusable = container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');

  }

        transform: translateX(100%);

  async checkAndAcquire() {

    try {        transition: all 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94);    let tabId = sessionStorage.getItem('pack_tab_id');      const first = focusable[0]; const last = focusable[focusable.length - 1];

      // Try to get current status

      const statusUrl = `/modules/transfers/stock/api/lock_gateway.php?action=status&transfer_id=${this.transferId}&fingerprint=${this.fingerprint}`;      }

      const response = await fetch(statusUrl);

      const result = await response.json();          if (!tabId) {      container.addEventListener('keydown', e => {

      

      if (result.success) {      .pack-status-pill.show {

        const status = result.data;

                transform: translateX(0);      tabId = Math.random().toString(36).slice(2, 8);        if (e.key === 'Tab') {

        if (status.has_lock && status.user_id === this.userId) {

          // We already have the lock      }

          this.hasLock = true;

          this.showUnlocked();            sessionStorage.setItem('pack_tab_id', tabId);          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }

          return;

        }      .pack-status-pill.unlocked {

        

        if (status.is_locked_by_other) {        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);    }          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }

          // Someone else has it

          this.hasLock = false;      }

          this.showLocked(status.holder_name || 'Another user');

          return;                  }

        }

      }      .pack-status-pill.locked {

      

      // Try to acquire        background: linear-gradient(135deg, #ff6b6b 0%, #ffa726 100%);    return `${browserFp}-${tabId}`;        if (e.key === 'Escape') e.preventDefault(); // No escape from modal

      await this.acquire();

            }

    } catch (error) {

      console.error('[PackLock] Check failed:', error);        }      });

      this.showLocked('System error');

    }      .pack-locked input, .pack-locked button, .pack-locked select {

  }

        transition: opacity 0.3s ease, filter 0.3s ease;      first?.focus();

  async acquire() {

    try {        opacity: 0.4;

      const formData = new FormData();

      formData.append('action', 'acquire');        filter: grayscale(100%);  async start() {    }

      formData.append('transfer_id', this.transferId);

      formData.append('fingerprint', this.fingerprint);        pointer-events: none;

      

      const response = await fetch('/modules/transfers/stock/api/lock_gateway.php', {      }    console.log('[PackLock] Starting...');

        method: 'POST',

        body: formData      

      });

            .pack-unlock-fade input, .pack-unlock-fade button, .pack-unlock-fade select {    await this.checkAndAcquire();    function hideInteractiveElements() {

      const result = await response.json();

              transition: opacity 0.4s ease, filter 0.4s ease;

      if (result.success) {

        this.hasLock = true;        opacity: 1;    this.startPolling();      document.querySelectorAll('button, input, select, textarea, .btn, [role="button"]').forEach(el => {

        this.showUnlocked();

        console.log('[PackLock] Lock acquired');        filter: grayscale(0%);

      } else {

        this.hasLock = false;        pointer-events: auto;  }        if (!el.closest('#lockFooterBar, #ownershipRequestModal, .lock-ro-focus')) {

        this.showLocked(result.data?.holder_name || 'Another user');

        console.log('[PackLock] Lock denied:', result.error);      }

      }

                      el.style.visibility = 'hidden';

    } catch (error) {

      console.error('[PackLock] Acquire failed:', error);      .lock-pulse {

      this.showLocked('Connection error');

    }        animation: lockPulse 2s ease-in-out infinite;  async checkAndAcquire() {          el.classList.add('lock-hidden');

  }

      }

  async release() {

    if (!this.hasLock) return;          try {        }

    

    try {      @keyframes lockPulse {

      const formData = new FormData();

      formData.append('action', 'release');        0%, 100% { opacity: 0.7; }      // Try to get current status      });

      formData.append('transfer_id', this.transferId);

      formData.append('fingerprint', this.fingerprint);        50% { opacity: 1; }

      

      await fetch('/modules/transfers/stock/api/lock_gateway.php', {      }      const statusUrl = `/modules/transfers/stock/api/lock_gateway.php?action=status&transfer_id=${this.transferId}&fingerprint=${this.fingerprint}`;    }

        method: 'POST',

        body: formData    `;

      });

          document.head.appendChild(style);      const response = await fetch(statusUrl);

      this.hasLock = false;

      console.log('[PackLock] Lock released');  }

      

    } catch (error) {      const result = await response.json();    function restoreInteractiveElements() {

      console.error('[PackLock] Release failed:', error);

    }  generateFingerprint() {

  }

    // Browser fingerprint (persistent)            document.querySelectorAll('.lock-hidden').forEach(el => {

  showUnlocked() {

    console.log('[PackLock] State: UNLOCKED');    let browserFp = localStorage.getItem('pack_browser_fp');

    

    // Smooth transition back to normal    if (!browserFp) {      if (result.success) {        el.style.visibility = '';

    document.body.style.transition = 'filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';

    document.body.style.filter = '';      browserFp = Math.random().toString(36).slice(2, 12);

    document.body.classList.remove('pack-locked');

    document.body.classList.add('pack-unlock-fade');      localStorage.setItem('pack_browser_fp', browserFp);        const status = result.data;        el.classList.remove('lock-hidden');

    

    // Smooth hide lock overlay    }

    const overlay = document.getElementById('lockOverlay');

    if (overlay) {                  });

      overlay.classList.add('hiding');

      setTimeout(() => {    // Tab ID (session-specific)

        overlay.style.display = 'none';

        overlay.classList.remove('hiding');    let tabId = sessionStorage.getItem('pack_tab_id');        if (status.has_lock && status.user_id === this.userId) {    }

      }, 400);

    }    if (!tabId) {

    

    // Show beautiful unlock notification      tabId = Math.random().toString(36).slice(2, 8);          // We already have the lock

    this.showStatusPill('🔓 You have control', 'unlocked');

          sessionStorage.setItem('pack_tab_id', tabId);

    // Clean up after transition

    setTimeout(() => {    }          this.hasLock = true;    const GATEWAY = '/modules/transfers/stock/api/lock_gateway.php';

      document.body.classList.remove('pack-unlock-fade');

    }, 600);    

  }

    return `${browserFp}-${tabId}`;          this.showUnlocked();

  showLocked(holderName = 'Another user') {

    console.log('[PackLock] State: LOCKED by', holderName);  }

    

    // Beautiful blur transition          return;    class PackLockSystem extends Base {

    document.body.style.transition = 'filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';

    document.body.style.filter = 'blur(3px) brightness(0.7)';  async start() {

    document.body.classList.add('pack-locked');

        console.log('[PackLock] Starting...');        }      constructor(transferId, userId, opts = {}) {

    // Show beautiful lock overlay

    const overlay = document.getElementById('lockOverlay');    await this.checkAndAcquire();

    if (overlay) {

      overlay.style.display = 'block';    this.startPolling();                const api = { gateway: GATEWAY, staff: '/modules/transfers/stock/api/get_staff_users.php' };

      overlay.innerHTML = `

        <div style="display: flex; align-items: center; justify-content: space-between;">  }

          <div>

            <div style="display: flex; align-items: center; margin-bottom: 8px;">        if (status.is_locked_by_other) {        super({

              <i class="fa fa-lock mr-3" style="font-size: 20px; opacity: 0.9;"></i>

              <span style="font-size: 16px; font-weight: 600;">Transfer Locked</span>  async checkAndAcquire() {

            </div>

            <div style="font-size: 14px; opacity: 0.9;">    try {          // Someone else has it          resourceType: 'transfer',

              <strong>${holderName}</strong> is currently editing this transfer

            </div>      // Try to get current status

          </div>

          <div class="lock-pulse" style="opacity: 0.7;">      const statusUrl = `/modules/transfers/stock/api/lock_gateway.php?action=status&transfer_id=${this.transferId}&fingerprint=${this.fingerprint}`;          this.hasLock = false;          resourceId  : transferId,

            <i class="fa fa-circle" style="font-size: 12px;"></i>

          </div>      const response = await fetch(statusUrl);

        </div>

      `;      const result = await response.json();          this.showLocked(status.holder_name || 'Another user');          userId,

      overlay.style.opacity = '0';

      overlay.style.transform = 'translateY(-10px)';      

      

      // Animate in      if (result.success) {          return;          api,

      setTimeout(() => {

        overlay.style.transition = 'all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';        const status = result.data;

        overlay.style.opacity = '1';

        overlay.style.transform = 'translateY(0)';                }          pollInterval: 10000,

      }, 100);

    }        if (status.has_lock && status.user_id === this.userId) {

    

    // Show locked notification          // We already have the lock      }          lockDuration: 1800,

    this.showStatusPill(`🔒 Locked by ${holderName}`, 'locked');

  }          this.hasLock = true;



  showStatusPill(text, type) {          this.showUnlocked();                debug: !!opts.debug,

    // Remove any existing pill

    const existing = document.querySelector('.pack-status-pill');          return;

    if (existing) existing.remove();

            }      // Try to acquire          onLockAcquired : (st) => this.onLockAcquired(st),

    // Create new pill

    const pill = document.createElement('div');        

    pill.className = `pack-status-pill ${type}`;

    pill.textContent = text;        if (status.is_locked_by_other) {      await this.acquire();          onLockLost     : () => this.onLockLost(),

    document.body.appendChild(pill);

              // Someone else has it

    // Animate in

    setTimeout(() => pill.classList.add('show'), 100);          this.hasLock = false;                onLockRequested: (req) => this.onLockRequested(req),

    

    // Auto-hide after 3 seconds          this.showLocked(status.holder_name || 'Another user');

    setTimeout(() => {

      pill.classList.remove('show');          return;    } catch (error) {          onReadOnlyMode : (st) => this.onReadOnlyMode(st)

      setTimeout(() => pill.remove(), 500);

    }, 3000);        }

  }

      }      console.error('[PackLock] Check failed:', error);        });

  startPolling() {

    if (this.pollTimer) clearInterval(this.pollTimer);      

    

    // Check status every 8 seconds      // Try to acquire      this.showLocked('System error');        this.api = api;

    this.pollTimer = setInterval(() => {

      this.checkAndAcquire();      await this.acquire();

    }, 8000);

  }          }        this.userId = userId;



  destroy() {    } catch (error) {

    if (this.pollTimer) {

      clearInterval(this.pollTimer);      console.error('[PackLock] Check failed:', error);  }        this.fingerprint = buildFingerprint();

      this.pollTimer = null;

    }      this.showLocked('System error');

    this.release();

  }    }        this._requestPollTimer = null;

}

  }

// Auto-start when page loads

function initPackLock() {  async acquire() {        this._bc = null; // BroadcastChannel

  const pageEl = document.querySelector('[data-page="transfer-pack"]');

  if (!pageEl) return;  async acquire() {

  

  const transferId = pageEl.getAttribute('data-txid');    try {    try {        this._prevStateSig = '';

  const userId = window.DISPATCH_BOOT?.user_id;

        const formData = new FormData();

  if (!transferId || !userId) {

    console.warn('[PackLock] Missing data:', { transferId, userId });      formData.append('action', 'acquire');      const formData = new FormData();        this.initBroadcastChannel();

    return;

  }      formData.append('transfer_id', this.transferId);

  

  // Create global instance      formData.append('fingerprint', this.fingerprint);      formData.append('action', 'acquire');        global.packLockSystem = this; // dev reach

  window.packLock = new PackLock(transferId, parseInt(userId, 10));

  window.packLock.start();      

  

  // Auto-cleanup on page unload      const response = await fetch('/modules/transfers/stock/api/lock_gateway.php', {      formData.append('transfer_id', this.transferId);      }

  window.addEventListener('beforeunload', () => {

    if (window.packLock) {        method: 'POST',

      window.packLock.destroy();

    }        body: formData      formData.append('fingerprint', this.fingerprint);

  });

}      });



// Wait for DISPATCH_BOOT and start                  /* -------------------------

function waitForBoot() {

  if (window.DISPATCH_BOOT) {      const result = await response.json();

    initPackLock();

  } else {            const response = await fetch('/modules/transfers/stock/api/lock_gateway.php', {       * Legacy endpoint adapters

    setTimeout(waitForBoot, 100);

  }      if (result.success) {

}

        this.hasLock = true;        method: 'POST',       * ------------------------- */

if (document.readyState === 'loading') {

  document.addEventListener('DOMContentLoaded', waitForBoot);        this.showUnlocked();

} else {

  setTimeout(waitForBoot, 50);        console.log('[PackLock] Lock acquired');        body: formData

}

      } else {

// Export for debugging

window.PackLock = PackLock;        this.hasLock = false;      });      // Helper: fingerprint (fallback if base doesn’t provide one)

        this.showLocked(result.data?.holder_name || 'Another user');

        console.log('[PackLock] Lock denied:', result.error);            fingerprint() {

      }

            const result = await response.json();        try {

    } catch (error) {

      console.error('[PackLock] Acquire failed:', error);                if (typeof super.fingerprint === 'function') return super.fingerprint();

      this.showLocked('Connection error');

    }      if (result.success) {        } catch (_) {}

  }

        this.hasLock = true;        return `${navigator.userAgent}|uid:${this.userId || '0'}`;

  async release() {

    if (!this.hasLock) return;        this.showUnlocked();      }

    

    try {        console.log('[PackLock] Lock acquired');

      const formData = new FormData();

      formData.append('action', 'release');      } else {      // 1) STATUS

      formData.append('transfer_id', this.transferId);

      formData.append('fingerprint', this.fingerprint);        this.hasLock = false;      async checkLockStatus() {

      

      await fetch('/modules/transfers/stock/api/lock_gateway.php', {        this.showLocked(result.data?.holder_name || 'Another user');        try {

        method: 'POST',

        body: formData        console.log('[PackLock] Lock denied:', result.error);          const qp = `action=status&transfer_id=${encodeURIComponent(this.resourceId)}&fp=${encodeURIComponent(this.fingerprint)}`;

      });

            }            const res = await fetch(`${this.api.gateway}?${qp}`, { credentials:'include', headers:{'X-Requested-With':'XMLHttpRequest'} });

      this.hasLock = false;

      console.log('[PackLock] Lock released');                  const json = await res.json();

      

    } catch (error) {    } catch (error) {            if(json && json.success){

      console.error('[PackLock] Release failed:', error);

    }      console.error('[PackLock] Acquire failed:', error);              const s = json.data || {};

  }

      this.showLocked('Connection error');              const next = {

  showUnlocked() {

    console.log('[PackLock] State: UNLOCKED');    }                has_lock: !!s.has_lock,

    

    // Smooth transition back to normal  }                is_locked: !!s.is_locked,

    document.body.style.transition = 'filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';

    document.body.style.filter = '';                is_locked_by_other: !!s.is_locked_by_other,

    document.body.classList.remove('pack-locked');

    document.body.classList.add('pack-unlock-fade');  async release() {                holder_name: s.holder_name || null,

    

    // Smooth hide lock overlay    if (!this.hasLock) return;                expires_at: s.expires_at || null,

    const overlay = document.getElementById('lockOverlay');

    if (overlay) {                    lock_acquired_at: s.lock_acquired_at || null

      overlay.classList.add('hiding');

      setTimeout(() => {    try {              };

        overlay.style.display = 'none';

        overlay.classList.remove('hiding');      const formData = new FormData();              try { super.updateLockStatus(next); } catch(_){}

      }, 400);

    }      formData.append('action', 'release');              this.updateLockStatus(next);

    

    // Show beautiful unlock notification      formData.append('transfer_id', this.transferId);              const target = next.has_lock ? 60000 : 10000;

    this.showStatusPill('🔓 You have control', 'unlocked');

          formData.append('fingerprint', this.fingerprint);              if (this.pollInterval !== target) { this.pollInterval = target; this.stopPolling?.(); this.startPolling?.(); }

    // Clean up after transition

    setTimeout(() => {                    if (next.has_lock) this.scheduleRequestPoll(); else this.clearRequestPoll();

      document.body.classList.remove('pack-unlock-fade');

    }, 600);      await fetch('/modules/transfers/stock/api/lock_gateway.php', {            }

  }

        method: 'POST',            return json;

  showLocked(holderName = 'Another user') {

    console.log('[PackLock] State: LOCKED by', holderName);        body: formData        } catch(e){

    

    // Beautiful blur transition      });          console.warn('[PackLock] status fail', e); return {success:false,error:'Network error'};

    document.body.style.transition = 'filter 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';

    document.body.style.filter = 'blur(3px) brightness(0.7)';              }

    document.body.classList.add('pack-locked');

          this.hasLock = false;      }

    // Show beautiful lock overlay

    const overlay = document.getElementById('lockOverlay');      console.log('[PackLock] Lock released');

    if (overlay) {

      overlay.style.display = 'block';            // 2) ACQUIRE

      overlay.innerHTML = `

        <div style="display: flex; align-items: center; justify-content: space-between;">    } catch (error) {      async acquireLock() {

          <div>

            <div style="display: flex; align-items: center; margin-bottom: 8px;">      console.error('[PackLock] Release failed:', error);        try {

              <i class="fa fa-lock mr-3" style="font-size: 20px; opacity: 0.9;"></i>

              <span style="font-size: 16px; font-weight: 600;">Transfer Locked</span>    }          const body = new URLSearchParams({ action:'acquire', transfer_id:String(this.resourceId), fingerprint:this.fingerprint });

            </div>

            <div style="font-size: 14px; opacity: 0.9;">  }          const res = await fetch(this.api.gateway, { method:'POST', credentials:'include', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body });

              <strong>${holderName}</strong> is currently editing this transfer

            </div>          const data = await res.json();

          </div>

          <div class="lock-pulse" style="opacity: 0.7;">  showUnlocked() {          if(data.success){

            <i class="fa fa-circle" style="font-size: 12px;"></i>

          </div>    console.log('[PackLock] State: UNLOCKED');            const lock = data.lock || {};

        </div>

      `;                const next = { has_lock:true,is_locked:true,is_locked_by_other:false, holder_name:lock.holder_name||null, expires_at:lock.expires_at||null, lock_acquired_at:lock.acquired_at||null };

      overlay.style.opacity = '0';

      overlay.style.transform = 'translateY(-10px)';    // Remove blur and enable inputs            try { super.updateLockStatus(next);} catch(_){ }

      

      // Animate in    document.body.style.filter = '';            this.updateLockStatus(next);

      setTimeout(() => {

        overlay.style.transition = 'all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94)';    document.body.classList.remove('pack-locked');            this.startHeartbeat();

        overlay.style.opacity = '1';

        overlay.style.transform = 'translateY(0)';                this.onLockAcquired(lock);

      }, 100);

    }    // Enable all form inputs            this.broadcastStateChange('acquire');

    

    // Show locked notification    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {            return {success:true,lock};

    this.showStatusPill(`🔒 Locked by ${holderName}`, 'locked');

  }      el.disabled = false;          }



  showStatusPill(text, type) {    });          return {success:false,error:data.error};

    // Remove any existing pill

    const existing = document.querySelector('.pack-status-pill');            }catch(e){ return {success:false,error:'Network error'}; }

    if (existing) existing.remove();

        // Hide lock overlay      }

    // Create new pill

    const pill = document.createElement('div');    const overlay = document.getElementById('lockOverlay');

    pill.className = `pack-status-pill ${type}`;

    pill.textContent = text;    if (overlay) overlay.style.display = 'none';      // 3) RELEASE

    document.body.appendChild(pill);

      }      async releaseLock(force=false){

    // Animate in

    setTimeout(() => pill.classList.add('show'), 100);        try {

    

    // Auto-hide after 3 seconds  showLocked(holderName = 'Another user') {          const body = new URLSearchParams({ action: force? 'force_release':'release', transfer_id:String(this.resourceId) });

    setTimeout(() => {

      pill.classList.remove('show');    console.log('[PackLock] State: LOCKED by', holderName);          const res = await fetch(this.api.gateway, { method:'POST', credentials:'include', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body });

      setTimeout(() => pill.remove(), 500);

    }, 3000);              const data = await res.json();

  }

    // Blur page and disable inputs          if(data.success){

  startPolling() {

    if (this.pollTimer) clearInterval(this.pollTimer);    document.body.style.filter = 'blur(2px)';            const next = { has_lock:false,is_locked:false,is_locked_by_other:false };

    

    // Check status every 8 seconds    document.body.classList.add('pack-locked');            try { super.updateLockStatus(next);} catch(_){ }

    this.pollTimer = setInterval(() => {

      this.checkAndAcquire();                this.updateLockStatus(next);

    }, 8000);

  }    // Disable all form inputs            this.stopHeartbeat();



  destroy() {    document.querySelectorAll('#transferItemsTable input, button, select').forEach(el => {            this.onLockLost();

    if (this.pollTimer) {

      clearInterval(this.pollTimer);      el.disabled = true;            this.broadcastStateChange(force? 'force_release':'release');

      this.pollTimer = null;

    }    });          }

    this.release();

  }                return data;

}

    // Show lock overlay        }catch(e){ return {success:false,error:'Network error'}; }

// Auto-start when page loads

function initPackLock() {    const overlay = document.getElementById('lockOverlay');      }

  const pageEl = document.querySelector('[data-page="transfer-pack"]');

  if (!pageEl) return;    if (overlay) {

  

  const transferId = pageEl.getAttribute('data-txid');      overlay.style.display = 'block';      // 4) HEARTBEAT — every 90s

  const userId = window.DISPATCH_BOOT?.user_id;

        overlay.innerHTML = `<i class="fa fa-lock mr-2"></i>Transfer is being edited by <strong>${holderName}</strong>`;      startHeartbeat(){ if(this.heartbeatTimer) return; this.heartbeatTimer = setInterval(async ()=>{ if(!this.lockStatus?.has_lock) return; try { const body = new URLSearchParams({action:'heartbeat',transfer_id:String(this.resourceId)}); await fetch(this.api.gateway,{method:'POST',credentials:'include',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body}); }catch(_){} }, 90000); }

  if (!transferId || !userId) {

    console.warn('[PackLock] Missing data:', { transferId, userId });    }      stopHeartbeat() {

    return;

  }  }        if (this.heartbeatTimer) { clearInterval(this.heartbeatTimer); this.heartbeatTimer = null; }

  

  // Create global instance      }

  window.packLock = new PackLock(transferId, parseInt(userId, 10));

  window.packLock.start();  startPolling() {

  

  // Auto-cleanup on page unload    if (this.pollTimer) clearInterval(this.pollTimer);      // 5) REQUEST OWNERSHIP (modern start)

  window.addEventListener('beforeunload', () => {

    if (window.packLock) {          async requestOwnership(message='Requesting access') {

      window.packLock.destroy();

    }    // Check status every 8 seconds        try { const body = new URLSearchParams({action:'request_start',transfer_id:String(this.resourceId),message, fingerprint:this.fingerprint}); const res = await fetch(this.api.gateway,{method:'POST',credentials:'include',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body}); return await res.json(); } catch { return {success:false,error:'Network error'}; }

  });

}    this.pollTimer = setInterval(() => {      }



// Wait for DISPATCH_BOOT and start      this.checkAndAcquire();

function waitForBoot() {

  if (window.DISPATCH_BOOT) {    }, 8000);      // SSE removed – use polling

    initPackLock();

  } else {  }      ensureRequestEvents(){ /* no-op (deprecated) */ }

    setTimeout(waitForBoot, 100);

  }

}

  destroy() {      async handleLockRequestEvent(payload){

if (document.readyState === 'loading') {

  document.addEventListener('DOMContentLoaded', waitForBoot);    if (this.pollTimer) {        if(!payload || !payload.state) return;

} else {

  setTimeout(waitForBoot, 50);      clearInterval(this.pollTimer);        const st = payload.state;

}

      this.pollTimer = null;        if((st==='accepted' || payload.state_alias==='granted') && payload.requesting_user_id === this.userId){

// Export for debugging

window.PackLock = PackLock;    }          const r = await this.acquireLock();

    this.release();          if(r?.success) this.checkLockStatus();

  }          return;

}        }

        if(st==='pending' && payload.action_required){ this.showPendingDecision(payload); }

// Auto-start when page loads      }

function initPackLock() {

  const pageEl = document.querySelector('[data-page="transfer-pack"]');      showPendingDecision(payload){

  if (!pageEl) return;        if(this._pendingDecisionBanner) return;

          const bar = document.createElement('div');

  const transferId = pageEl.getAttribute('data-txid');        bar.className='lock-decision-banner';

  const userId = window.DISPATCH_BOOT?.user_id;        bar.style.cssText='position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#222;color:#fff;padding:8px 12px;font-size:14px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -2px 6px rgba(0,0,0,.3)';

          bar.innerHTML=`<span>Lock request from <strong>${payload.requesting_user_name||('User '+payload.requesting_user_id)}</strong></span><span><button id="lockDecideAccept" class="btn btn-sm btn-success mr-2">Give Lock</button><button id="lockDecideDecline" class="btn btn-sm btn-danger">Decline</button></span>`;

  if (!transferId || !userId) {        document.body.appendChild(bar);

    console.warn('[PackLock] Missing data:', { transferId, userId });        bar.querySelector('#lockDecideAccept').onclick=()=>this.decideRequest(payload.request_id,true);

    return;        bar.querySelector('#lockDecideDecline').onclick=()=>this.decideRequest(payload.request_id,false);

  }        this._pendingDecisionBanner = bar;

        }

  // Create global instance

  window.packLock = new PackLock(transferId, parseInt(userId, 10));      async decideRequest(requestId, accept){

  window.packLock.start();        try {

            const body = new URLSearchParams({ action:'request_decide', transfer_id:String(this.resourceId), decision: accept?'grant':'decline' });

  // Auto-cleanup on page unload          const res = await fetch(this.api.gateway, { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest' }, body });

  window.addEventListener('beforeunload', () => {          const json = await res.json();

    if (window.packLock) {          if(this._pendingDecisionBanner){ this._pendingDecisionBanner.remove(); this._pendingDecisionBanner = null; }

      window.packLock.destroy();          if(json.success && json.state==='accepted'){ this.checkLockStatus(); }

    }          return json;

  });        } catch(e){ console.warn('Decision error', e); }

}      }



// Wait for DISPATCH_BOOT and start      // 6) PENDING REQUESTS

function waitForBoot() {      async pollRequestState(){

  if (window.DISPATCH_BOOT) {        try {

    initPackLock();          const qp = `action=request_state&transfer_id=${encodeURIComponent(this.resourceId)}`;

  } else {          const res = await fetch(`${this.api.gateway}?${qp}`, {credentials:'include',headers:{'X-Requested-With':'XMLHttpRequest'}});

    setTimeout(waitForBoot, 100);          const data = await res.json();

  }          if(data.success && data.state==='pending'){

}            this.onLockRequested({

              request_id:data.request_id,

if (document.readyState === 'loading') {              requesting_user_id:data.requesting_user_id,

  document.addEventListener('DOMContentLoaded', waitForBoot);              requesting_user_name:data.requesting_user_name,

} else {              holder_deadline:data.holder_deadline,

  setTimeout(waitForBoot, 50);              created_at:Date.now()

}            });

          }

// Export for debugging        }catch(e){ /* silent */ }

window.PackLock = PackLock;      }
      scheduleRequestPoll(){ this.clearRequestPoll(); this._requestPollTimer = setInterval(()=>this.pollRequestState(), 7000); }
      clearRequestPoll(){ if(this._requestPollTimer){ clearInterval(this._requestPollTimer); this._requestPollTimer=null; } }

      // 7) RESPOND TO REQUEST
      async respondToOwnershipRequest(_requestId, granted){
        try { const body = new URLSearchParams({action:'request_decide',transfer_id:String(this.resourceId),decision:granted?'grant':'decline'}); const res = await fetch(this.api.gateway,{method:'POST',credentials:'include',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body}); const data = await res.json(); if(data.success && granted){ const next={has_lock:false,is_locked_by_other:true}; try{ super.updateLockStatus(next);}catch(_){ } this.updateLockStatus(next); this.stopHeartbeat(); this.onLockLost(); } return data; }catch(e){ return {success:false,error:'Network error'}; }
      }

      initBroadcastChannel(){
        try {
          this._bc = new BroadcastChannel(`pack_lock_${this.resourceId}`);
          this._bc.onmessage = (e)=>{
            if(!e || !e.data || !e.data.type) return;
            if(e.data.type === 'lock-state-changed' && e.data.fingerprint !== this.fingerprint){
              // Immediate refresh to reduce race window
              this.checkLockStatus();
            }
          };
        } catch(_) { this._bc = null; }
      }
      broadcastStateChange(kind){
        if(!this._bc) return;
        try { this._bc.postMessage({ type:'lock-state-changed', event:kind, ts:Date.now(), fingerprint:this.fingerprint }); } catch(_){}
      }

      /* -------------------------
       * Base event hooks (UI)
       * ------------------------- */
      onLockAcquired(_state) {
        this.enableControls(true);
        this.startHeartbeat(); // ensure HB is running
      }
      onLockLost() {
        this.enableControls(false);
      }
      onLockRequested(request) {
        // Holder sees modal / decision UI; non-holder just waits
        if(this.lockStatus?.has_lock){ this.showOwnershipRequestNotification(request); }
      }
      onReadOnlyMode(_state) {
        this.showReadOnlyMode();
      }

      /* -------------------------
       * Minimal boot/wiring
       * ------------------------- */
      init() {
        const boot = () => {
          this.loadStaffUsers();
          this.startPolling?.();
          this.checkForActiveRequest();
          document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
              if (typeof this.stopPolling === 'function') this.stopPolling();
            } else {
              if (typeof this.startPolling === 'function') this.startPolling();
            }
          });
          this.setupEventHandlers();
          this.checkLockStatus();
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
      }

      /* -------------------------
       * UI helpers (kept & refined)
       * ------------------------- */
      loadStaffUsers() {
        fetch(this.api.staff, { credentials:'include' })
          .then(r => r.json())
          .then(data => { if (data.success) global.staffUsers = data.users || {}; })
          .catch(() => { global.staffUsers = global.staffUsers || {}; });
      }

      setupEventHandlers() {
        document.addEventListener('click', (e) => {
          if (e.target && e.target.id === 'requestOwnershipBtn') {
            this.handleRequestOwnershipAction(e.target);
          }
        });
      }

      async handleRequestOwnershipAction(buttonEl) {
        if (buttonEl) buttonEl.disabled = true;
        const res = await this.requestOwnership('User requesting ownership');
        if (res && res.success) {
          try {
            localStorage.setItem(
              `ownership_request_${this.resourceId}`,
              JSON.stringify({ request_id: res.request_id, expires_at: res.expires_at, started_at: Date.now() })
            );
          } catch (_) {}
          this.transformButtonToCountdown(buttonEl, res.request_id, res.expires_at);
          return;
        }
        // Error UI
        if (buttonEl) {
          buttonEl.disabled = false;
          buttonEl.innerHTML = `<i class="fa fa-exclamation-triangle mr-2"></i>Error - Try Again`;
          buttonEl.style.background = '#dc3545';
          setTimeout(() => {
            buttonEl.innerHTML = `<i class="fa fa-hand-paper mr-2"></i>Request Ownership`;
            buttonEl.style.background = '';
          }, 2000);
        }
      }

      updateLockStatus(status) {
        const prev = this.lockStatus || {};
        this.lockStatus = { ...prev, ...status };
        const lockBadge = document.getElementById('lockStatusBadge');
        const sameTabConflict = status.same_user_other_tab || status.sameUserOtherTab || false;
        const newSig = `${+!!this.lockStatus.has_lock}-${+!!this.lockStatus.is_locked_by_other}-${+sameTabConflict}-${this.lockStatus.holder_name||''}`;
        if(newSig !== this._prevStateSig){
          this.broadcastStateChange('state');
          this._prevStateSig = newSig;
        }
        if (sameTabConflict) {
          if (lockBadge) {
            lockBadge.textContent = 'LOCKED (OTHER TAB)';
            lockBadge.style.background = 'rgba(255, 193, 7, 0.9)';
            lockBadge.style.border = '1px solid rgba(255, 193, 7, 1)';
          }
          this.showSameTabConflict();
          this.enableControls(false);
          return;
        }
        if (status.has_lock || status.hasLock) {
          if (lockBadge) {
            lockBadge.textContent = 'LOCKED BY YOU';
            lockBadge.style.background = 'rgba(40, 167, 69, 0.9)';
            lockBadge.style.border = '1px solid rgba(40, 167, 69, 1)';
          }
          this.startHeartbeat();
          this.enableControls(true);
        } else if (status.is_locked_by_other || status.isLockedByOther) {
          if (lockBadge) {
            lockBadge.textContent = `LOCKED BY ${status.holder_name || 'OTHER USER'}`;
            lockBadge.style.background = 'rgba(220, 53, 69, 0.9)';
            lockBadge.style.border = '1px solid rgba(220, 53, 69, 1)';
          }
          this.enableControls(false);
        } else {
          if (lockBadge) {
            lockBadge.textContent = 'ACQUIRING LOCK...';
            lockBadge.style.background = 'rgba(255, 193, 7, 0.9)';
            lockBadge.style.border = '1px solid rgba(255, 193, 7, 1)';
          }
          this.acquireLock();
        }
      }

      enableControls(enabled) {
        if (!enabled) {
          this.showReadOnlyMode();
          this.disableFormElements();
        } else {
          this.hideReadOnlyMode();
          this.enableFormElements();
        }
      }

      showReadOnlyMode() {
        // Keep blur effect + existing styles but remove embedded request button; footer handles interaction.
        this.hideReadOnlyMode();
        if(!document.getElementById('lockReadonlyStyles')){
          const css = `body.lock-readonly #packPageContainer :not(.lock-ro-focus):not(.lock-ro-focus *):not(#lockFooterBar):not(#lockFooterBar *){filter:blur(2px) saturate(.7) brightness(.92);transition:filter .3s ease;pointer-events:none;}\nbody.lock-readonly .lock-ro-focus{filter:none!important;position:relative;z-index:10;}\nbody.lock-readonly .lock-hidden{visibility:hidden!important;}\nbody.lock-readonly .card-header .btn, body.lock-readonly .breadcrumb .btn{opacity:.3;pointer-events:none;}\n#lockFooterBar{position:fixed;left:0;right:0;bottom:0;z-index:1045;background:linear-gradient(135deg,#212529 0%,#343a40 50%,#495057 100%);padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px;box-shadow:0 -6px 20px -4px rgba(0,0,0,.6);font-family:'Segoe UI',system-ui,sans-serif;border-top:2px solid #495057;}\n#lockFooterBar .lock-meta{display:flex;align-items:center;gap:12px;color:#f8f9fa;font-size:14px;font-weight:500;letter-spacing:.3px;}\n#lockFooterBar .lock-meta i{color:#ffc107;font-size:16px;}\n#lockFooterBar button{border:none;border-radius:25px;font-weight:600;letter-spacing:.4px;font-size:13px;padding:11px 22px;display:inline-flex;align-items:center;gap:8px;box-shadow:0 3px 8px -2px rgba(0,0,0,.5);transition:all .2s ease;cursor:pointer;}\n#lockFooterBar button:disabled{opacity:.6;cursor:default;transform:none;}\n#lockFooterBar button:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 4px 12px -2px rgba(0,0,0,.6);}\n#lockFooterBar .request-btn{background:linear-gradient(135deg,#ffc107,#ffb300);color:#212529;border:1px solid #e0a800;}\n#lockFooterBar .request-btn:hover:not(:disabled){background:linear-gradient(135deg,#ffcd39,#ffc107);}\n#lockFooterBar .countdown-btn{background:linear-gradient(135deg,#6c757d,#5a6268);color:#fff;cursor:default;border:1px solid #545b62;}\n#lockFooterBar .countdown-btn.flash{animation:flashWarn 1.2s ease-in-out infinite alternate;}\n@keyframes flashWarn{0%{background:linear-gradient(135deg,#6c757d,#5a6268);box-shadow:0 3px 8px -2px rgba(0,0,0,.5);}100%{background:linear-gradient(135deg,#dc3545,#c82333);box-shadow:0 3px 12px -1px rgba(220,53,69,.4);}}\nbody.lock-readonly.lock-same-tab #lockFooterBar{background:linear-gradient(135deg,#664d03,#856404,#a0781a);}\nbody.lock-readonly.lock-same-tab #lockFooterBar .request-btn{display:none;}\n`;
          const styleEl = document.createElement('style'); styleEl.id='lockReadonlyStyles'; styleEl.appendChild(document.createTextNode(css)); document.head.appendChild(styleEl);
        }
        // Determine main table for focus and hide interactive elements
        const tables = Array.from(document.querySelectorAll('#packPageContainer table'));
        let focusTable=null,maxRows=-1; 
        tables.forEach(t=>{ const rows=t.querySelectorAll('tbody tr').length; if(rows>maxRows){maxRows=rows;focusTable=t;} });
        if(focusTable) {
          focusTable.classList.add('lock-ro-focus');
          // Also preserve parent card structure
          const card = focusTable.closest('.card');
          if(card) card.classList.add('lock-ro-focus');
        }
        hideInteractiveElements();
        document.body.classList.add('lock-readonly');
        this.ensureFooterBar();
      }

      hideReadOnlyMode() {
        ['readOnlyStickyBar','readOnlyBanner','lockOverlay','lockTestOverlay','lockReadOnlyRibbon'].forEach(id=>{ const el=document.getElementById(id); if(el) el.remove(); });
        document.querySelectorAll('.lock-ro-focus').forEach(el=>el.classList.remove('lock-ro-focus'));
        restoreInteractiveElements();
        if(!this.lockStatus?.is_locked_by_other && !this.lockStatus?.same_user_other_tab){ const fb=document.getElementById('lockFooterBar'); if(fb) fb.remove(); }
        document.body.classList.remove('lock-readonly','lock-same-tab');
      }

      ensureFooterBar() {
        if(document.getElementById('lockFooterBar')) return;
        const bar = document.createElement('div');
        bar.id='lockFooterBar';
        bar.innerHTML = `
          <div class="lock-meta"><i class="fa fa-lock"></i><span id="lockFooterText">Viewing in read-only mode</span></div>
          <div class="lock-actions">
            <button id="lockRequestBtnFooter" class="request-btn"><i class="fa fa-hand-paper"></i> Request Edit Access</button>
          </div>`;
        document.body.appendChild(bar);
        const btn = bar.querySelector('#lockRequestBtnFooter');
        if(btn){ btn.addEventListener('click', ()=> this.handleRequestOwnershipFooter(btn)); }
        // Restore countdown if pending
        this.restoreFooterCountdown();
      }

      handleRequestOwnershipFooter(buttonEl){
        if(buttonEl) buttonEl.disabled=true;
        this.requestOwnership('User requesting ownership').then(res=>{
          if(res && res.success){
            try { localStorage.setItem(`ownership_request_${this.resourceId}`, JSON.stringify({ request_id: res.request_id, expires_at: res.expires_at, started_at: Date.now() })); } catch(_){}
            this.transformFooterButtonToCountdown(buttonEl, res.request_id, res.expires_at);
          } else {
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent='Error – Retry'; buttonEl.style.background='#dc3545'; setTimeout(()=>{ buttonEl.innerHTML='<i class="fa fa-hand-paper"></i> Request Edit Access'; buttonEl.style.background=''; },1800);} }
        });
      }

      restoreFooterCountdown(){
        let stored=null; try { stored = JSON.parse(localStorage.getItem(`ownership_request_${this.resourceId}`)||'null'); } catch(_){}
        if(!stored) return;
        const expiryTime = new Date(stored.expires_at).getTime();
        if(Date.now() >= expiryTime){ try{ localStorage.removeItem(`ownership_request_${this.resourceId}`);}catch(_){} return; }
        const btn = document.getElementById('lockRequestBtnFooter');
        if(btn) this.transformFooterButtonToCountdown(btn, stored.request_id, stored.expires_at);
      }

      transformFooterButtonToCountdown(button, requestId, expiresAt){
        if(!button) return;
        button.classList.remove('request-btn');
        button.classList.add('countdown-btn');
        button.disabled=true;
        button.style.minWidth='200px';
        const expiryTime = new Date(expiresAt).getTime();
        const update = ()=>{
          const now=Date.now();
          const remaining = Math.max(0, Math.ceil((expiryTime - now)/1000));
          if(remaining<=0){
            button.classList.remove('countdown-btn');
            button.classList.add('request-btn');
            button.disabled=false; button.innerHTML='<i class="fa fa-refresh"></i> Refresh'; button.onclick=()=>{ try{localStorage.removeItem(`ownership_request_${this.resourceId}`);}catch(_){} window.location.reload(); };
            this.grantOwnership(requestId); // triggers status refresh
            return;
          }
          const m = Math.floor(remaining/60); const s = remaining % 60; const t = `${m}:${String(s).padStart(2,'0')}`;
          if(remaining<=10) button.classList.add('flash'); else button.classList.remove('flash');
          button.innerHTML = `<i class="fa fa-clock"></i> Waiting Holder: ${t}`;
          this._footerCountdownTimer = setTimeout(update, 1000);
        };
        update();
      }

      // Override existing count-down transformer (legacy) to direct to footer if present
      transformButtonToCountdown(button, requestId, expiresAt){
        const footerBtn = document.getElementById('lockRequestBtnFooter');
        if(footerBtn && button && button.id !== 'lockRequestBtnFooter'){
          // Prefer footer version
          this.transformFooterButtonToCountdown(footerBtn, requestId, expiresAt);
          // Hide original to avoid duplicate
          button.style.display='none';
          return;
        }
        // Fallback to original behavior if footer not available
        try {
          if (typeof super.transformButtonToCountdown === 'function') return super.transformButtonToCountdown();
        } catch (_) {}
        if(!button) return;
        button.classList.remove('request-btn');
        button.classList.add('countdown-btn');
        button.disabled=true;
        button.style.minWidth='120px';
        const expiryTime = new Date(expiresAt).getTime();
        const update = ()=>{
          const now=Date.now();
          const remaining = Math.max(0, Math.ceil((expiryTime - now)/1000));
          if(remaining<=0){
            button.classList.remove('countdown-btn');
            button.classList.add('request-btn');
            button.disabled=false; button.innerHTML='<i class="fa fa-refresh"></i> Refresh'; button.onclick=()=>{ try{localStorage.removeItem(`ownership_request_${this.resourceId}`);}catch(_){} window.location.reload(); };
            this.grantOwnership(requestId); // triggers status refresh
            return;
          }
          const m = Math.floor(remaining/60); const s = remaining % 60; const t = `${m}:${String(s).padStart(2,'0')}`;
          if(remaining<=10) button.classList.add('flash'); else button.classList.remove('flash');
          button.innerHTML = `<i class="fa fa-clock"></i> ${t}`;
          this._countdownTimer = setTimeout(update, 1000);
        };
        update();
      }

      showSameTabConflict(){
        this.showReadOnlyMode();
        document.body.classList.add('lock-same-tab');
        const footerText = document.getElementById('lockFooterText'); if(footerText) footerText.textContent='Open in another tab (same user)';
        const reqBtn = document.getElementById('lockRequestBtnFooter'); if(reqBtn) { reqBtn.remove(); }
        // Add takeover button
        const bar = document.getElementById('lockFooterBar');
        if(bar && !document.getElementById('takeoverFooterBtn')){
          const takeBtn = document.createElement('button');
          takeBtn.id='takeoverFooterBtn';
          takeBtn.className='request-btn';
          takeBtn.style.background='#343a40'; takeBtn.style.color='#fff';
          takeBtn.innerHTML='<i class="fa fa-bolt"></i> Take Over';
          takeBtn.onclick=()=>this.takeoverLock(takeBtn);
          bar.appendChild(takeBtn);
        }
      }

      // Patch updateLockStatus tail to ensure footer reflects state
      onLockLost(){
        this.enableControls(false);
        const footerText = document.getElementById('lockFooterText'); if(footerText && this.lockStatus?.is_locked_by_other){ footerText.textContent='Another user is editing'; }
      }

      /* -------------------------
       * Ownership modal + utils (HARDENED VERSION)
       * ------------------------- */
      showOwnershipRequestNotification(request) {
        if (!request || typeof request !== 'object') return;
        // If already visible, update countdown only
        const existing = document.getElementById('ownershipRequestModal');
        const deadline = request.holder_deadline ? new Date(request.holder_deadline).getTime() : null;
        if (existing) {
          if(deadline){ existing.dataset.deadline = String(deadline); }
          return; }
        const backdrop = document.createElement('div');
        backdrop.id = 'ownershipRequestModal';
        if(deadline) backdrop.dataset.deadline = String(deadline);
        backdrop.style.cssText = `position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 10550; display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',system-ui,sans-serif; backdrop-filter:blur(3px);`;
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'orTitle');
        
        const modal = document.createElement('div');
        modal.style.cssText = `background:#fff; border-radius:16px; width:520px; max-width:94%; box-shadow:0 15px 50px -8px rgba(0,0,0,.5); overflow:hidden; display:flex; flex-direction:column; border:2px solid #e9ecef;`;
        modal.innerHTML = `
          <div style="background:linear-gradient(135deg,#dc3545 0%,#c82333 50%,#b02a37 100%);color:#fff;padding:20px 24px;display:flex;align-items:center;gap:14px;">
            <i class="fa fa-exclamation-triangle" style="font-size:1.6rem;color:#fff5b7;"></i>
            <div style="flex:1;">
              <div id="orTitle" style="font-weight:700;letter-spacing:.3px;font-size:1.1rem;">Lock Ownership Request</div>
              <div style="font-size:.8rem;opacity:.9;margin-top:2px;">Another user wants to edit this transfer</div>
            </div>
            <div id="orCountdown" style="font-size:.8rem;background:rgba(255,255,255,.25);padding:6px 10px;border-radius:14px;min-width:60px;text-align:center;font-weight:600;border:1px solid rgba(255,255,255,.3);">--:--</div>
          </div>
          <div style="padding:24px 26px;">
            <p style="margin:0 0 16px;font-size:.95rem;line-height:1.4;color:#495057;"><strong>Decision Required:</strong> If you take no action, the lock will automatically transfer when the countdown reaches zero.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:12px 0 22px;">
              <div style="flex:1;background:linear-gradient(135deg,#f8f9fa,#e9ecef);border:2px solid #dee2e6;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                <i class="fa fa-user-circle" style="font-size:2rem;color:#6f42c1"></i>
                <div style="line-height:1.2;">
                  <div style="font-weight:600;font-size:.9rem;color:#212529;">${request.requesting_user_name || ('User '+request.requesting_user_id)}</div>
                  <div style="font-size:.75rem;color:#6c757d;margin-top:1px;">Requesting immediate edit access</div>
                </div>
              </div>
            </div>
            <div style="display:flex;justify-content:center;gap:14px;">
              <button id="orDecline" style="background:#6c757d;color:#fff;border:none;border-radius:25px;padding:10px 20px;font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">Decline Request</button>
              <button id="orGrant" style="background:linear-gradient(135deg,#28a745,#20c997);color:#fff;border:none;border-radius:25px;padding:10px 24px;font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px -2px rgba(40,167,69,.4);" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 12px -2px rgba(40,167,69,.5)'" onmouseout="this.style.transform='';this.style.boxShadow='0 2px 8px -2px rgba(40,167,69,.4)'"><i class="fa fa-share mr-1"></i>Accept & Transfer</button>
            </div>
          </div>`;
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);
        
        const acceptBtn = modal.querySelector('#orGrant');
        const declineBtn = modal.querySelector('#orDecline');
        acceptBtn.onclick = ()=>{ this.respondToOwnershipRequest(request.request_id,true).then(()=>{ this.closeOwnershipModal();}); };
        declineBtn.onclick = ()=>{ this.respondToOwnershipRequest(request.request_id,false).then(()=>{ this.closeOwnershipModal();}); };
        // Prevent backdrop clicks - require explicit choice
        backdrop.onclick = (e) => { if(e.target === backdrop) e.preventDefault(); };
        // Apply focus trap
        trapFocus(modal);
        
        const tick = ()=>{
          if(!document.getElementById('ownershipRequestModal')) return;
          if(!deadline) return;
          const now = Date.now();
            let rem = Math.max(0, Math.ceil((deadline - now)/1000));
            const m = Math.floor(rem/60); const s = rem%60;
            const el = modal.querySelector('#orCountdown'); if(el) el.textContent = `${m}:${String(s).padStart(2,'0')}`;
            if(rem<=0){ this.closeOwnershipModal(); return; }
          requestAnimationFrame(()=>setTimeout(tick,1000));
        };
        tick();
      }
      closeOwnershipModal(){ const m=document.getElementById('ownershipRequestModal'); if(m) m.remove(); }

      /* -------------------------
       * Legacy debug helpers (exposed)
       * ------------------------- */
      showLockDiagnostic() {
        const s = this.lockStatus || {};
        const diag = `
          PackLockSystem Diagnostic
          -------------------------
          Resource ID      : ${s.resourceId || 'n/a'}
          User ID          : ${s.userId || 'n/a'}
          Has Lock         : ${s.has_lock ? 'YES' : 'NO'}
          Is Locked        : ${s.is_locked ? 'YES' : 'NO'}
          Locked By        : ${s.holder_name || 'n/a'}
          Expires At       : ${s.expires_at ? new Date(s.expires_at).toString() : 'n/a'}
          Lock Acquired At : ${s.lock_acquired_at ? new Date(s.lock_acquired_at).toString() : 'n/a'}
          Same Tab Open    : ${s.same_user_other_tab ? 'YES' : 'NO'}
          Debug Info       : ${s.debug_info || 'n/a'}
        `;
        console.log(diag);
        alert(diag);
      }

      /* -------------------------
       * Base class overrides
       * ------------------------- */
      // No-op (legacy)
      startPolling() {}
      stopPolling() {}
    }

    // Provide boot helper that was referenced (prevent ReferenceError)
    function bootWhenReady(){
      try {
        const mainEl = document.querySelector('[data-page="transfer-pack"]');
        if(!mainEl) return; // page not relevant
        const txId = mainEl.getAttribute('data-txid');
        
        // Get userId from DISPATCH_BOOT first, then fall back to globals
        let userId = null;
        if (window.DISPATCH_BOOT && window.DISPATCH_BOOT.user_id) {
          userId = parseInt(window.DISPATCH_BOOT.user_id, 10);
        } else {
          userId = (window.CURRENT_USER_ID || window.currentUserId || window.userId || null);
          if (userId) userId = parseInt(userId, 10);
        }
        
        if(!txId || !userId || userId <= 0) {
          console.warn('[PackLock] Missing required data:', { txId, userId, DISPATCH_BOOT: window.DISPATCH_BOOT });
          return;
        }
        
        if(!global.__packLockInstance){
          console.log('[PackLock] Initializing lock system:', { txId, userId });
          global.__packLockInstance = new PackLockSystem(txId, userId);
          global.PackLockSystemInstance = global.__packLockInstance; // For modular system
          global.__packLockInstance.init();
        }
      } catch(e){ console.warn('[PackLock] boot error', e); }
    }

    bootWhenReady();

    // Export
    global.PackLockSystem = PackLockSystem;
  }

  // Delay definition until base available
  if (typeof global.UniversalLockSystem === 'function') {
    define(global.UniversalLockSystem);
  } else {
    const wait = setInterval(() => {
      if (typeof global.UniversalLockSystem === 'function') {
        clearInterval(wait);
        define(global.UniversalLockSystem);
      }
    }, 60);
  }
})(window);
