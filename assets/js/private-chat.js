(function() {
    'use strict';

    function $(id) { return document.getElementById(id); }
    function escapeHtml(text) { var div = document.createElement('div'); div.appendChild(document.createTextNode(String(text || ''))); return div.innerHTML; }

    var state = {
        container: null,
        input: null,
        sendBtn: null,
        sendForm: null,
        errorMsg: null,
        loadMoreBtn: null,
        imageUploadBtn: null,
        imageUploadInput: null,
        imagePreviewList: null,
        partnerId: 0,
        myAvatarUrl: '',
        myInitial: '',
        partnerAvatarUrl: '',
        partnerInitial: '',
        keyboardOffsetPx: 0,
        isMobileViewport: false,
        keyboardPollTimer: null,
        baseInnerHeight: window.innerHeight,
        baseVisualHeight: window.visualViewport ? window.visualViewport.height : window.innerHeight,
        pendingImages: [],
        pendingImageId: 0,
        csrfToken: (window.__CSRF_TOKEN__ && typeof window.__CSRF_TOKEN__ === 'string') ? window.__CSRF_TOKEN__ : ''
    };

    function init() {
        state.container = $('chatContainer');
        state.input = $('msgInput');
        state.sendBtn = $('sendBtn');
        state.sendForm = $('pmSendForm');
        state.errorMsg = $('errorMsg');
        state.loadMoreBtn = $('loadMoreBtn');
        state.imageUploadBtn = $('imageUploadBtn');
        state.imageUploadInput = $('imageUploadInput');
        state.imagePreviewList = $('imagePreviewList');
        if (!state.input || !state.container) return;

        state.partnerId = parseInt(document.body.getAttribute('data-partner-id') || '0', 10) || 0;
        state.myAvatarUrl = document.body.getAttribute('data-my-avatar') || '';
        state.myInitial = document.body.getAttribute('data-my-initial') || '';
        state.partnerAvatarUrl = document.body.getAttribute('data-partner-avatar') || '';
        state.partnerInitial = document.body.getAttribute('data-partner-initial') || '';

        refreshMobileViewportFlag();
        bindEvents();
        bindUploadButton();
        bindPasteImageSupport();
        scrollToBottom();
        syncKeyboardOffset();
    }

    function bindEvents() {
        state.sendBtn.addEventListener('click', sendMessage);
        state.input.addEventListener('keydown', onInputKeydown);
        state.input.addEventListener('focus', onInputFocus);
        state.input.addEventListener('blur', onInputBlur);
        if (state.loadMoreBtn) state.loadMoreBtn.addEventListener('click', onLoadMoreClick);
        window.addEventListener('resize', onWindowResize);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', syncKeyboardOffset);
            window.visualViewport.addEventListener('scroll', syncKeyboardOffset);
        }
        window.addEventListener('orientationchange', onOrientationChange);
        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    function refreshMobileViewportFlag() {
        state.isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
        if (!state.isMobileViewport) {
            stopKeyboardPolling();
            setKeyboardOffset(0);
        }
    }

    function startKeyboardPolling() {
        if (state.keyboardPollTimer || !state.isMobileViewport) return;
        state.keyboardPollTimer = setInterval(syncKeyboardOffset, 100);
    }

    function stopKeyboardPolling() {
        if (!state.keyboardPollTimer) return;
        clearInterval(state.keyboardPollTimer);
        state.keyboardPollTimer = null;
    }

    function setKeyboardOffset(px) {
        if (!state.isMobileViewport) {
            state.keyboardOffsetPx = 0;
            document.documentElement.style.setProperty('--pm-keyboard-offset', '0px');
            if (state.sendForm) {
                state.sendForm.classList.remove('keyboard-open');
                state.sendForm.style.transform = 'translateY(0px)';
            }
            if (state.container) state.container.style.paddingBottom = '';
            return;
        }

        state.keyboardOffsetPx = Math.max(0, Math.round(px || 0));
        document.documentElement.style.setProperty('--pm-keyboard-offset', state.keyboardOffsetPx + 'px');
        if (state.sendForm) {
            state.sendForm.style.transform = state.keyboardOffsetPx > 0 ? ('translateY(' + (-state.keyboardOffsetPx) + 'px)') : 'translateY(0px)';
            state.sendForm.classList.toggle('keyboard-open', state.keyboardOffsetPx > 0);
        }
        if (state.container) state.container.style.paddingBottom = state.keyboardOffsetPx > 0 ? (Math.round(state.keyboardOffsetPx * 0.22) + 'px') : '';
        if (state.keyboardOffsetPx > 0) scrollToBottom();
    }

    function syncKeyboardOffset() {
        if (!state.isMobileViewport || document.activeElement !== state.input) {
            setKeyboardOffset(0);
            return;
        }
        var vv = window.visualViewport;
        var inferred = 0;
        if (vv) {
            var layoutHeight = window.innerHeight || state.baseInnerHeight;
            inferred = Math.max(0, layoutHeight - (vv.height + vv.offsetTop));
        }
        inferred = Math.min(Math.max(0, inferred), 260);
        if (inferred < 12) inferred = 0;
        setKeyboardOffset(inferred);
    }

    function onInputFocus() {
        if (!state.isMobileViewport) return;
        startKeyboardPolling();
        setKeyboardOffset(Math.max(140, Math.round(window.innerHeight * 0.2)));
        setTimeout(syncKeyboardOffset, 20);
        setTimeout(syncKeyboardOffset, 80);
        setTimeout(syncKeyboardOffset, 180);
        setTimeout(syncKeyboardOffset, 320);
    }

    function onInputBlur() {
        if (!state.isMobileViewport) return;
        stopKeyboardPolling();
        setTimeout(function() { setKeyboardOffset(0); }, 80);
    }

    function onWindowResize() {
        refreshMobileViewportFlag();
        state.baseInnerHeight = Math.max(state.baseInnerHeight, window.innerHeight);
        if (window.visualViewport) state.baseVisualHeight = Math.max(state.baseVisualHeight, window.visualViewport.height);
        syncKeyboardOffset();
    }

    function onOrientationChange() {
        setTimeout(function() {
            refreshMobileViewportFlag();
            state.baseInnerHeight = window.innerHeight;
            state.baseVisualHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            syncKeyboardOffset();
        }, 80);
    }

    function onVisibilityChange() {
        if (document.visibilityState === 'visible') setTimeout(syncKeyboardOffset, 60);
    }

    function onInputKeydown(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); sendMessage(); return; }
        if (e.key === 'Tab') {
            e.preventDefault();
            var pos = state.input.selectionStart;
            state.input.value = state.input.value.substring(0, pos) + '    ' + state.input.value.substring(state.input.selectionEnd);
            state.input.selectionStart = state.input.selectionEnd = pos + 4;
        }
    }

    function getJsonHeaders() {
        var headers = { 'Content-Type': 'application/json' };
        if (state.csrfToken) headers['X-CSRF-Token'] = state.csrfToken;
        return headers;
    }

    function showError(msg) {
        if (!state.errorMsg) return;
        state.errorMsg.textContent = msg;
        state.errorMsg.classList.add('show');
        setTimeout(function() { state.errorMsg.classList.remove('show'); state.errorMsg.textContent = ''; }, 5000);
    }

    function hideError() {
        if (!state.errorMsg) return;
        state.errorMsg.classList.remove('show');
        state.errorMsg.textContent = '';
    }

    function renderMarkdown(text) {
        var content = String(text || '');
        if (typeof marked !== 'undefined') {
            var html = marked.parse(content, { breaks: true });
            var singleParagraph = html.match(/^\s*<p>([\s\S]*)<\/p>\s*$/i);
            if (singleParagraph) return singleParagraph[1];
            return html;
        }
        return escapeHtml(content).replace(/\n/g, '<br>');
    }

    function normalizeImageUrl(url) {
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) return url;
        return '/' + String(url).replace(/^\/+/, '');
    }

    function renderMessageContent(message) {
        var text = '';
        var images = [];
        if (message && typeof message === 'object') {
            text = String(message.content_text || message.text || '');
            images = Array.isArray(message.content_images) ? message.content_images : (Array.isArray(message.images) ? message.images : []);
            if (!text && typeof message.content === 'string') {
                try {
                    var legacy = JSON.parse(message.content);
                    text = String(legacy.text || legacy.content_text || '');
                    images = Array.isArray(legacy.images) ? legacy.images : images;
                } catch (e) {
                    text = String(message.content || '');
                }
            }
        } else if (typeof message === 'string') {
            text = message;
        }
        var html = '<div class="pm-bubble-content markdown-body">';
        if (text) html += renderMarkdown(text);
        if (images && images.length) {
            html += '<div class="pm-inline-images">';
            for (var i = 0; i < images.length; i++) {
                var imgUrl = normalizeImageUrl(images[i]);
                html += '<img src="' + escapeHtml(imgUrl) + '" alt="私信图片" class="pm-inline-image js-image-viewer-trigger" data-viewer-src="' + escapeHtml(imgUrl) + '" data-viewer-alt="私信图片">';
            }
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    function createBubbleHTML(content, time, isMine, avatarUrl, initial) {
        var avatarHtml = avatarUrl ? ('<img src="' + escapeHtml(avatarUrl) + '" class="pm-bubble-avatar" alt="">') : ('<div class="pm-bubble-avatar fallback">' + escapeHtml(initial) + '</div>');
        return '<div class="pm-chat-bubble ' + (isMine ? 'mine' : 'theirs') + '">' + avatarHtml + '<div class="pm-bubble-body">' + renderMessageContent(content) + '<span class="pm-bubble-time">' + escapeHtml(time) + '</span></div></div>';
    }

    function scrollToBottom() { if (state.container) state.container.scrollTop = state.container.scrollHeight; }

    function fileToDataUrl(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = function() { reject(new Error('读取图片失败')); };
            reader.readAsDataURL(file);
        });
    }

    function createPreviewItem(file) {
        var id = ++state.pendingImageId;
        var url = URL.createObjectURL(file);
        state.pendingImages.push({ id: id, file: file, url: url });
        var item = document.createElement('div');
        item.className = 'pm-preview-item';
        item.setAttribute('data-id', String(id));
        item.innerHTML = '<img src="' + url + '" alt=""><button type="button" class="pm-preview-remove" aria-label="移除图片">×</button>';
        item.querySelector('.pm-preview-remove').addEventListener('click', function() { removePendingImage(id); });
        state.imagePreviewList.appendChild(item);
    }

    function removePendingImage(id) {
        for (var i = 0; i < state.pendingImages.length; i++) {
            if (state.pendingImages[i].id === id) {
                URL.revokeObjectURL(state.pendingImages[i].url);
                state.pendingImages.splice(i, 1);
                break;
            }
        }
        var node = state.imagePreviewList.querySelector('[data-id="' + id + '"]');
        if (node) node.remove();
    }

    function clearPendingImages() {
        while (state.pendingImages.length) URL.revokeObjectURL(state.pendingImages.pop().url);
        state.imagePreviewList.innerHTML = '';
    }

    function addImages(files) {
        Array.prototype.slice.call(files || []).forEach(function(file) {
            if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
            if (file.size > 4 * 1024 * 1024) return showError('图片大小不能超过 4MB');
            createPreviewItem(file);
        });
    }

    function bindUploadButton() {
        if (!state.imageUploadBtn || !state.imageUploadInput) return;
        state.imageUploadBtn.addEventListener('click', function() { state.imageUploadInput.click(); });
        state.imageUploadInput.addEventListener('change', function() {
            addImages(state.imageUploadInput.files);
            state.imageUploadInput.value = '';
        });
    }

    function getClipboardImageFiles(event) {
        var items = event.clipboardData && event.clipboardData.items ? event.clipboardData.items : [];
        var files = [];
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            if (item.kind === 'file' && item.type.indexOf('image/') === 0) {
                var file = item.getAsFile();
                if (file) files.push(file);
            }
        }
        return files;
    }

    function bindPasteImageSupport() {
        state.input.addEventListener('paste', function(event) {
            var files = getClipboardImageFiles(event);
            if (files.length) {
                event.preventDefault();
                addImages(files);
            }
        });
    }

    function sendMessage() {
        var content = state.input.value.trim();
        if (!content && state.pendingImages.length === 0) return;
        if (!state.csrfToken) { showError('页面令牌缺失，请刷新后重试'); return; }
        hideError();
        state.sendBtn.disabled = true;
        Promise.all(state.pendingImages.map(function(item) { return fileToDataUrl(item.file); })).then(function(imageDataUrls) {
            return fetch('/api/private_msg.php', {
                method: 'POST',
                headers: getJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'send', receiver_id: state.partnerId, content: content, images: imageDataUrls })
            });
        }).then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        }).then(function(data) {
            state.sendBtn.disabled = false;
            if (data.success) {
                var emptyTip = state.container.querySelector('[style*="text-align:center"]');
                if (emptyTip) emptyTip.remove();
                state.container.insertAdjacentHTML('beforeend', createBubbleHTML({ content_text: data.message.content_text || '', content_images: data.message.content_images || [] }, data.message.created_at, true, state.myAvatarUrl, state.myInitial));
                state.input.value = '';
                clearPendingImages();
                scrollToBottom();
            } else {
                showError(data.message || '发送失败');
            }
        }).catch(function(err) {
            state.sendBtn.disabled = false;
            showError('网络错误，请稍后重试（' + (err && err.message ? err.message : '未知错误') + '）');
        });
    }

    function onLoadMoreClick(e) {
        e.preventDefault();
        var page = parseInt(state.loadMoreBtn.getAttribute('data-page'), 10) || 2;
        state.loadMoreBtn.textContent = '加载中...';
        fetch('/api/private_msg.php', {
            method: 'POST',
            headers: getJsonHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'history', partner_id: state.partnerId, page: page })
        }).then(function(res) { return res.json(); }).then(function(data) {
            if (data.success && data.messages && data.messages.length > 0) {
                var msgs = data.messages.slice().reverse();
                var html = '';
                for (var i = 0; i < msgs.length; i++) {
                    var msg = msgs[i];
                    var avatarUrl = msg.is_mine ? state.myAvatarUrl : state.partnerAvatarUrl;
                    var initial = msg.is_mine ? state.myInitial : state.partnerInitial;
                    html += createBubbleHTML(msg.content || { content_text: msg.content_text || '', content_images: msg.content_images || [] }, msg.created_at, !!msg.is_mine, avatarUrl, initial);
                }
                var oldHeight = state.container.scrollHeight;
                state.container.insertAdjacentHTML('afterbegin', html);
                state.container.scrollTop = state.container.scrollHeight - oldHeight;
                if (data.has_more) {
                    state.loadMoreBtn.setAttribute('data-page', page + 1);
                    state.loadMoreBtn.textContent = '加载更早的消息';
                } else {
                    $('loadMoreArea').style.display = 'none';
                }
            } else {
                $('loadMoreArea').style.display = 'none';
            }
        }).catch(function() {
            state.loadMoreBtn.textContent = '加载失败，点击重试';
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
