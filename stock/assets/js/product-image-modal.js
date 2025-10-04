// product-image-modal.js
// Extracted product image preview modal from pack.view.php (2025-10-02)
(function(){
  'use strict';
  if(window.ProductImageModalLoaded) return; window.ProductImageModalLoaded=true;

  window.showProductImageModal = function(imageSrc, productName){
    const existingModal = document.getElementById('productImageModal');
    if (existingModal) existingModal.remove();
    const modal = document.createElement('div'); modal.id='productImageModal'; modal.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:10000;display:flex;align-items:center;justify-content:center;cursor:pointer;animation:fadeIn 0.3s ease;';
    const content=document.createElement('div'); content.style.cssText='max-width:90vw;max-height:90vh;display:flex;flex-direction:column;align-items:center;cursor:default;';
    const img=document.createElement('img'); img.src=imageSrc; img.style.cssText='max-width:100%;max-height:80vh;object-fit:contain;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);animation:zoomIn 0.3s ease;';
    const title=document.createElement('div'); title.textContent=productName; title.style.cssText='color:white;margin-top:15px;font-size:18px;font-weight:600;text-align:center;text-shadow:0 2px 4px rgba(0,0,0,0.8);';
    const closeHint=document.createElement('div'); closeHint.textContent='Click anywhere to close'; closeHint.style.cssText='color:rgba(255,255,255,0.7);margin-top:8px;font-size:14px;text-align:center;';
    const style=document.createElement('style'); style.textContent='@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}@keyframes zoomIn{from{transform:scale(.8);opacity:0;}to{transform:scale(1);opacity:1;}}@keyframes fadeOut{from{opacity:1;}to{opacity:0;}}'; document.head.appendChild(style);
    content.appendChild(img); content.appendChild(title); content.appendChild(closeHint); modal.appendChild(content); document.body.appendChild(modal);
    modal.onclick=function(e){ if(e.target===modal){ modal.style.animation='fadeOut 0.3s ease'; setTimeout(()=>modal.remove(),300);} };
    const handleEscape=function(e){ if(e.key==='Escape'){ modal.style.animation='fadeOut 0.3s ease'; setTimeout(()=>modal.remove(),300); document.removeEventListener('keydown', handleEscape);} }; document.addEventListener('keydown', handleEscape); content.onclick=function(e){ e.stopPropagation(); };
  };
})();
