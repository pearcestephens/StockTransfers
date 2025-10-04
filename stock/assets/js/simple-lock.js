/* simple-lock.js v2 - SSE + BroadcastChannel real-time lock system */
class SimpleLock {
  constructor(opts) {
    this.endpoint = opts.endpoint || '/modules/transfers/stock/api/simple_lock.php';
    this.sseEndpoint = opts.sseEndpoint || '/modules/transfers/stock/api/lock_events.php';
    this.resource = opts.resourceKey;
    this.ownerId  = String(opts.ownerId);
    this.ttl      = opts.ttl ?? 90;
    this.onChange = opts.onChange || (()=>{});
  this.verifyEveryMs = opts.verifyEveryMs || 45000; // periodic verification when owning
  this.spectatorPollMs = opts.spectatorPollMs || 20000; // polling while spectator
  this._verifier = null;
  this._spectatorPoller = null;
  this._acquiring = false;
  this._lastRefreshAt = 0;
  this._offline = false;
    
    if(!opts.endpoint){
      console.warn('[SimpleLock] endpoint not provided, defaulting to', this.endpoint);
    }
    
    const tabKey = 'sl_tab:'+this.resource;
    this.tabId = sessionStorage.getItem(tabKey) || (crypto.randomUUID?crypto.randomUUID():Math.random().toString(36).slice(2));
    sessionStorage.setItem(tabKey,this.tabId);
    
    this.token = null;
    this.alive = false;
    this.eventSource = null;
    
    // BroadcastChannel for instant same-browser communication
    if(typeof BroadcastChannel !== 'undefined'){
      this.channel = new BroadcastChannel('lock:'+this.resource);
      this.channel.onmessage = (ev) => {
        const msg = ev.data;
        console.log('[SimpleLock] Broadcast received:', msg);
        if(msg.type === 'lock_acquired' && msg.tabId !== this.tabId){
          // Another tab in same browser got the lock
          if(this.alive){
            console.warn('[SimpleLock] Lock stolen by another tab (instant broadcast)');
            this.alive = false;
            this.stopSSE();
            this.onChange({state:'lost', reason:'broadcast', info:msg});
          }
        }
        if(msg.type === 'lock_released'){
          console.log('[SimpleLock] Lock released (broadcast)');
        }
      };
    } else {
      console.warn('[SimpleLock] BroadcastChannel not supported');
      this.channel = null;
    }

    // Network offline / online resilience
    window.addEventListener('offline', ()=>{
      this._offline = true;
      if(this.alive){
        // We may still logically own it, but mark uncertain without releasing
        this.onChange({state:'alive', info:{offline:true}});
      } else {
        this.onChange({state:'blocked', info:{offline:true}});
      }
    });
    window.addEventListener('online', ()=>{
      this._offline = false;
      // Force a refresh soon after coming back
      setTimeout(()=>this.refresh(), 500);
    });
  }
  
  async call(action, payload){
    const body = Object.assign({action}, payload);
    if(!this.endpoint){ throw new Error('SimpleLock endpoint undefined'); }
    const url = this.endpoint + '?action=' + encodeURIComponent(action);
    let res;
    try {
      res = await fetch(url,{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(body),
        credentials:'same-origin',
        keepalive: action==='release'
      });
    } catch(networkErr){
      throw new Error('Network error calling lock endpoint: '+ networkErr.message);
    }
    let data;
    try { data = await res.json(); }
    catch(parseErr){ throw new Error('Invalid JSON from lock endpoint ('+url+'): '+parseErr.message); }
    return data;
  }
  
  async status(){
    try {
      const r = await this.call('status',{resource_key:this.resource});
      if(r.ok && r.locked){
        const sameOwner = r.owner_id === this.ownerId;
        const sameTab = r.tab_id === this.tabId;
        return {locked:true, mine: sameOwner && sameTab, sameOwner, sameTab, owner:r.owner_id, tab:r.tab_id, expiresIn:r.expires_in};
      }
      return {locked:false};
    } catch(err){
      console.error('[SimpleLock] status check error', err);
      return {locked:false, error:err};
    }
  }
  
  startSSE(){
    if(this.eventSource) {
      console.warn('[SimpleLock] SSE already connected, skipping');
      return; // Prevent duplicate connections
    }
    
    if(!this.alive || !this.token){
      console.warn('[SimpleLock] Cannot start SSE without active lock');
      return;
    }
    
    const url = `${this.sseEndpoint}?resource=${encodeURIComponent(this.resource)}&owner_id=${encodeURIComponent(this.ownerId)}&tab_id=${encodeURIComponent(this.tabId)}`;
    console.log('[SimpleLock] Starting SSE connection:', url);
    
    this.eventSource = new EventSource(url);
    this._sseReconnectAttempts = 0;
    this._sseConnectedAt = Date.now();
    
    this.eventSource.addEventListener('connected', (e) => {
      const data = JSON.parse(e.data);
      console.log('[SimpleLock] SSE connected:', data);
      this._sseReconnectAttempts = 0; // Reset on successful connect
    });
    
    this.eventSource.addEventListener('lock_stolen', (e) => {
      const data = JSON.parse(e.data);
      console.warn('[SimpleLock] SSE: Lock stolen!', data);
      if(this.alive){
        this.alive = false;
        this.token = null;
        this.stopSSE(); // Close SSE immediately when we lose lock
        this.onChange({state:'lost', reason:'sse', info:data});
      }
    });
    
    this.eventSource.addEventListener('lock_released', (e) => {
      const data = JSON.parse(e.data);
      console.log('[SimpleLock] SSE: Lock released', data);
    });
    
    this.eventSource.addEventListener('timeout', (e) => {
      const data = JSON.parse(e.data);
      console.log('[SimpleLock] SSE: Max duration reached, will reconnect', data);
      this.stopSSE();
      // Auto-reconnect if we still have the lock
      if(this.alive && this.token){
        setTimeout(() => this.startSSE(), 1000);
      }
    });
    
    this.eventSource.addEventListener('heartbeat', (e) => {
      // SSE connection alive - silent
    });
    
    this.eventSource.addEventListener('error', (e) => {
      const data = e.data ? JSON.parse(e.data) : {};
      console.error('[SimpleLock] SSE error event:', data);
      this.stopSSE();
    });
    
    this.eventSource.onerror = (err) => {
      console.warn('[SimpleLock] SSE onerror triggered');
      
      // Prevent infinite reconnect loops
      this._sseReconnectAttempts = (this._sseReconnectAttempts || 0) + 1;
      
      if(this._sseReconnectAttempts > 5){
        console.error('[SimpleLock] SSE reconnect failed 5 times, giving up');
        this.stopSSE();
        return;
      }
      
      if(this.eventSource && this.eventSource.readyState === EventSource.CLOSED){
        console.error('[SimpleLock] SSE connection closed permanently');
        this.stopSSE();
      }
      
      // Only reconnect if connection died prematurely and we still have lock
      const connectionDuration = Date.now() - (this._sseConnectedAt || 0);
      if(connectionDuration < 290000 && this.alive && this.token){ // Less than 290s (before 5min timeout)
        console.log('[SimpleLock] SSE died early, will reconnect in 2s');
        setTimeout(() => {
          if(this.alive && this.token && !this.eventSource){
            this.startSSE();
          }
        }, 2000);
      }
    };
  }
  
  stopSSE(){
    if(this.eventSource){
      console.log('[SimpleLock] Closing SSE connection');
      try {
        this.eventSource.close();
      } catch(e) {
        console.warn('[SimpleLock] Error closing SSE:', e);
      }
      this.eventSource = null;
      this._sseReconnectAttempts = 0;
    }
  }
  
  async acquire(){
    if(this._acquiring){
      console.log('[SimpleLock] acquire suppressed (already acquiring)');
      return false;
    }
    this._acquiring = true;
    let r;
    try {
      r = await this.call('acquire',{resource_key:this.resource,owner_id:this.ownerId,tab_id:this.tabId,ttl:this.ttl});
    } catch(err){
      console.error('[SimpleLock] acquire error', err);
      this.onChange({state:'error', error: err});
      this._acquiring = false;
      return false;
    }
    
    if(r.ok && r.acquired){
      this.token = r.token; 
      this.alive = true; 
      console.log('[SimpleLock] Lock acquired successfully');
      
      // Start SSE for real-time notifications
      this.startSSE();
      
      // Broadcast to same-browser tabs instantly
      if(this.channel){
        this.channel.postMessage({type:'lock_acquired', tabId:this.tabId, ownerId:this.ownerId});
      }
      
      this.onChange({state:'acquired', mine:true, expiresIn:r.expires_in});
      this._acquiring = false;
      return true;
    }
    
    // Blocked
    console.log('[SimpleLock] Blocked by:', r);
    this.onChange({state:'blocked', mine:false, reason: r.same_owner? (r.same_tab? 'Already active in this tab' : 'Another tab you own has control') : 'Held by another user', info:r});
    this._acquiring = false;
    return false;
  }
  
  async steal(){
    const res = await this.call('steal',{resource_key:this.resource,owner_id:this.ownerId,tab_id:this.tabId,ttl:this.ttl});
    if(res.ok && res.acquired){
      this.token = res.token; 
      this.alive = true;
      
      // Start SSE
      this.startSSE();
      
      // Broadcast steal instantly
      if(this.channel){
        this.channel.postMessage({type:'lock_acquired', tabId:this.tabId, ownerId:this.ownerId, stolen:true});
      }
      
      this.onChange({state:'acquired', mine:true, stolen:!!res.stolen, expiresIn:res.expires_in});
      return true;
    }
    return false;
  }
  
  async release(){
    if(!this.token) return;
    
    // Stop SSE first
    this.stopSSE();
    
    try { 
      await this.call('release',{resource_key:this.resource,owner_id:this.ownerId,tab_id:this.tabId,token:this.token}); 
    } catch(_){ }
    
    this.alive = false;
    this.token = null;
    
    // Broadcast release instantly
    if(this.channel){
      this.channel.postMessage({type:'lock_released', tabId:this.tabId});
    }
    
    this.onChange({state:'released'});
  }
  
  async start(){ 
    // Check status first
    console.log('[SimpleLock] Checking lock status...');
    const status = await this.status();
    console.log('[SimpleLock] Status result:', status);
    
    if(status.locked && !status.mine){
      // Already locked by someone else
      console.log('[SimpleLock] Lock is taken - entering blocked state');
      this.onChange({state:'blocked', mine:false, info:{same_owner:status.sameOwner, same_tab:status.sameTab, owner_id:status.owner, tab_id:status.tab, expires_in:status.expiresIn}});
    } else {
      // Try to acquire
      console.log('[SimpleLock] Lock appears free - attempting acquire');
      await this.acquire();
    }
    
    // Listen for visibility changes
    document.addEventListener('visibilitychange', async () => {
      if (!document.hidden && this.alive) {
        console.log('[SimpleLock] Tab visible - verifying lock');
        const s = await this.status();
        if(s.locked && !s.mine){
          console.warn('[SimpleLock] Lost lock while tab was hidden');
          this.alive = false;
          this.stopSSE();
          this.onChange({state:'lost', reason:'visibility_check'});
        }
      }
    });
    
    // Listen for focus
    window.addEventListener('focus', async () => {
      if (this.alive) {
        console.log('[SimpleLock] Window focused - verifying lock');
        const s = await this.status();
        if(s.locked && !s.mine){
          console.warn('[SimpleLock] Lost lock detected on focus');
          this.alive = false;
          this.stopSSE();
          this.onChange({state:'lost', reason:'focus_check'});
        }
      }
    });
    
    window.addEventListener('beforeunload',()=>this.release()); 
    window.addEventListener('pagehide',()=>this.release()); 

    // Start verification & spectator polling loops
    this.startLoops();
  }

  startLoops(){
    if(this._verifier) clearInterval(this._verifier);
    if(this._spectatorPoller) clearInterval(this._spectatorPoller);
    this._verifier = setInterval(()=>{
      if(this.alive && !this._offline){
        // Light-weight sanity check without hammering server
        this.refresh();
      }
    }, this.verifyEveryMs);

    this._spectatorPoller = setInterval(()=>{
      if(!this.alive && !this._offline){
        this.spectatorPoll();
      }
    }, this.spectatorPollMs);
  }

  async spectatorPoll(){
    // Poll for status; if free attempt acquire, else emit blocked to keep UI fresh
    try {
      const s = await this.status();
      if(!s.locked){
        // Try opportunistic acquire (non-aggressive)
        await this.acquire();
      } else {
        if(!s.mine){
          this.onChange({state:'blocked', mine:false, info:{poll:true, same_owner:s.sameOwner, same_tab:s.sameTab, owner_id:s.owner}});
        }
      }
    } catch(e){
      // Ignore transient errors
    }
  }

  // Manual status refresh to integrate with legacy or external UI triggers.
  // Invokes status() and emits appropriate synthetic onChange events reflecting current reality.
  async refresh(){
    const now = Date.now();
    if(now - this._lastRefreshAt < 2000){
      return; // rate-limit manual triggers
    }
    this._lastRefreshAt = now;
    try {
      const s = await this.status();
      if(s.locked){
        if(s.mine){
          // We hold it (alive/acquired)
            if(!this.alive){
              this.alive = true;
              this.onChange({state:'acquired', mine:true, info:{refreshed:true, expiresIn:s.expiresIn}});
            } else {
              this.onChange({state:'alive', mine:true, info:{refreshed:true, expiresIn:s.expiresIn}});
            }
        } else {
          // Someone else (or another tab) holds it
          if(this.alive){
            // We thought we had it but do not anymore
            this.alive=false; this.stopSSE();
            this.onChange({state:'lost', reason:'refresh_status', info:{refreshed:true, same_owner:s.sameOwner, same_tab:s.sameTab}});
          } else {
            this.onChange({state:'blocked', mine:false, info:{refreshed:true, same_owner:s.sameOwner, same_tab:s.sameTab, owner_id:s.owner, tab_id:s.tab, expires_in:s.expiresIn}});
          }
        }
      } else {
        // Not locked at all
        if(this.alive){
          this.alive=false; this.stopSSE();
          this.onChange({state:'lost', reason:'refresh_status_not_locked', info:{refreshed:true}});
        } else {
          this.onChange({state:'released', info:{refreshed:true}});
        }
      }
    } catch(err){
      console.error('[SimpleLock] refresh error', err);
      this.onChange({state:'error', error:err, op:'refresh'});
    }
  }
}

window.SimpleLock = SimpleLock;
