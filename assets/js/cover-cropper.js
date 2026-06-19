(function(){
'use strict';
function CoverCropper(o){
 o=o||{};this.uploadUrl=o.uploadUrl||'?action=upload_cropped';this.proxyUrl=o.proxyUrl||'/image_proxy?url=';this.croppedPathInput=o.croppedPathInput||'cropped_cover_path';this.cropStatusId=o.cropStatusId||'crop_status';this.aspectRatio=o.aspectRatio||5/7;this.outputWidth=o.outputWidth||400;this.outputHeight=o.outputHeight||null;this.outputQuality=o.outputQuality||.9;this.title=o.title||'裁剪图片';this.confirmText=o.confirmText||'确认裁剪';this.previewShape=o.previewShape||'rect';this.mimeType=o.mimeType||'image/jpeg';this.onCropped=o.onCropped||null;this.maxFileSize=o.maxFileSize||4*1024*1024;this.maxFileSizeLabel=o.maxFileSizeLabel||'4MB';this.cropper=null;this.currentBlobUrl=null;this.sourceWidth=0;this.sourceHeight=0;this._createModal();
}
CoverCropper.prototype._createModal=function(){
 var self=this;this.modalOverlay=document.createElement('div');this.modalOverlay.className='cropper-modal-overlay';this.modalOverlay.style.display='none';
 this.modal=document.createElement('div');this.modal.className='image-cropper-dialog';if(this.previewShape==='circle')this.modal.classList.add('cropper-modal-circle');
 var header=document.createElement('div');header.className='cropper-modal-header';
 var titleWrap=document.createElement('div');titleWrap.className='cropper-modal-title-wrap';
 this.titleEl=document.createElement('span');this.titleEl.className='cropper-modal-title';this.titleEl.textContent=this.title;
 this.metaEl=document.createElement('span');this.metaEl.className='cropper-modal-meta';titleWrap.appendChild(this.titleEl);titleWrap.appendChild(this.metaEl);
 var closeBtn=document.createElement('button');closeBtn.className='cropper-modal-close';closeBtn.innerHTML='&times;';closeBtn.type='button';closeBtn.onclick=function(){self.cancel();};
 header.appendChild(titleWrap);header.appendChild(closeBtn);
 this.content=document.createElement('div');this.content.className='cropper-modal-content';this.stage=document.createElement('div');this.stage.className='cropper-stage';
 this.cropImage=document.createElement('img');this.cropImage.className='cropper-target-image';this.cropImage.style.display='block';this.stage.appendChild(this.cropImage);
 this.loadingDiv=document.createElement('div');this.loadingDiv.className='cropper-loading';this.loadingDiv.textContent='图片加载中...';this.loadingDiv.style.display='none';this.stage.appendChild(this.loadingDiv);
 this.hintEl=document.createElement('div');this.hintEl.className='cropper-upload-hint';this.hintEl.textContent='支持 JPG/PNG 格式，单张图片大小不超过 ' + this.maxFileSizeLabel + '。';this.stage.appendChild(this.hintEl);
 var actions=document.createElement('div');actions.className='cropper-actions';var groups=document.createElement('div');groups.className='cropper-tool-groups';
 groups.appendChild(this._group('缩放',[['缩小',function(){self.zoom(-.1);}],['放大',function(){self.zoom(.1);}]]));
 groups.appendChild(this._group('旋转',[['左旋',function(){if(self.cropper)self.cropper.rotate(-90);}],['右旋',function(){if(self.cropper)self.cropper.rotate(90);}]]));
 groups.appendChild(this._group('操作',[['重置',function(){if(self.cropper){self.cropper.reset();self._setInitialCropBox();}}]]));
 var footer=document.createElement('div');footer.className='cropper-modal-footer';
 var cancelBtn=document.createElement('button');cancelBtn.type='button';cancelBtn.className='btn btn-secondary';cancelBtn.textContent='取消';cancelBtn.onclick=function(){self.cancel();};
 this.confirmBtn=document.createElement('button');this.confirmBtn.type='button';this.confirmBtn.className='btn';this.confirmBtn.textContent=this.confirmText;this.confirmBtn.onclick=function(){self.confirm();};
 footer.appendChild(cancelBtn);footer.appendChild(this.confirmBtn);actions.appendChild(groups);actions.appendChild(footer);
 this.content.appendChild(this.stage);this.content.appendChild(actions);this.modal.appendChild(header);this.modal.appendChild(this.content);this.modalOverlay.appendChild(this.modal);document.body.appendChild(this.modalOverlay);
 document.addEventListener('keydown',function(e){if(e.key==='Escape'&&self.modalOverlay.classList.contains('is-open'))self.cancel();});
};
CoverCropper.prototype._group=function(label,items){var group=document.createElement('div');group.className='cropper-tool-group';var labelEl=document.createElement('span');labelEl.className='cropper-tool-label';labelEl.textContent=label;group.appendChild(labelEl);items.forEach(function(item){var btn=document.createElement('button');btn.type='button';btn.className='btn btn-secondary cropper-tool-btn';btn.textContent=item[0];btn.onclick=item[1];group.appendChild(btn);});return group;};
CoverCropper.prototype.setAspectRatio=function(r,w,h){this.aspectRatio=r;if(w)this.outputWidth=w;this.outputHeight=h||null;if(this.cropper){this.cropper.setAspectRatio(r);this._setInitialCropBox();}};
CoverCropper.prototype.zoom=function(v){if(this.cropper)this.cropper.zoom(v);};
CoverCropper.prototype.open=function(imageSource,sourceType){
 this._destroyCropper();if(this.currentBlobUrl){URL.revokeObjectURL(this.currentBlobUrl);this.currentBlobUrl=null;}
 this.modalOverlay.classList.add('is-open');this.modalOverlay.style.display='';this.cropImage.style.display='none';this.loadingDiv.style.display='flex';this.loadingDiv.textContent='图片加载中...';this.loadingDiv.style.color='';this.confirmBtn.disabled=true;document.body.style.overflow='hidden';
 if(sourceType==='file'&&imageSource instanceof File){this.currentBlobUrl=URL.createObjectURL(imageSource);this._loadImage(this.currentBlobUrl);}else{this._loadImage(this.proxyUrl+encodeURIComponent(imageSource));}
};
CoverCropper.prototype._loadImage=function(src){var self=this,img=new Image();img.crossOrigin='anonymous';img.onload=function(){self.sourceWidth=img.naturalWidth||img.width;self.sourceHeight=img.naturalHeight||img.height;self._applyAdaptiveLayout();self.cropImage.src=src;self.cropImage.style.display='block';self.loadingDiv.style.display='none';self.confirmBtn.disabled=false;requestAnimationFrame(function(){self._initCropper();});};img.onerror=function(){self.loadingDiv.textContent='图片加载失败，请检查图片是否有效';self.loadingDiv.style.color='#ef4444';self.confirmBtn.disabled=true;};img.src=src;};
CoverCropper.prototype._applyAdaptiveLayout=function(){
 var vw=Math.max(document.documentElement.clientWidth||0,window.innerWidth||0),vh=Math.max(document.documentElement.clientHeight||0,window.innerHeight||0),ratio=this.sourceWidth&&this.sourceHeight?this.sourceWidth/this.sourceHeight:this.aspectRatio;
 var maxModalW=Math.min(vw-24,1180),stageMaxW=Math.max(280,maxModalW-36),stageMaxH=Math.max(320,vh-244),stageW=stageMaxW,stageH=Math.round(stageW/ratio);
 if(stageH>stageMaxH){stageH=stageMaxH;stageW=Math.round(stageH*ratio);}stageW=Math.max(300,Math.min(stageW,stageMaxW));stageH=Math.max(280,Math.min(stageH,stageMaxH));
 this.modal.style.width=Math.min(maxModalW,Math.max(stageW+36,360))+'px';this.stage.style.height=stageH+'px';this.stage.style.setProperty('--cropper-stage-height',stageH+'px');this.metaEl.textContent=this.sourceWidth+'×'+this.sourceHeight+' · 原始比例 '+ratio.toFixed(2)+':1';
};
CoverCropper.prototype._initCropper=function(){var self=this;this._destroyCropper();this.cropper=new Cropper(this.cropImage,{aspectRatio:this.aspectRatio,viewMode:1,dragMode:'move',autoCropArea:.82,responsive:true,restore:false,guides:true,center:true,highlight:true,background:false,movable:true,zoomable:true,rotatable:true,scalable:false,cropBoxMovable:true,cropBoxResizable:true,toggleDragModeOnDblclick:false,ready:function(){self._setInitialCropBox();}});};
CoverCropper.prototype._setInitialCropBox=function(){if(!this.cropper)return;var c=this.cropper.getContainerData();if(!c.width||!c.height)return;var cw=c.width*.82,ch=cw/this.aspectRatio;if(ch>c.height*.82){ch=c.height*.82;cw=ch*this.aspectRatio;}this.cropper.setCropBoxData({left:(c.width-cw)/2,top:(c.height-ch)/2,width:cw,height:ch});};
CoverCropper.prototype.confirm=function(){
 if(!this.cropper)return;var self=this,oh=this.outputHeight||Math.round(this.outputWidth/this.aspectRatio),canvas=this.cropper.getCroppedCanvas({width:this.outputWidth,height:oh,imageSmoothingEnabled:true,imageSmoothingQuality:'high',fillColor:'#fff'});if(!canvas){alert('裁剪失败，请重试');return;}
 var base64Data=canvas.toDataURL(this.mimeType,this.outputQuality);this.confirmBtn.disabled=true;this.confirmBtn.textContent='上传中...';
 fetch(this.uploadUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'image_data='+encodeURIComponent(base64Data)}).then(function(r){return r.json();}).then(function(data){if(data.success){var input=document.getElementById(self.croppedPathInput);if(input)input.value=data.path;if(typeof self.onCropped==='function')self.onCropped(data.path,base64Data);self._closeModal();}else{alert('上传失败: '+(window.getApiMessage?getApiMessage(data,'未知错误'):(data.message||'未知错误')));}}).catch(function(e){alert('上传请求失败: '+e.message);}).finally(function(){self.confirmBtn.disabled=false;self.confirmBtn.textContent=self.confirmText;});
};
CoverCropper.prototype.cancel=function(){this._closeModal();};
CoverCropper.prototype.reset=function(){var input=document.getElementById(this.croppedPathInput);if(input)input.value='';var statusEl=document.getElementById(this.cropStatusId);if(statusEl){statusEl.style.display='none';statusEl.innerHTML='';}};
CoverCropper.prototype._closeModal=function(){this._destroyCropper();this.modalOverlay.classList.remove('is-open');this.modalOverlay.style.display='none';document.body.style.overflow='';if(this.currentBlobUrl){URL.revokeObjectURL(this.currentBlobUrl);this.currentBlobUrl=null;}this.loadingDiv.textContent='图片加载中...';this.loadingDiv.style.color='';this.metaEl.textContent='';if(this.hintEl){this.hintEl.textContent='支持 JPG/PNG 格式，单张图片大小不超过 ' + this.maxFileSizeLabel + '。';}};
CoverCropper.prototype._destroyCropper=function(){if(this.cropper){this.cropper.destroy();this.cropper=null;}};
window.CoverCropper=CoverCropper;
})();
