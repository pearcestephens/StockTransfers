// Dual-mode Wizard — default Pickup/Internal; Courier on demand with full functionality
(function(){
  'use strict';
  const $  = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  const TID = parseInt($('#pw-transfer')?.value || new URLSearchParams(location.search).get('transfer') || '0', 10) || 0;

  /* utils */
  function csrf(){ if(window.CIS_CSRF) return window.CIS_CSRF;
    const m=document.cookie.match(/(?:^|;)\s*XSRF-TOKEN=([^;]+)/); return m?decodeURIComponent(m[1]):''; }
  async function jget(u){
    const r=await fetch(u,{credentials:'same-origin',headers:{Accept:'application/json','X-CSRF':csrf()}});
    let j={}; try{ j=await r.json(); }catch{}; if(!r.ok) throw new Error(`GET ${u} → ${r.status}`); return j.data||j; }
  async function jpost(u,b){
    const r=await fetch(u,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF':csrf()},body:JSON.stringify(b)});
    let j={}; try{ j=await r.json(); }catch{}; if(!r.ok) throw new Error(`POST ${u} → ${r.status}`); return j.data||j; }
  function toast(t,tone){
    const fb = $('#pw-feedback'); if(!fb) return; fb.textContent = t || '';
    fb.className = 'small ' + (tone==='error'?'text-danger':tone==='success'?'text-success':'text-muted');
  }

  /* elements */
  const root = $('#print-wizard');
  const btnModeCourier  = $('#pw-mode-courier');
  const btnModePickup   = $('#pw-mode-pickup');

  const carrierSel = $('#pw-carrier'), serviceSel = $('#pw-service'), printerInp = $('#pw-printer');
  const fromEl = $('#pw-from'), toEl = $('#pw-to');

  const itemsEl=$('#pw-items'), totalEl=$('#pw-total'), missingEl=$('#pw-missing'), capEl=$('#pw-cap'), boxesEl=$('#pw-boxes');
  const seedBtn = $('#pw-seed'), overrideBtn=$('#pw-override'), overrideBlk=$('#pw-override-block'), validateBtn=$('#pw-validate');

  const ratesWrap = $('#pw-rates'), packagesTbody = $('#pw-packages tbody');
  const btnPreview = $('#pw-preview'), btnPrint = $('#pw-print'), btnCreate = $('#pw-create'), btnReady = $('#pw-ready');
  const btnPickupPreview = $('#pw-pickup-preview'), btnPickupPrint = $('#pw-pickup-print');
  const pickupBoxes = $('#pw-pickup-boxes');

  /* mode switching — default pickup */
  function setMode(mode){
    if(mode==='courier'){
      root.classList.remove('is-mode-pickup'); root.classList.add('is-mode-courier');
      btnModePickup.classList.remove('is-active'); btnModeCourier.classList.add('is-active');
      // on entering courier, load services & quote (once)
      loadServices().then(quoteAndAutoPick).catch(()=>{});
    }else{
      root.classList.remove('is-mode-courier'); root.classList.add('is-mode-pickup');
      btnModeCourier.classList.remove('is-active'); btnModePickup.classList.add('is-active');
      // hide courier bits; nothing to call
    }
    localStorage.setItem('pw.mode', mode);
  }
  btnModeCourier?.addEventListener('click', ()=> setMode('courier'));
  btnModePickup ?.addEventListener('click', ()=> setMode('pickup'));
  setMode(localStorage.getItem('pw.mode') || 'pickup'); // DEFAULT to pickup

  /* summary + suggest (both modes) */
  async function loadWeights(){
    if(!TID) return;
    try{
      const j = await jget(`/modules/transfers/stock/api/weight_suggest.php?transfer=${TID}`);
      itemsEl.textContent   = j.items_count ?? 0;
      totalEl.textContent   = (j.total_weight_kg ?? 0).toFixed(3);
      missingEl.textContent = j.missing_weights ?? 0;
      capEl.textContent     = j.plan?.cap_kg ?? '—';
      boxesEl.textContent   = j.plan?.boxes ?? 0;
      seedBtn.onclick = ()=>{
        // pickup: set count; courier: seed table
        if(root.classList.contains('is-mode-pickup')){
          if (pickupBoxes) pickupBoxes.value = Math.max(1, j.plan?.boxes || 1);
          toast('Suggested box count applied.');
          return;
        }
        packagesTbody.innerHTML='';
        (j.packages||[]).forEach((p,i)=> packagesTbody.insertAdjacentHTML('beforeend', rowTpl(i+1,p)));
        toast('Suggested boxes added.');
        quoteAndAutoPick();
      };
    }catch(e){ /* silent */ }
  }

  /* address/services (courier only) — with safe From/To fallback */
  async function loadServices(){
    if(root.classList.contains('is-mode-pickup')) return;
    const carrier=(carrierSel?.value||'nz_post').toLowerCase();
    try{
      const j=await jget(`/modules/transfers/stock/api/services_live.php?transfer=${TID}&carrier=${carrier}`);
      // From/To from API if available, else fallback to hidden inputs (pack.php sets these)
      const fallbackFrom = $('#sourceID')?.value || '';
      const fallbackTo   = $('#destinationID')?.value || '';
      if (j.from || fallbackFrom) fromEl.textContent = j.from ? [j.from.addr1,j.from.city,j.from.postcode].filter(Boolean).join(', ') : fallbackFrom;
      if (j.to   || fallbackTo)   toEl.textContent   = j.to   ? [j.to.addr1,  j.to.city,  j.to.postcode].filter(Boolean).join(', ') : fallbackTo;

      // Services
      serviceSel.innerHTML = `<option value="">Service (live/manual)</option>`;
      const list = carrier==='gss' ? (j.services?.gss||[]) : (j.services?.nz_post||[]);
      list.forEach(s=>{
        const code=(s.code||s.service_code||s.id||'').toString();
        const name=(s.name||s.display||code).toString();
        if(code) serviceSel.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
      });

      // Printers (GSS) optional — never required
      if (Array.isArray(j.printers) && j.printers.length) {
        const names = j.printers.map(p => (p.name||p).toString()).filter(Boolean);
        printerInp.setAttribute('list','pw-printers');
        let dl = $('#pw-printers'); if (!dl) { dl = document.createElement('datalist'); dl.id = 'pw-printers'; printerInp.after(dl); }
        dl.innerHTML = names.map(n=>`<option value="${n}">`).join('');
      }
      if((j.warnings||[]).length) toast(j.warnings[0],'info'); else toast('');
    }catch(e){
      // graceful manual fallback
      serviceSel.innerHTML = `<option value="">Service (manual)</option>`;
      toast('Live services unavailable (manual OK).','info');
      // still fill From/To from hidden inputs if present
      const fallbackFrom = $('#sourceID')?.value || '';
      const fallbackTo   = $('#destinationID')?.value || '';
      if (fallbackFrom) fromEl.textContent = fallbackFrom;
      if (fallbackTo)   toEl.textContent   = fallbackTo;
    }
  }

  /* packages (courier only) */
  function rowTpl(i, box){ const l=box.l_cm||40,w=box.w_cm||30,h=box.h_cm||20,kg=box.weight_kg||2.0;
    return `<tr class="box-row" data-l="${l}" data-w="${w}" data-h="${h}" data-kg="${kg}">
      <td><input class="form-control form-control-sm" value="${box.name||('Box '+i)}"></td>
      <td><input class="form-control form-control-sm box-l" type="number" min="1" step="0.1" value="${l}"></td>
      <td><input class="form-control form-control-sm box-w" type="number" min="1" step="0.1" value="${w}"></td>
      <td><input class="form-control form-control-sm box-h" type="number" min="1" step="0.1" value="${h}"></td>
      <td><input class="form-control form-control-sm box-kg" type="number" min="0.01" step="0.01" value="${kg}"></td>
      <td><button class="btn-icon-xs" title="Remove" aria-label="Remove" onclick="this.closest('tr').remove()"><i class="fa fa-times"></i></button></td>
    </tr>`; }
  $('#pw-add') ?.addEventListener('click',()=> packagesTbody.insertAdjacentHTML('beforeend', rowTpl(packagesTbody.children.length+1, {})));
  $('#pw-copy')?.addEventListener('click',()=>{
    const last = $('#pw-packages tbody tr:last-child');
    const box = last? {
      l_cm: parseFloat(last.querySelector('.box-l').value||'40'),
      w_cm: parseFloat(last.querySelector('.box-w').value||'30'),
      h_cm: parseFloat(last.querySelector('.box-h').value||'20'),
      weight_kg: parseFloat(last.querySelector('.box-kg').value||'2.0')
    } : {};
    packagesTbody.insertAdjacentHTML('beforeend', rowTpl(packagesTbody.children.length+1, box));
    quoteAndAutoPick();
  });
  $('#pw-clear')?.addEventListener('click',()=> { packagesTbody.innerHTML=''; quoteAndAutoPick(); });

  function packagesFromTable(){
    const rows = $$('#pw-packages tbody tr');
    const pkgs = rows.map((tr,i)=>({
      name: tr.querySelector('input')?.value || `Box ${i+1}`,
      l_cm: parseFloat(tr.querySelector('.box-l')?.value || '40'),
      w_cm: parseFloat(tr.querySelector('.box-w')?.value || '30'),
      h_cm: parseFloat(tr.querySelector('.box-h')?.value || '20'),
      weight_kg: parseFloat(tr.querySelector('.box-kg')?.value || '2.0'),
      qty: 1, ref: `T${TID}-${i+1}`
    }));
    return pkgs.length ? pkgs : [{name:'Box 1',l_cm:40,w_cm:30,h_cm:20,weight_kg:2.0,qty:1,ref:`T${TID}-1`}];
  }

  /* rates + auto-pick (courier only) */
  async function quoteAndAutoPick(){
    if(root.classList.contains('is-mode-pickup')) return;
    const carrier=(carrierSel?.value||'nz_post').toLowerCase();
    try{
      const j = await jpost('/modules/transfers/stock/api/rates.php', { transfer_id:TID, carrier, packages: packagesFromTable() });
      const quotes = j.quotes || j.results || [];
      ratesWrap.classList.remove('d-none'); ratesWrap.innerHTML='';
      if(!quotes.length){ ratesWrap.innerHTML='<div class="text-muted">No live quotes (rules/manual)</div>'; return; }
      quotes.forEach(q=>{
        const code=(q.service_code||q.code||'').toString();
        const name=(q.service_name||q.name||code).toString();
        const price=(+q.total_price||+q.price||0).toFixed(2);
        const eta=(q.eta||'');
        const d=document.createElement('div'); d.className='pw-rate';
        d.innerHTML=`<div class="pw-rate__name">${name}</div><div class="pw-rate__meta"><span>${code}</span><span>$${price}</span>${eta?`<span>${eta}</span>`:''}</div>`;
        d.addEventListener('click',()=>{ serviceSel.value=code; toast(`Selected ${name} — $${price}`,'success'); });
        ratesWrap.appendChild(d);
      });
      let best=quotes[0];
      for(const q of quotes){ if((+q.total_price||+q.price||0) < (+best.total_price||+best.price||0)) best=q; }
      const code=(best.service_code||best.code||'').toString(); const name=(best.service_name||best.name||code).toString();
      if(code){
        if(!$$('#pw-service option').some(o=>o.value===code)) serviceSel.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
        serviceSel.value=code; toast(`Selected ${name} — $${(+best.total_price||+best.price||0).toFixed(2)}`,'success');
      }
    }catch(e){ /* silent */ }
  }

  /* preview/print (pickup) */
  function openPreview(){
    const n = Math.max(1, parseInt(pickupBoxes?.value || '1',10) || 1);
    const url = `/modules/transfers/stock/print/box_slip.php?transfer=${TID}&preview=1&n=${n}`;
    window.open(url,'_blank');
  }
  btnPickupPreview?.addEventListener('click', openPreview);
  btnPickupPrint ?.addEventListener('click', openPreview);

  /* preview/print/create labels (courier) */
  btnPreview?.addEventListener('click', ()=> window.open(`/modules/transfers/stock/print/box_slip.php?transfer=${TID}&preview=1&n=${Math.max(1,$$('#pw-packages tbody_
