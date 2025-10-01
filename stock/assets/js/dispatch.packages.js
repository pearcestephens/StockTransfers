(function(){
  'use strict';
  var D = window.Dispatch, U = D.util, S = D.state, PRESETS = D.PRESETS;

  function loadPresetOptions(){
    var sel = U.$('#preset'); if (!sel) return;
    var list = PRESETS[S.container] || [];
    sel.innerHTML = '';
    list.forEach(function(p){ var o=document.createElement('option'); o.value=p.id; o.textContent=p.name; sel.appendChild(o); });
  }

  function renderMeters(){
    var cap = S.container==='satchel' ? 15 : 25, wrap = U.$('#meters'); if (!wrap) return;
    wrap.innerHTML = '';
    S.packages.forEach(function(p,i){
      var pct = Math.min(100, Math.round((Number(p.kg||0)/cap)*100));
      var row = document.createElement('div');
      row.innerHTML = '<div class="small">Parcel '+(i+1)+' · '+(Number(p.kg||0).toFixed(1))+'kg/'+cap.toFixed(1)+'kg</div><div class="meter"><i style="width:'+pct+'%"></i></div>';
      wrap.appendChild(row);
    });
  }

  function updateShipmentStats(){
    var capPer = S.container==='satchel'?15:25, totalWeight=0, totalItems=0, missing=0;
    (S.packages||[]).forEach(function(p){
      var kg = Number(p.kg||0), items=Number(p.items||0);
      if (!kg || kg<=0) missing += 1;
      if (Number.isFinite(kg)) totalWeight += kg;
      if (Number.isFinite(items)) totalItems += items;
    });
    var count = S.packages.length;
    S.metrics = { weight: totalWeight, items: totalItems, missing: missing, count: count, capPer: capPer };

    var set = function(id, val){ var el=U.$('#'+id); if (el) el.textContent = val; };
    set('sw-total', totalWeight.toFixed(3));
    set('sw-items', totalItems);
    set('sw-missing', missing);
    set('sw-boxes', count);
    set('sw-cap', count ? capPer.toFixed(1) : '—');

    var sw = U.$('#sw-summary-weight'); if (sw) sw.textContent = totalWeight.toFixed(3)+'kg';
    var sp = U.$('#sw-summary-packages'); if (sp) sp.textContent = count ? (count+'pkg'+(count===1?'':'s')) : '0 pkgs';
  }

  function renderPackages(){
    var body = U.$('#pkgBody'); if (!body) return;
    body.innerHTML = '';
    S.packages.forEach(function(p,i){
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>'+(i+1)+'</td>'+
        '<td>'+U.escapeHtml(p.name||('Parcel '+(i+1)))+'</td>'+
        '<td><input class="pn" type="number" step="1" min="1" value="'+(p.w||'')+'" data-i="'+i+'" data-k="w">×'+
              '<input class="pn" type="number" step="1" min="1" value="'+(p.l||'')+'" data-i="'+i+'" data-k="l">×'+
              '<input class="pn" type="number" step="1" min="1" value="'+(p.h||'')+'" data-i="'+i+'" data-k="h"></td>'+
        '<td><input class="pw" type="number" step="0.01" min="0" value="'+(p.kg||'')+'" data-i="'+i+'" data-k="kg">kg</td>'+
        '<td><input class="pi" type="number" step="1" min="0" value="'+(p.items||0)+'" data-i="'+i+'" data-k="items"></td>'+
        '<td class="num"><button class="btn icon" data-del="'+i+'" title="Remove">×</button></td>';
      body.appendChild(tr);
    });
    var sb = U.$('#slipBox'); if (sb) sb.textContent = S.packages.length ? 1 : 0;
    var st = U.$('#slipTotal'); if (st) st.value = S.packages.length || 1;

    renderMeters(); updateShipmentStats();
    if (window.Dispatch.rates && window.Dispatch.rates.renderSummary) window.Dispatch.rates.renderSummary();
  }

  function wirePackageInputs(){
    var body = U.$('#pkgBody'); if (!body) return;
    body.addEventListener('input', function(e){
      var t=e.target, i=+t.dataset.i, k=t.dataset.k; if (Number.isNaN(i) || !k) return;
      var v = (t.type==='number') ? parseFloat(t.value||'0') : t.value;
      S.packages[i][k] = (k==='name') ? String(v) : (Number.isFinite(v) ? v : (S.packages[i][k]||0));
      renderMeters(); updateShipmentStats();
      window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-edit',{force:false});
    });
    body.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-del]'); if (!btn) return;
      var idx = +btn.dataset.del, removed=S.packages[idx];
      S.packages.splice(idx,1); renderPackages();
      window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-remove',{force:false});
      if (window.PackToast) window.PackToast.info('Parcel removed: '+(removed && (removed.name||('#'+(idx+1)))));
    });
  }

  function setContainer(type){
    if (type === S.container) return;
    S.container = type;
    var b1=U.$('#btnSatchel'), b2=U.$('#btnBox');
    if (b1) b1.setAttribute('aria-pressed', type==='satchel' ? 'true' : 'false');
    if (b2) b2.setAttribute('aria-pressed', type==='box' ? 'true' : 'false');
    loadPresetOptions(); renderPackages();
    window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('container-change', {force:true});
    if (window.PackToast) window.PackToast.info('Container set: '+type);
  }

  function addParcelFromPreset(){
    var id = (U.$('#preset')||{}).value; var src = (PRESETS[S.container]||[]).find(function(p){return p.id===id;}) || (PRESETS[S.container]||[])[0];
    S.packages.push(Object.assign({ id: S.packages.length+1 }, src)); renderPackages();
    window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-add',{force:false});
    if (window.PackToast) window.PackToast.success('Parcel added');
  }
  function copyLastParcel(){
    if (!S.packages.length) return; var last=S.packages[S.packages.length-1];
    S.packages.push(Object.assign({ id:S.packages.length+1 }, last)); renderPackages();
    window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-copy',{force:false});
    if (window.PackToast) window.PackToast.success('Parcel copied');
  }
  function clearParcels(){
    S.packages = []; renderPackages();
    window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-clear',{force:false});
    if (window.PackToast) window.PackToast.warn('Parcels cleared');
  }
  function autoAssignParcels(){
    var per=S.packages.length?Math.ceil(120/S.packages.length):0;
    S.packages = S.packages.map(function(p,i){ return Object.assign({}, p, { items: per, kg: Math.max(p.kg, 1.2 + 0.4*i) }); });
    renderPackages(); window.Dispatch.rates && window.Dispatch.rates.scheduleRatesRefresh('pkg-auto',{force:false});
    if (window.PackToast) window.PackToast.success('Auto-assign complete');
  }

  // Boot helpers
  function primeMetricsFromBoot(){
    var m=BOOT && BOOT.metrics; if (!m) return;
    var w=U.toNumber(m.total_weight_kg||m.total_weight||0,0), it=Math.max(0,Math.trunc(U.toNumber(m.total_items||0,0)));
    if (Number.isFinite(w)) S.metrics.weight=w; if (Number.isFinite(it)) S.metrics.items=it;
  }
  function applyBootAutoplan(){
    var plan=BOOT && BOOT.autoplan;
    if (!plan || plan.shouldHydrate===false || !Array.isArray(plan.packages) || !plan.packages.length) { primeMetricsFromBoot(); return false; }
    var container=plan.container==='box'?'box':'satchel'; S.container=container;
    var list=PRESETS[container]||[], fb=list[0]||PRESETS.satchel[1], pkgs=[];
    plan.packages.forEach(function(pkg,i){
      var preset=list.find(function(p){return p.id===pkg.preset_id;})||fb, dims=pkg.dimensions||{};
      var kg=Math.max(U.toNumber(pkg.weight_kg||pkg.goods_weight_kg, preset.kg), preset.kg);
      var items=Math.max(0,Math.trunc(U.toNumber(pkg.items||0,0)));
      pkgs.push({ id: i+1, name: preset.name, w: U.toNumber(dims.w||pkg.width_cm||pkg.w, preset.w),
                  l: U.toNumber(dims.l||pkg.length_cm||pkg.l, preset.l),
                  h: U.toNumber(dims.h||pkg.height_cm||pkg.h, preset.h), kg: kg, items: items });
    });
    if (!pkgs.length){ primeMetricsFromBoot(); return false; }
    S.packages = pkgs; S.selection=null;
    S.metrics.capPer = (typeof plan.cap_kg==='number' && plan.cap_kg>0) ? plan.cap_kg : (container==='box'?25:15);
    var tw=U.toNumber(plan.total_weight_kg||plan.total_weight||S.metrics.weight||0,0);
    var ti=Math.max(0,Math.trunc(U.toNumber(plan.total_items||S.metrics.items||0,0)));
    if (Number.isFinite(tw)) S.metrics.weight=tw; if (Number.isFinite(ti)) S.metrics.items=ti; S.metrics.count=pkgs.length;
    return true;
  }

  // Expose
  D.packages = {
    loadPresetOptions: loadPresetOptions,
    renderMeters: renderMeters,
    updateShipmentStats: updateShipmentStats,
    renderPackages: renderPackages,
    wirePackageInputs: wirePackageInputs,
    setContainer: setContainer,
    addParcelFromPreset: addParcelFromPreset,
    copyLastParcel: copyLastParcel,
    clearParcels: clearParcels,
    autoAssignParcels: autoAssignParcels,
    primeMetricsFromBoot: primeMetricsFromBoot,
    applyBootAutoplan: applyBootAutoplan
  };

})();
