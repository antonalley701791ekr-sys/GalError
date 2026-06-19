(function(){
'use strict';
function markCover(img){
 if(!img)return;
 var apply=function(){
  var w=img.naturalWidth||0;
  var h=img.naturalHeight||0;
  if(!w||!h)return;
  var wrap=img.closest('.game-detail-cover');
  if(!wrap)return;
  var landscape=w>=h;
  wrap.classList.toggle('game-detail-cover-landscape',landscape);
  wrap.classList.toggle('game-detail-cover-portrait',!landscape);
 };
 if(img.complete)apply();else img.addEventListener('load',apply,{once:true});
}
function initAdaptiveCovers(){
 document.querySelectorAll('.js-adaptive-cover').forEach(markCover);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initAdaptiveCovers);else initAdaptiveCovers();
})();
