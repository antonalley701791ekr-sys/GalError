(function(){
'use strict';
function closeViewer(modal,img){
 modal.style.display='none';
 document.body.classList.remove('image-viewer-open');
 img.src='';
 img.alt='';
}
function initImageViewer(){
 var modal=document.getElementById('global-image-viewer');
 if(!modal)return;
 var img=modal.querySelector('.global-image-viewer-modal-image');
 if(!img)return;
 document.addEventListener('click',function(e){
  var trigger=e.target.closest('.js-image-viewer-trigger');
  if(!trigger)return;
  var src=trigger.getAttribute('data-viewer-src');
  if(!src)return;
  e.preventDefault();
  img.src=src;
  img.alt=trigger.getAttribute('data-viewer-alt')||'';
  modal.style.display='block';
  document.body.classList.add('image-viewer-open');
 });
 modal.addEventListener('click',function(){closeViewer(modal,img);});
 document.addEventListener('keydown',function(e){
  if(e.key==='Escape'&&modal.style.display==='block')closeViewer(modal,img);
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initImageViewer);else initImageViewer();
})();
