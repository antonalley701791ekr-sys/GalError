(function(){
'use strict';
function getModalState(modal){
 return modal.__viewerState || (modal.__viewerState={scale:1,baseScale:1});
}
function applyTransform(img,state){
 img.style.transform='translate(-50%, -50%) scale(' + state.scale + ')';
 img.style.transformOrigin='center center';
 img.style.cursor='pointer';
}
function resetViewer(modal,img){
 var state=getModalState(modal);
 state.scale=1;
 state.baseScale=1;
 img.src='';
 img.alt='';
 img.style.transform='translate(-50%, -50%) scale(1)';
 img.style.cursor='zoom-in';
}
function closeViewer(modal,img){
 modal.classList.remove('is-open');
 modal.style.display='none';
 document.body.classList.remove('image-viewer-open');
 resetViewer(modal,img);
}
function openViewer(modal,img,src,alt){
 var state=getModalState(modal);
 img.src=src;
 img.alt=alt||'';
 state.scale=1;
 state.baseScale=1;
 modal.style.display='block';
 modal.classList.add('is-open');
 document.body.classList.add('image-viewer-open');
 applyTransform(img,state);
 try {
  var rect = img.getBoundingClientRect();
  if (rect.width > 0 && rect.height > 0) {
   var fitScale = Math.min((window.innerWidth - 40) / rect.width, (window.innerHeight - 40) / rect.height, 1);
   if (fitScale > 0 && fitScale < 1) {
    state.scale = fitScale;
    applyTransform(img,state);
   }
  }
 } catch (e) {}
}
function zoomTo(modal,img,newScale){
 var state=getModalState(modal);
 state.scale=Math.max(0.5,Math.min(6,newScale));
 applyTransform(img,state);
}
function initImageViewer(){
 var modal=document.getElementById('global-image-viewer');
 if(!modal)return;
 var img=modal.querySelector('.global-image-viewer-modal-image');
 if(!img)return;
 var closeBtn=modal.querySelector('.global-image-viewer-close');
 var panel=modal.querySelector('.global-image-viewer-modal-panel');
 document.addEventListener('click',function(e){
  var trigger=e.target.closest('.js-image-viewer-trigger, .pm-inline-images img, img.js-image-viewer-trigger, a.js-image-viewer-link, a[href*="image_proxy.php"]');
  if(!trigger || modal.contains(trigger))return;
  var src='';
  var alt='';
  if(trigger.tagName==='IMG'){
    var parentLink=trigger.closest('a');
    if(parentLink){
      src=parentLink.getAttribute('data-viewer-src')||parentLink.getAttribute('href')||trigger.src||'';
      alt=parentLink.getAttribute('data-viewer-alt')||trigger.alt||'';
    } else {
      src=trigger.currentSrc||trigger.src||'';
      alt=trigger.alt||'';
    }
  } else {
    src=trigger.getAttribute('data-viewer-src')||trigger.getAttribute('href');
    alt=trigger.getAttribute('data-viewer-alt')||'';
  }
  if(!src)return;
  e.preventDefault();
  e.stopPropagation();
  if(src.indexOf('image_proxy.php')>-1 && trigger.tagName==='A'){
    var imgEl=trigger.querySelector('img');
    if(imgEl && imgEl.src){ src = imgEl.src; }
  }
  openViewer(modal,img,src,alt||img.alt||'');
 },true);
 if(closeBtn){
  closeBtn.addEventListener('click',function(){closeViewer(modal,img);});
 }
 if(panel){
  panel.addEventListener('click',function(e){e.stopPropagation();});
 }
 img.addEventListener('click',function(){
  closeViewer(modal,img);
 });
 img.addEventListener('wheel',function(e){
  if(modal.style.display!=='block')return;
  e.preventDefault();
  e.stopPropagation();
  var delta=e.deltaY>0?-0.25:0.25;
  var state=getModalState(modal);
  zoomTo(modal,img,state.scale+delta);
 },{passive:false});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initImageViewer);else initImageViewer();
})();
