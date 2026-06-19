/**
 * comment-core.js —— 评论系统共享核心（任务7：三套评论合一）
 * ------------------------------------------------------------------
 * 这是从 article-comments.js 抽取、参数化而来的可复用评论交互核心。
 * 报错页 / 讨论页 / 文章页通过 GalCommentCore.create(config) 复用同一套逻辑，
 * 逐页迁移（先文章页，行为完全不变作为安全基线）。
 *
 * 与 api/comment.php 的契约：
 *   端点    : POST /api/comment.php
 *   action  : create | update | delete（图片上传 action=upload_image，返回 url）
 *   字段    : { success, message, code?, comment?, redirect_url? }
 *
 * config:
 *   tag           调试标签，如 'article' / 'discussion' / 'error'
 *   contentType   评论所属内容类型，如 'article'
 *   getContentId  function(): int  返回当前内容 ID
 *   exposeGlobals （可选）true 时把 openCommentModal/submitComment/... 挂到 window，
 *                 并把删除函数同时挂为 deleteComment/galDeleteComment/handleDeleteComment 三个别名
 *                 （兼容各页模板里不同的 inline onclick 名）
 *
 * 返回：{ init, openCommentModal, closeCommentModal, switchCommentEditorMode,
 *        submitComment, cancelReply, replyToComment, editComment, deleteComment }
 */
(function () {
    'use strict';

    function create(config) {
        config = config || {};
        var TAG = config.tag || 'core';
        var CONTENT_TYPE = config.contentType || '';
        var getContentId = typeof config.getContentId === 'function' ? config.getContentId : function () { return 0; };

        function byId(id) { return document.getElementById(id); }
        function getViewConfig() { return byId('view-counter-config'); }
        function getCsrfToken() {
            // 报错页的 csrf 在 window.GalCommentConfig.csrfToken；文章/讨论页在 #view-counter-config[data-csrf]
            if (window.GalCommentConfig && window.GalCommentConfig.csrfToken) return window.GalCommentConfig.csrfToken;
            var el = getViewConfig(); return el ? (el.getAttribute('data-csrf') || '') : '';
        }

        function isCommentDebugEnabled() {
            return /(?:^|[?&])comment_debug=1(?:&|$)/.test(window.location.search);
        }
        function ensureDebugPanel() {
            if (!isCommentDebugEnabled()) return null;
            var panel = document.getElementById('commentDebugPanel');
            if (panel) return panel;
            panel = document.createElement('div');
            panel.id = 'commentDebugPanel';
            panel.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:2147483647;max-width:40vw;max-height:35vh;overflow:auto;padding:10px 12px;background:rgba(17,24,39,.92);color:#e5e7eb;font:12px/1.5 monospace;border:1px solid rgba(255,255,255,.15);border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.35);white-space:pre-wrap;';
            panel.textContent = 'comment debug enabled';
            document.body.appendChild(panel);
            return panel;
        }
        function appendDebugLine(line) {
            var panel = ensureDebugPanel();
            if (!panel) return;
            panel.textContent += '\n' + line;
        }
        function debugLog() {
            var args = Array.prototype.slice.call(arguments);
            var msg = '[comment-debug][' + TAG + '] ' + args.map(function (v) {
                if (typeof v === 'string') return v;
                try { return JSON.stringify(v); } catch (e) { return String(v); }
            }).join(' ');
            appendDebugLine(msg);
            if (!isCommentDebugEnabled() || !window.console || !console.log) return;
            args.unshift('[comment-debug][' + TAG + ']');
            console.log.apply(console, args);
        }
        function debugWarn() {
            var args = Array.prototype.slice.call(arguments);
            var msg = '[comment-debug][' + TAG + '] WARN ' + args.map(function (v) {
                if (typeof v === 'string') return v;
                try { return JSON.stringify(v); } catch (e) { return String(v); }
            }).join(' ');
            appendDebugLine(msg);
            if (!isCommentDebugEnabled() || !window.console || !console.warn) return;
            args.unshift('[comment-debug][' + TAG + ']');
            console.warn.apply(console, args);
        }

        var state = {
            editor: null, previewPane: null, modalOverlay: null, submitButton: null,
            cancelReplyButton: null, replyIndicator: null, modalTitle: null, editorBody: null,
            csrfToken: getCsrfToken(), replyParentId: 0, editingCommentId: 0,
            submitting: false, imageInput: null, requestKey: '', deleteLock: false,
            mentionPopup: null, mentionItems: [], selectedMentionIndex: 0,
            mentionRange: null, mentionRequestToken: 0
        };

        function hydrateDomRefs() {
            state.editor = byId('commentTextarea');
            state.previewPane = byId('commentPreview');
            state.modalOverlay = byId('commentModalOverlay');
            state.submitButton = byId('commentSubmitBtn');
            state.cancelReplyButton = byId('cancelReplyBtn');
            state.replyIndicator = byId('replyIndicator');
            state.modalTitle = byId('commentModalTitle');
            state.editorBody = byId('commentEditorBody');
        }

        function init() {
            ensureDebugPanel();
            hydrateDomRefs();
            debugLog('init', { hasEditor: !!state.editor, hasModal: !!state.modalOverlay, contentType: CONTENT_TYPE });
            bindGlobalHooks();

            if (!state.editor || !state.modalOverlay) {
                debugWarn('init pending: waiting for modal/editor to mount');
                window.setTimeout(function () {
                    hydrateDomRefs();
                    if (state.editor && state.modalOverlay) {
                        bindToolbar(); bindEditor(); bindModal(); ensureMentionPopup(); renderPreview();
                    }
                }, 50);
                return;
            }
            bindToolbar(); bindEditor(); bindModal(); ensureMentionPopup(); renderPreview();
        }

        function bindToolbar() {
            var toolbar = byId('commentToolbar');
            if (!toolbar) return;
            toolbar.addEventListener('click', function (e) {
                var button = e.target.closest('button[data-action]');
                if (!button) return;
                handleToolbarAction(button.getAttribute('data-action'));
                state.editor.focus();
            });
        }

        function bindEditor() {
            state.editor.addEventListener('input', renderPreview);
            state.editor.addEventListener('keydown', onEditorKeydown);
            state.editor.addEventListener('keyup', onEditorKeyup);
            state.editor.addEventListener('click', syncMentionAutocomplete);
        }

        function bindModal() {
            if (!state.modalOverlay) { debugWarn('bindModal skipped: modal overlay missing'); return; }
            state.modalOverlay.addEventListener('click', function (e) {
                if (e.target === state.modalOverlay) { closeCommentModal(); }
            });
            document.addEventListener('click', function (e) {
                var hit = e.target && e.target.closest ? e.target.closest('.comment-reply-btn, .comment-edit-btn, .comment-delete-btn, #commentSubmitBtn') : null;
                if (hit) {
                    if (hit.id === 'commentSubmitBtn') { e.preventDefault(); submitComment(); return; }
                    if (hit.classList.contains('comment-delete-btn')) { e.preventDefault(); deleteComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0); return; }
                    if (hit.classList.contains('comment-edit-btn')) { e.preventDefault(); editComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0); return; }
                    if (hit.classList.contains('comment-reply-btn')) { e.preventDefault(); replyToComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0, hit.getAttribute('data-comment-username') || ''); return; }
                }
                onDocumentClick(e);
            });
            window.addEventListener('resize', positionMentionPopup);
            window.addEventListener('scroll', positionMentionPopup, true);
        }

        function bindGlobalHooks() {
            if (!config.exposeGlobals) return;
            window.openCommentModal = openCommentModal;
            window.closeCommentModal = closeCommentModal;
            window.switchCommentEditorMode = switchCommentEditorMode;
            window.submitComment = submitComment;
            window.cancelReply = cancelReply;
            window.replyToComment = replyToComment;
            window.editComment = editComment;
            window.deleteDiscussion = deleteDiscussion;
            // 删除函数挂三个别名，兼容各页模板里不同的 inline onclick 名
            window.deleteComment = deleteComment;
            window.galDeleteComment = deleteComment;
            window.handleDeleteComment = deleteComment;
            window.GalCommentBridge = {
                openCommentModal: openCommentModal, closeCommentModal: closeCommentModal,
                submitComment: submitComment, replyToComment: replyToComment,
                editComment: editComment, deleteComment: deleteComment
            };
        }

        function switchCommentEditorMode(mode) {
            if (!state.editorBody) return;
            state.editorBody.className = 'md-editor-body mode-' + mode;
            document.querySelectorAll('#commentModalOverlay .md-mode-tab').forEach(function (tab) {
                tab.classList.remove('active');
                if (tab.getAttribute('data-mode') === mode) tab.classList.add('active');
            });
            if (mode === 'preview') renderPreview();
        }

        function openCommentModal() {
            if (state.modalOverlay) state.modalOverlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            if (state.editor) state.editor.focus();
        }

        function closeCommentModal() {
            if (state.modalOverlay) state.modalOverlay.style.display = 'none';
            document.body.style.overflow = '';
            state.replyParentId = 0;
            state.editingCommentId = 0;
            setMode('create');
            cancelReply();
        }

        function setMode(mode) {
            if (state.modalTitle) state.modalTitle.textContent = mode === 'edit' ? '编辑评论' : '发表评论';
            if (state.submitButton) state.submitButton.textContent = mode === 'edit' ? '保存修改' : '提交评论';
            if (state.editorBody) state.editorBody.className = 'md-editor-body mode-edit';
        }

        function cancelReply() {
            state.replyParentId = 0;
            if (state.replyIndicator) { state.replyIndicator.textContent = ''; state.replyIndicator.style.display = 'none'; }
            if (state.cancelReplyButton) state.cancelReplyButton.style.display = 'none';
        }

        function replyToComment(commentId, username) {
            state.editingCommentId = 0;
            state.replyParentId = commentId;
            setMode('create');
            if (state.replyIndicator) { state.replyIndicator.textContent = '回复 ' + username; state.replyIndicator.style.display = 'inline'; }
            if (state.cancelReplyButton) state.cancelReplyButton.style.display = '';
            if (state.editor) state.editor.value = '';
            renderPreview();
            openCommentModal();
        }

        function editComment(commentId) {
            var item = byId('comment-' + commentId);
            if (!item || !state.editor) return;
            state.replyParentId = 0;
            state.editingCommentId = commentId;
            setMode('edit');
            cancelReply();
            state.editor.value = item.getAttribute('data-comment-content') || '';
            renderPreview();
            openCommentModal();
        }

        function handleToolbarAction(action) {
            if (!state.editor) return;
            var wrapMap = { bold: ['**', '**', '粗体文本'], italic: ['*', '*', '斜体文本'], strikethrough: ['~~', '~~', '删除线文本'], code: ['`', '`', '代码'] };
            if (wrapMap[action]) { wrapSelection(wrapMap[action][0], wrapMap[action][1], wrapMap[action][2]); renderPreview(); return; }
            if (action === 'link') { var url = prompt('请输入链接地址:', 'https://'); if (!url) return; wrapSelection('[', '](' + url + ')', '链接文本'); renderPreview(); return; }
            if (action === 'image') { if (config.multiImageUpload) { triggerMultiImageUpload(); } else { uploadImage(); } return; }
            if (action === 'mention') { var name = prompt('请输入要提及的站内用户名:', ''); if (name == null) return; insertAtCursor('@' + String(name).replace(/^@+/, '').trim() + ' '); renderPreview(); return; }
            if (action === 'codeblock') { insertAtCursor('\n```\n代码内容\n```\n'); renderPreview(); return; }
            if (action === 'ul') { insertAtLineStart('- '); renderPreview(); return; }
            if (action === 'ol') { insertAtLineStart('1. '); renderPreview(); return; }
            if (action === 'quote') { insertAtLineStart('> '); renderPreview(); return; }
        }

        function wrapSelection(before, after, fallbackText) {
            var start = state.editor.selectionStart, end = state.editor.selectionEnd;
            var selected = state.editor.value.substring(start, end);
            var insert = selected ? before + selected + after : before + fallbackText + after;
            state.editor.value = state.editor.value.substring(0, start) + insert + state.editor.value.substring(end);
            state.editor.selectionStart = start + before.length;
            state.editor.selectionEnd = start + before.length + (selected ? selected.length : fallbackText.length);
        }

        function insertAtCursor(text) {
            var start = state.editor.selectionStart;
            state.editor.value = state.editor.value.substring(0, start) + text + state.editor.value.substring(start);
            state.editor.selectionStart = state.editor.selectionEnd = start + text.length;
        }

        function insertAtLineStart(prefix) {
            var start = state.editor.selectionStart;
            var lineStart = state.editor.value.lastIndexOf('\n', start - 1) + 1;
            state.editor.value = state.editor.value.substring(0, lineStart) + prefix + state.editor.value.substring(lineStart);
            state.editor.selectionStart = state.editor.selectionEnd = start + prefix.length;
        }

        function renderPreview() {
            if (!state.previewPane || typeof marked === 'undefined') return;
            var content = state.editor.value || '';
            content = normalizePlainImageUrls(content);
            content = preserveExtraBlankLines(content);
            var html = content ? marked.parse(content) : '<div class="md-preview-empty">预览区域</div>';
            state.previewPane.innerHTML = '<div class="markdown-body">' + restoreExtraBlankLines(html) + '</div>';
        }

        function uploadImage() {
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) return;
                var file = input.files[0];
                if (file.size > 2 * 1024 * 1024) { alert('图片大小不能超过 2MB'); return; }
                var formData = new FormData();
                formData.append('action', 'upload_image');
                formData.append('image', file);
                fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: state.csrfToken ? { 'X-CSRF-Token': state.csrfToken } : {}, body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { if (!data || !data.success || !data.url) { alert((data && data.message) ? data.message : '图片上传失败'); return; } insertAtCursor('![](' + data.url + ')'); renderPreview(); })
                    .catch(function () { alert('网络错误，图片上传失败'); });
            });
            input.click();
        }

        // 多图上传（config.multiImageUpload=true 时启用，如讨论页）：支持一次多选，逐张上传后插入
        function ensureImageInput() {
            if (state.imageInput) return state.imageInput;
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.multiple = true;
            input.style.display = 'none';
            document.body.appendChild(input);
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files || []);
                if (files.length) uploadCommentImagesMulti(files);
                input.value = '';
            });
            state.imageInput = input;
            return input;
        }

        function triggerMultiImageUpload() {
            var input = ensureImageInput();
            if (input) input.click();
        }

        function uploadCommentImagesMulti(files) {
            var list = Array.prototype.slice.call(files || []);
            var images = [];
            list.forEach(function (file) {
                if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
                if (file.size > 4 * 1024 * 1024) { alert('图片大小不能超过 4MB'); return; }
                images.push(file);
            });
            if (!images.length) return;
            var uploadOne = function (file) {
                var formData = new FormData();
                formData.append('action', 'upload_image');
                formData.append('image', file);
                return fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: state.csrfToken ? { 'X-CSRF-Token': state.csrfToken } : {}, body: formData })
                    .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                    .then(function (data) { if (!data || !data.success || !data.url) { throw new Error((data && data.message) ? data.message : '图片上传失败'); } return data.url; });
            };
            Promise.all(images.map(uploadOne))
                .then(function (urls) { urls.forEach(function (url) { insertAtCursor('![](' + url + ')\n'); }); renderPreview(); state.editor.focus(); })
                .catch(function (err) { alert(err && err.message ? err.message : '图片上传失败'); });
        }

        // ── 就地渲染（提交后不刷新整页，客户端构建评论并插入 DOM）──
        function createCommentRequestKey() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return 'cmt_' + window.crypto.randomUUID().replace(/-/g, '');
            }
            return 'cmt_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
        }

        function updateCommentCount(delta) {
            var countEl = byId('commentCount');
            if (!countEl) return;
            var current = parseInt((countEl.textContent || '0').replace(/\D+/g, ''), 10);
            if (isNaN(current)) current = 0;
            countEl.textContent = String(Math.max(0, current + delta));
        }

        function removeCommentEmptyState() {
            var empty = byId('commentEmpty');
            if (empty && empty.parentNode) empty.parentNode.removeChild(empty);
        }

        function buildCommentHtml(comment, rawContent) {
            if (!comment || !comment.id) return '';
            var cid = parseInt(comment.id, 10);
            var avatarHtml = comment.avatar_url
                ? '<img src="' + escapeHtml(comment.avatar_url) + '" class="comment-author-avatar" alt="">'
                : '<span class="comment-author-avatar fallback">' + escapeHtml((comment.username || '').slice(0, 1)) + '</span>';
            var replyMeta = '', replyContext = '';
            if (comment.parent_id && comment.reply_to_username) {
                var replyUserId = parseInt(comment.reply_to_user_id, 10) || 0;
                replyMeta = '<span class="comment-reply-to">回复 <a href="/profile?user_id=' + replyUserId + '" class="comment-user-link">' + escapeHtml(comment.reply_to_username) + '</a></span>';
                if (comment.reply_to_content) {
                    replyContext = '<div class="comment-reply-context">' +
                        '<div class="comment-reply-header">回复 <span class="comment-user-link">@' + escapeHtml(comment.reply_to_username) + '</span></div>' +
                        '<div class="comment-reply-quote">' + escapeHtml(comment.reply_to_content) + '</div>' +
                        '<div class="comment-reply-divider"></div></div>';
                }
            }
            return '<div class="comment-item" id="comment-' + cid + '" data-comment-content="' + escapeHtml(rawContent) + '">' +
                '<div class="comment-author"><span class="comment-author-info">' + avatarHtml +
                '<strong><a href="/profile?user_id=' + (parseInt(comment.user_id, 10) || 0) + '" class="comment-user-link">' + escapeHtml(comment.username || '') + '</a></strong>' + replyMeta +
                '</span><span class="comment-meta-right"><span class="comment-time">' + escapeHtml(comment.created_at || '') + '</span>' +
                '<button class="comment-reply-btn" data-comment-id="' + cid + '" data-comment-username="' + escapeHtml(comment.username || '') + '">回复</button>' +
                '<button class="comment-reply-btn comment-edit-btn" data-comment-id="' + cid + '">编辑</button>' +
                '</span></div>' +
                '<div class="comment-body markdown-body">' + replyContext +
                '<div class="comment-main-content">' + (comment.content_html || '') + '</div></div></div>';
        }

        function insertCommentIntoList(comment, rawContent) {
            var list = byId('commentList');
            if (!list || !comment || !comment.id) return false;
            removeCommentEmptyState();
            list.insertAdjacentHTML('beforeend', buildCommentHtml(comment, rawContent));
            return true;
        }

        function updateExistingComment(commentId, contentHtml, rawContent) {
            var item = byId('comment-' + commentId);
            if (!item) return false;
            item.setAttribute('data-comment-content', rawContent || '');
            var mainContent = item.querySelector('.comment-main-content');
            if (mainContent) mainContent.innerHTML = contentHtml || '';
            return true;
        }

        function goToComment(commentId, redirectUrl) {
            var hash = '#comment-' + commentId;
            var target = byId('comment-' + commentId);
            if (target) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, document.title, window.location.pathname + window.location.search + hash);
                } else { window.location.hash = hash; }
                if (window.GalCommentInteractions && typeof window.GalCommentInteractions.highlightCommentByHash === 'function') {
                    window.GalCommentInteractions.highlightCommentByHash(hash);
                } else {
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }
            if (redirectUrl) { window.location.assign(redirectUrl); return; }
            window.location.reload();
        }

        function finishSubmit() {
            state.requestKey = '';
            state.submitting = false;
            if (state.submitButton) { state.submitButton.disabled = false; state.submitButton.textContent = '提交评论'; }
            closeCommentModal();
        }

        // 就地插入失败时的兜底：跳转/刷新到新评论（同页则强制 reload，兼容美化/非美化 URL）
        function navigateToComment(data) {
            var target = (data && data.redirect_url) || (window.location.pathname + window.location.search);
            var a = document.createElement('a');
            a.href = target;
            var samePage = (a.origin + a.pathname + a.search) === (window.location.origin + window.location.pathname + window.location.search);
            if (samePage) { if (a.hash) { try { window.location.hash = a.hash; } catch (e) {} } window.location.reload(); }
            else { window.location.assign(target); }
        }

        function submitComment() {
            // 重入守卫：兼容模板里“inline onclick + #commentSubmitBtn 事件委托”双触发，避免重复提交
            if (state.submitting) { debugWarn('submitComment ignored: already submitting'); return; }
            var rawContent = state.editor && state.editor.value || '';
            if (!rawContent.trim()) { debugWarn('submitComment aborted: empty content'); alert('请输入评论内容'); return; }
            var btn = state.submitButton;
            if (!btn) { debugWarn('submitComment aborted: submit button missing'); return; }
            state.submitting = true;
            btn.disabled = true;
            btn.textContent = state.editingCommentId ? '保存中...' : '提交中...';

            var payload;
            if (state.editingCommentId) {
                payload = { action: 'update', id: state.editingCommentId, content: rawContent };
            } else {
                state.requestKey = createCommentRequestKey();
                payload = { action: 'create', content_type: CONTENT_TYPE, content_id: getContentId(), content: rawContent, parent_id: state.replyParentId || null, idempotency_key: state.requestKey };
            }
            var requestBody = Object.assign({ _csrf: state.csrfToken }, payload);

            fetch('/api/comment.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken },
                body: JSON.stringify(requestBody)
            })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        state.submitting = false;
                        btn.disabled = false;
                        btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                        if (!state.editingCommentId) state.requestKey = '';
                        alert((data && data.message) || '提交失败');
                        return;
                    }
                    if (state.editingCommentId) {
                        var updated = updateExistingComment(state.editingCommentId, data.comment && data.comment.content_html, rawContent);
                        finishSubmit();
                        if (updated) { goToComment(state.editingCommentId, data.redirect_url || ''); } else { navigateToComment(data); }
                        return;
                    }
                    var inserted = insertCommentIntoList(data.comment, rawContent);
                    updateCommentCount(1);
                    finishSubmit();
                    if (inserted) { goToComment(data.comment && data.comment.id, data.redirect_url || ''); } else { navigateToComment(data); }
                })
                .catch(function (err) {
                    state.submitting = false;
                    btn.disabled = false;
                    btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                    if (!state.editingCommentId) state.requestKey = '';
                    debugWarn('submitComment failed', err);
                    alert('网络错误，请稍后重试');
                });
        }

        function deleteComment(commentId) {
            // 同一次点击只处理一次：模板 inline onclick 与核心事件委托会双触发，
            // 利用 confirm() 阻塞 + setTimeout(0) 下一 tick 解锁，挡掉同一次点击的第二次调用（避免弹两次确认）。
            if (state.deleteLock) return;
            state.deleteLock = true;
            setTimeout(function () { state.deleteLock = false; }, 0);
            if (!confirm('确定删除此评论？')) return;
            fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken }, body: JSON.stringify({ _csrf: state.csrfToken, action: 'delete', id: commentId }) })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (!data || !data.success) { alert((data && data.message) || '删除失败'); return; } var el = byId('comment-' + commentId); if (el) el.remove(); })
                .catch(function () { alert('网络错误'); });
        }

        function deleteDiscussion(id) {
            // 删除话题：跳转到服务端删除入口（与讨论页历史行为一致；文章页不存在此按钮，无影响）
            var discussionId = parseInt(id || 0, 10);
            if (!discussionId) return;
            if (!window.confirm('确认删除这个话题吗？删除后无法恢复。')) return;
            window.location.href = '/discussion_delete.php?id=' + discussionId;
        }

        function onEditorKeydown(e) {
            if (state.mentionPopup && state.mentionPopup.style.display === 'block') {
                if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionSelection(1); return; }
                if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionSelection(-1); return; }
                if (e.key === 'Enter') { e.preventDefault(); chooseMentionSelection(); return; }
                if (e.key === 'Escape') { e.preventDefault(); hideMentionPopup(); return; }
            }
            if (e.key === 'Tab') { e.preventDefault(); insertAtCursor('    '); }
        }

        function onEditorKeyup(e) { if (!['ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(e.key)) syncMentionAutocomplete(); }
        function onDocumentClick(e) { if (!state.mentionPopup) return; if (e.target === state.editor || state.mentionPopup.contains(e.target)) return; hideMentionPopup(); }

        function ensureMentionPopup() {
            if (state.mentionPopup || !state.editor) return;
            var popup = document.createElement('div');
            popup.className = 'md-mention-popup';
            popup.style.display = 'none';
            popup.innerHTML = '<div class="md-mention-list"></div>';
            document.body.appendChild(popup);
            popup.addEventListener('mousedown', function (e) {
                var item = e.target.closest('.md-mention-item');
                if (!item) return;
                e.preventDefault();
                state.selectedMentionIndex = parseInt(item.getAttribute('data-index') || '0', 10) || 0;
                chooseMentionSelection();
            });
            state.mentionPopup = popup;
        }

        function syncMentionAutocomplete() {
            var info = getMentionQuery();
            if (!info || !info.query) { hideMentionPopup(); return; }
            state.mentionRange = info;
            fetchMentionSuggestions(info.query);
        }

        function getMentionQuery() {
            var cursor = state.editor.selectionStart;
            if (cursor !== state.editor.selectionEnd) return null;
            var before = state.editor.value.slice(0, cursor);
            var match = before.match(/(^|[^一-龥A-Za-z0-9_])@([一-龥A-Za-z0-9_]*)$/);
            if (!match) return null;
            return { query: match[2], start: cursor - match[2].length - 1, end: cursor };
        }

        function fetchMentionSuggestions(query) {
            var token = ++state.mentionRequestToken;
            fetch('/api/mention.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (token !== state.mentionRequestToken) return;
                    if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) { hideMentionPopup(); return; }
                    state.mentionItems = data.users;
                    state.selectedMentionIndex = 0;
                    renderMentionPopup();
                })
                .catch(function () { if (token !== state.mentionRequestToken) return; hideMentionPopup(); });
        }

        function renderMentionPopup() {
            if (!state.mentionPopup || !state.mentionItems.length) { hideMentionPopup(); return; }
            state.mentionPopup.querySelector('.md-mention-list').innerHTML = state.mentionItems.map(function (user, index) {
                return '<button type="button" class="md-mention-item' + (index === state.selectedMentionIndex ? ' is-active' : '') + '" data-index="' + index + '">@' + escapeHtml(user.username) + '</button>';
            }).join('');
            positionMentionPopup();
            state.mentionPopup.style.display = 'block';
        }

        function positionMentionPopup() {
            if (!state.mentionPopup || !state.editor) return;
            var rect = state.editor.getBoundingClientRect();
            state.mentionPopup.style.left = (window.scrollX + rect.left + 16) + 'px';
            state.mentionPopup.style.top = (window.scrollY + rect.bottom - 12) + 'px';
            state.mentionPopup.style.width = Math.min(rect.width - 32, 320) + 'px';
        }

        function moveMentionSelection(step) { if (!state.mentionItems.length) return; state.selectedMentionIndex = (state.selectedMentionIndex + step + state.mentionItems.length) % state.mentionItems.length; renderMentionPopup(); }
        function chooseMentionSelection() { if (!state.mentionItems.length || !state.mentionRange) return; var selected = state.mentionItems[state.selectedMentionIndex]; if (!selected) return; var replacement = '@' + selected.username + ' '; state.editor.value = state.editor.value.slice(0, state.mentionRange.start) + replacement + state.editor.value.slice(state.mentionRange.end); state.editor.selectionStart = state.editor.selectionEnd = state.mentionRange.start + replacement.length; hideMentionPopup(); state.editor.focus(); }
        function hideMentionPopup() { if (state.mentionPopup) state.mentionPopup.style.display = 'none'; state.mentionItems = []; state.mentionRange = null; }

        function normalizePlainImageUrls(text) { return String(text || '').replace(/(^|[\s>\(])((?:https?:)?\/\/[^\s<]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<]*)?(?:#[^\s<]*)?)(?=$|[\s<\)])/gi, function (match, prefix, url) { return prefix + '![](' + url + ')'; }); }
        function preserveExtraBlankLines(text) { return String(text || '').replace(/\n{3,}/g, function (match) { var extraCount = match.length - 2; var result = '\n\n'; for (var i = 0; i < extraCount; i++) result += '@@MD_EXTRA_BLANK_LINE@@\n'; return result; }); }
        function restoreExtraBlankLines(html) { var output = String(html || ''); output = output.replace(/<p>@@MD_EXTRA_BLANK_LINE@@<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>'); output = output.replace(/@@MD_EXTRA_BLANK_LINE@@<br\s*\/?\s*>/g, '<br>'); output = output.replace(/@@MD_EXTRA_BLANK_LINE@@/g, '<br>'); return output; }
        function escapeHtml(str) { var div = document.createElement('div'); div.textContent = str; return div.innerHTML; }

        return {
            init: init,
            openCommentModal: openCommentModal,
            closeCommentModal: closeCommentModal,
            switchCommentEditorMode: switchCommentEditorMode,
            submitComment: submitComment,
            cancelReply: cancelReply,
            replyToComment: replyToComment,
            editComment: editComment,
            deleteComment: deleteComment
        };
    }

    window.GalCommentCore = { create: create };
})();
