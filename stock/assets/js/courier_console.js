
(function(){ "use strict";
var $=function(s){return document.querySelector(s)}, $$=function(s){return Array.from(document.querySelectorAll(s))};
function enc(o){var u=new URLSearchParams();Object.keys(o||{}).forEach(function(k){var v=o[k];u.append(k,(typeof v==="string")?v:JSON.stringify(v));});return u.toString();}
function post(p){return fetch("/modules/transfers/stock/api/courier_api.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:enc(p)}).then(function(r){return r.json();});}
function n(v){var x=parseFloat(v);return isNaN(x)?0:x;}
var el=$("#courier-console"); if(!el) return;
var state={transferId:n(el.dataset.transferId||"0"),mode:"auto",parcels:[],rates:[],chosen:null,labels:[],prefs:{satchelPref:true,boxAdder:300,volDiv:5000,prefCarrier:"auto"}};

function domWeight(){var t=0; $$("#packTable tr,[data-pack-row]").forEach(function(tr){var g=n(tr.getAttribute("data-weight-g")||tr.getAttribute("data-weight")||"0");var q=n(tr.getAttribute("data-qty")||"1"); if(!g){var c=tr.querySelector("[data-weight-g]"); if(c) g=n(c.getAttribute("data-weight-g"));} if(g>0) t+=g*(q||1);}); var te=document.querySelector("[data-total-weight-g]"); var tot=te?n(te.getAttribute("data-total-weight-g")):0; if(t<=0 && tot>0) t=tot; return Math.round(Math.max(0,t));}

function renderParcels(){var tb=$("#cx-parcel-rows"); tb.innerHTML=""; state.parcels.forEach(function(p,i){var tr=document.createElement("tr"); tr.innerHTML="<td>"+(i+1)+"</td><td>"+(p.type||"box").toUpperCase()+"</td><td>"+
'<input class="form-control form-control-sm dim-input d-inline-block mr-1" data-k="length_mm" data-i="'+i+'" value="'+(p.length_mm||0)+'">'+
'<input class="form-control form-control-sm dim-input d-inline-block mr-1" data-k="width_mm" data-i="'+i+'" value="'+(p.width_mm||0)+'">'+
'<input class="form-control form-control-sm dim-input d-inline-block" data-k="height_mm" data-i="'+i+'" value="'+(p.height_mm||0)+'"></td>'+
'<td><input class="form-control form-control-sm kg-input" data-k="weight_g" data-i="'+i+'" value="'+(p.weight_g||0)+'"></td>'+
"<td><button class='btn btn-light btn-sm parcel-assign' data-i='"+i+"'>Assign</button></td>"+
"<td><button class='btn btn-outline-danger btn-sm parcel-del' data-i='"+i+"'>&times;</button></td>"; tb.appendChild(tr); });
  $$("#cx-parcels input").forEach(function(inp){inp.addEventListener("change",function(){var i=n(inp.getAttribute("data-i")),k=inp.getAttribute("data-k"),v=n(inp.value); if(state.parcels[i]){state.parcels[i][k]=v; queueRates();}});});
  $$(".parcel-del").forEach(function(btn){btn.addEventListener("click",function(){var i=n(btn.getAttribute("data-i")); state.parcels.splice(i,1); renderParcels(); queueRates();});});
}
function renderRates(){var tb=$("#cx-rate-rows"); tb.innerHTML=""; if(!state.rates.length){tb.innerHTML='<tr><td colspan="6" class="text-muted">No rates yet.</td></tr>'; return;} state.rates.forEach(function(r,i){
  var cont=(r.meta&&r.meta.containers&&r.meta.containers.list&&r.meta.containers.list[0])||null;
  var cc=cont?cont.container_code:''; var cname=cont?cont.container_name:''; var ccost=cont?cont.cost:0;
  var tr=document.createElement("tr"); var ch=(state.chosen===r); if(ch) tr.classList.add("font-weight-bold");
  tr.innerHTML='<td><input type="radio" name="cx-rate" data-i="'+i+'" '+(ch?"checked":"")+'></td><td>'+(r.carrier||r.provider||"")+'</td><td>'+(r.service||"")+'</td><td>'+(cc||"")+'</td><td>$'+(r.cost||0).toFixed(2)+'</td><td>'+(r.note||"")+'</td>';
  tb.appendChild(tr);
}); $$('input[name="cx-rate"]').forEach(function(radio){radio.addEventListener("change",function(){state.chosen=state.rates[n(radio.getAttribute("data-i"))]; updateButtons();});});}
function updateButtons(){var ready=!!state.chosen && state.mode==="auto"; $("#cx-print-only").disabled=!ready; $("#cx-print-pack").disabled=!ready; $("#cx-reprint").disabled=!(state.labels&&state.labels.length); $("#cx-cancel").disabled=!(state.labels&&state.labels.length); $("#cx-status").textContent=ready?"Ready":"Waiting"; var c=(state.chosen&&state.chosen.meta&&state.chosen.meta.containers&&state.chosen.meta.containers.list[0])||null; var cc=c?(' • '+c.container_code+' • $'+(c.cost||0).toFixed(2)):""; $("#cx-suggest").textContent = state.chosen?((state.chosen.carrier||state.chosen.provider)+" "+(state.chosen.service||"")+" • $"+(state.chosen.cost||0).toFixed(2)+cc):"No suggestion yet";}
var rt=null; function queueRates(){clearTimeout(rt); rt=setTimeout(fetchRates,350);}
function autoPack(){var t=domWeight(); state.parcels=[]; if(t<=2000){state.parcels.push({type:"satchel",length_mm:0,width_mm:0,height_mm:0,weight_g:t});} else {state.parcels.push({type:"satchel",length_mm:0,width_mm:0,height_mm:0,weight_g:2000}); state.parcels.push({type:"box",length_mm:300,width_mm:200,height_mm:200,weight_g:Math.max(10,t-2000)+(state.prefs.boxAdder||300)});} renderParcels();}
function fetchRates(){rt=null; if(state.mode!=="auto"){updateButtons(); return;} $("#cx-status").textContent="Quoting…";
  post({action:"rates",transfer_id:state.transferId,parcels:state.parcels,dom_weight_g:domWeight(),prefer_satchel:true,sig_required:$("#cx-sig")&&$("#cx-sig").checked?true:false,saturday:$("#cx-sat")&&$("#cx-sat").checked?true:false,pref_carrier:(state.prefs.prefCarrier||"auto")}).then(function(j){
    if(!j||!j.ok) throw 0; state.rates=j.data.rates||[]; state.chosen=j.data.chosen||null; renderRates(); updateButtons();
  }).catch(function(){ $("#cx-status").textContent="Rate error"; });
}
function doBuy(fin){ if(!state.chosen) return; $("#cx-status").textContent="Buying…"; $("#cx-print-only").disabled=$("#cx-print-pack").disabled=true;
  post({action:"buy_label",transfer_id:state.transferId,rate:state.chosen,parcels:state.parcels,finalize:fin?1:0}).then(function(j){ if(!j||!j.ok) throw 0; state.labels=(j.data&&j.data.labels)||[]; var L=state.labels[0]; if(L&&L.label_url) window.open(L.label_url,"_blank"); $("#cx-status").textContent=fin?"Packed & Printed":"Printed"; updateButtons(); }).catch(function(){ $("#cx-status").textContent="Buy error"; }).finally(function(){ updateButtons(); });
}
$("#cx-auto-pack").addEventListener("click",function(){autoPack(); queueRates();});
$("#cx-add-satchel").addEventListener("click",function(){state.parcels.push({type:"satchel",length_mm:0,width_mm:0,height_mm:0,weight_g:500}); renderParcels(); queueRates();});
$("#cx-add-box").addEventListener("click",function(){state.parcels.push({type:"box",length_mm:300,width_mm:200,height_mm:200,weight_g:1000+(state.prefs.boxAdder||300)}); renderParcels(); queueRates();});
$("#cx-sig").addEventListener("change",queueRates); $("#cx-sat").addEventListener("change",queueRates);
$$("#courier-console [data-mode]").forEach(function(b){ b.addEventListener("click",function(){ $$("[data-mode]").forEach(function(x){x.classList.remove("active")}); b.classList.add("active"); state.mode=b.getAttribute("data-mode"); $("#cx-status").textContent=state.mode==="auto"?"Waiting":state.mode.toUpperCase(); updateButtons(); }); });
$("#cx-print-only").addEventListener("click",function(){ if(state.mode!=="auto"){ manualDispatch(); } else { doBuy(false);} });
$("#cx-print-pack").addEventListener("click",function(){ if(state.mode!=="auto"){ manualDispatch(); } else { doBuy(true);} });
$("#cx-reprint").addEventListener("click",function(){ var L=(state.labels||[])[0]; if(L&&L.label_url) window.open(L.label_url,"_blank"); });
$("#cx-cancel").addEventListener("click",function(){ if(!confirm("Cancel latest label?")) return; $("#cx-status").textContent="Cancelling…"; post({action:"cancel_label",transfer_id:state.transferId}).then(function(j){ if(j&&j.ok){ state.labels=[]; $("#cx-status").textContent="Cancelled"; } updateButtons(); }).catch(function(){ $("#cx-status").textContent="Cancel error"; }); });

$("#cx-settings").addEventListener("click",function(){ $("#cxSettingsModal").classList.add("show"); $("#cxSettingsModal").style.display="block"; });
$$("#cxSettingsModal [data-dismiss='modal'], #cxSettingsModal .close").forEach(function(x){ x.addEventListener("click", function(){ $("#cxSettingsModal").classList.remove("show"); $("#cxSettingsModal").style.display="none"; }); });
$("#saveSettings").addEventListener("click", function(){ state.prefs={ satchelPref: $("#prefSatchel").checked, forceSig: $("#forceSignature").checked, allowSat: $("#allowSaturday").checked, volDiv:n($("#volDiv").value||"5000"), boxAdder:n($("#boxAdder").value||"300"), prefCarrier: ($("#prefCarrier").value||"auto") }; $("#cxSettingsModal").classList.remove("show"); $("#cxSettingsModal").style.display="none"; queueRates(); });

$("#cx-edit-address").addEventListener("click", function(){ $("#cxAddressModal").classList.add("show"); $("#cxAddressModal").style.display="block"; });
$$("#cxAddressModal [data-dismiss='modal'],#cxAddressModal .close").forEach(function(x){ x.addEventListener("click", function(){ $("#cxAddressModal").classList.remove("show"); $("#cxAddressModal").style.display="none"; }); });
$("#addrValidate").addEventListener("click", function(){ var a={ name:$("#addr_name").value, company:$("#addr_company").value, line1:$("#addr_line1").value, line2:$("#addr_line2").value, suburb:$("#addr_suburb").value, city:$("#addr_city").value, postcode:$("#addr_postcode").value, email:$("#addr_email").value, phone:$("#addr_phone").value }; $("#addrStatus").textContent="Validating…"; post({action:"address_validate",transfer_id:state.transferId,address:a}).then(function(j){ $("#addrStatus").textContent=(j&&j.ok&&j.data&&j.data.status)||"OK"; }).catch(function(){ $("#addrStatus").textContent="Address check failed"; }); });
$("#addrSave").addEventListener("click", function(){ var a={ name:$("#addr_name").value, company:$("#addr_company").value, line1:$("#addr_line1").value, line2:$("#addr_line2").value, suburb:$("#addr_suburb").value, city:$("#addr_city").value, postcode:$("#addr_postcode").value, email:$("#addr_email").value, phone:$("#addr_phone").value }; post({action:"address_save",transfer_id:state.transferId,address:a}).then(function(){ $("#cxAddressModal").classList.remove("show"); $("#cxAddressModal").style.display="none"; }); });

function manualDispatch(){ var c=prompt("Carrier (e.g. GSS, NZ_POST, INTERNAL):","GSS")||"MANUAL"; var t=prompt("Tracking / Ref:","")||""; post({action:"manual_dispatch",transfer_id:state.transferId,mode:state.mode,carrier:c,tracking:t}).then(function(j){ if(j&&j.ok) alert("Saved"); }); }
function init(){ autoPack(); fetchRates(); }
init();
})();