/**
 * discussion-comments.js —— 讨论详情页评论交互
 * 契约（与 api/comment.php 对齐，详见 docs/评论系统梳理.md）：
 *   content_type : 'discussion'
 *   端点         : POST /api/comment.php
 *   action       : create | edit | delete
 *   返回字段     : { success, message, code? }
 *   按钮绑定     : 自带绑定，并显式将 GalCommentInteractions.init 置空以避免双重委托
 *   导出全局     : openCommentModal/closeCommentModal/switchCommentEditorMode/submitComment/
 *                  discussionSubmitComment/cancelReply/replyToComment/editComment/
 *                  deleteComment(=handleDeleteComment=galDeleteComment 三别名)/deleteDiscussion
 */
(function() {
    'use strict';

    function byId(id) { return document.getElementById(id); }
    function getConfig() { return byId('view-counter-config'); }
    function getCsrf() { var el = getConfig(); return el ? (el.getAttribute('data-csrf') || '') : ''; }
    function getDiscussionId() { var el = getConfig(); return el ? parseInt(el.getAttribute('data-id') || '0', 10) : 0; }
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
        var msg = '[comment-debug][discussion] ' + args.map(function(v) {
            if (typeof v === 'string') return v;
            try { return JSON.stringify(v); } catch (e) { return String(v); }
        }).join(' ');
        appendDebugLine(msg);
        if (!isCommentDebugEnabled() || !window.console || !console.log) return;
        args.unshift('[comment-debug][discussion]');
        console.log.apply(console, args);
    }
    function debugWarn() {
        var args = Array.prototype.slice.call(arguments);
        var msg = '[comment-debug][discussion] WARN ' + args.map(function(v) {
            if (typeof v === 'string') return v;
            try { return JSON.stringify(v); } catch (e) { return String(v); }
        }).join(' ');
        appendDebugLine(msg);
        if (!isCommentDebugEnabled() || !window.console || !console.warn) return;
        args.unshift('[comment-debug][discussion]');
        console.warn.apply(console, args);
    }

    var state = {
        editor: null,
        preview: null,
        modalOverlay: null,
        submitButton: null,
        cancelReplyButton: null,
        replyIndicator: null,
        modalTitle: null,
        editorBody: null,
        csrfToken: getCsrf(),
        replyParentId: 0,
        editingCommentId: 0,
        mentionPopup: null,
        mentionItems: [],
        selectedMentionIndex: 0,
        mentionRange: null,
        mentionRequestToken: 0,
        orderedListHandledAt: 0,
        imageInput: null
    };

    function init() {
        ensureDebugPanel();
        debugLog('script loaded', { href: window.location.href });
        state.editor = byId('commentTextarea');
        state.preview = byId('commentPreview');
        state.modalOverlay = byId('commentModalOverlay');
        state.submitButton = byId('commentSubmitBtn');
        state.cancelReplyButton = byId('cancelReplyBtn');
        state.replyIndicator = byId('replyIndicator');
        state.modalTitle = byId('commentModalTitle');
        state.editorBody = byId('commentEditorBody');
        debugLog('init', {
            hasEditor: !!state.editor,
            hasPreview: !!state.preview,
            hasModal: !!state.modalOverlay,
            hasSubmit: !!state.submitButton,
            hasCancelReply: !!state.cancelReplyButton,
            hasReplyIndicator: !!state.replyIndicator,
            hasTitle: !!state.modalTitle,
            hasEditorBody: !!state.editorBody,
            script: 'discussion-comments.js'
        });

        bindGlobalHooks();
        bindCommentActions();

        if (!state.editor) {
            debugWarn('init aborted: comment textarea not found');
            return;
        }

        bindToolbar();
        bindEditor();
        bindModal();
        ensureMentionPopup();
        ensureImageInput();
        renderPreview();
    }

    function bindToolbar() {
        var toolbar = byId('commentToolbar');
        if (!toolbar) return;
        toolbar.addEventListener('click', function(e) {
            var button = e.target.closest('button[data-action]');
            if (!button) return;
            handleToolbarAction(button.getAttribute('data-action'));
            state.editor.focus();
        });
    }

    function bindEditor() {
        state.editor.addEventListener('input', function() {
            renderPreview();
            syncMentionAutocomplete();
        });
        state.editor.addEventListener('click', syncMentionAutocomplete);
        state.editor.addEventListener('keyup', onEditorKeyup);
        state.editor.addEventListener('keydown', onEditorKeydown);
        state.editor.addEventListener('beforeinput', function(e) {
            if (!e || e.inputType !== 'insertLineBreak') return;
            if (handleOrderedListContinue()) {
                state.orderedListHandledAt = Date.now();
                e.preventDefault();
            }
        });
        document.addEventListener('click', onDocumentClick);
        window.addEventListener('resize', positionMentionAutocomplete);
        window.addEventListener('scroll', positionMentionAutocomplete, true);
    }

    function bindModal() {
        if (!state.modalOverlay) {
            debugWarn('bindModal skipped: modal overlay missing');
            return;
        }
        state.modalOverlay.addEventListener('click', function(e) {
            if (e.target === state.modalOverlay) {
                debugLog('modal backdrop click -> close');
                closeCommentModal();
            }
        });
    }

    function bindCommentActions() {
        document.addEventListener('click', function(e) {
            var deleteBtn = e.target.closest('.comment-delete-btn');
            if (deleteBtn) {
                var id = parseInt(deleteBtn.getAttribute('data-comment-id') || '0', 10);
                e.preventDefault();
                e.stopPropagation();
                debugLog('delete button clicked', { id: id, hasDelete: typeof window.galDeleteComment === 'function', hasHandle: typeof window.handleDeleteComment === 'function' });
                if (id && typeof window.galDeleteComment === 'function') {
                    window.galDeleteComment(id);
                } else if (id && typeof window.handleDeleteComment === 'function') {
                    window.handleDeleteComment(id);
                }
                return;
            }
            var replyBtn = e.target.closest('.comment-reply-btn:not(.comment-edit-btn)');
            if (replyBtn && replyBtn.getAttribute('data-comment-id')) {
                debugLog('reply button clicked', { id: replyBtn.getAttribute('data-comment-id') });
                return;
            }
            var editBtn = e.target.closest('.comment-edit-btn');
            if (editBtn) {
                debugLog('edit button clicked', { id: editBtn.getAttribute('data-comment-id') });
            }
        }, true);

        document.querySelectorAll('.comment-delete-btn').forEach(function(btn) {
            btn.onclick = function(ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var id = parseInt(btn.getAttribute('data-comment-id') || '0', 10);
                if (id && typeof window.galDeleteComment === 'function') {
                    window.galDeleteComment(id);
                }
                return false;
            };
        });
    }

    function bindGlobalHooks() {
        window.openCommentModal = openCommentModal;
        window.closeCommentModal = closeCommentModal;
        window.switchCommentEditorMode = switchCommentEditorMode;
        window.discussionSubmitComment = discussionSubmitCommentImpl;
        window.submitComment = discussionSubmitCommentImpl;
        window.cancelReply = cancelReply;
        window.replyToComment = replyToComment;
        window.editComment = editComment;
        window.galDeleteComment = handleDeleteComment;
        window.handleDeleteComment = handleDeleteComment;
        window.deleteComment = handleDeleteComment;
        window.deleteDiscussion = deleteDiscussion;
        window.GalCommentBridge = {
            openCommentModal: openCommentModal,
            closeCommentModal: closeCommentModal,
            submitComment: discussionSubmitCommentImpl,
            replyToComment: replyToComment,
            editComment: editComment,
            deleteComment: handleDeleteComment,
            handleDeleteComment: handleDeleteComment,
            galDeleteComment: handleDeleteComment
        };
        debugLog('globals bound', {
            hasDiscussionSubmit: typeof window.discussionSubmitComment === 'function',
            hasSubmitComment: typeof window.submitComment === 'function',
            hasReplyToComment: typeof window.replyToComment === 'function',
            hasEditComment: typeof window.editComment === 'function',
            hasDeleteComment: typeof window.deleteComment === 'function'
        });
    }

    function switchCommentEditorMode(mode) {
        if (!state.editorBody) return;
        state.editorBody.className = 'md-editor-body mode-' + mode;
        document.querySelectorAll('#commentModalOverlay .md-mode-tab').forEach(function(tab) {
            tab.classList.remove('active');
            if (tab.getAttribute('data-mode') === mode) tab.classList.add('active');
        });
        if (mode === 'preview') renderPreview();
    }

    function openCommentModal() {
        debugLog('openCommentModal', { hasModal: !!state.modalOverlay, hasEditor: !!state.editor });
        if (!state.modalOverlay) return;
        state.modalOverlay.style.display = 'flex';
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
        if (state.replyIndicator) {
            state.replyIndicator.textContent = '';
            state.replyIndicator.style.display = 'none';
        }
        if (state.cancelReplyButton) state.cancelReplyButton.style.display = 'none';
    }

    function replyToComment(commentId, username) {
        debugLog('replyToComment', { commentId: commentId, username: username });
        state.editingCommentId = 0;
        state.replyParentId = commentId;
        setMode('create');
        if (state.replyIndicator) {
            state.replyIndicator.textContent = '回复 ' + username;
            state.replyIndicator.style.display = 'inline';
        }
        if (state.cancelReplyButton) state.cancelReplyButton.style.display = '';
        if (state.editor) state.editor.value = '';
        renderPreview();
        openCommentModal();
    }

    function editComment(commentId) {
        var item = byId('comment-' + commentId);
        debugLog('editComment', { commentId: commentId, hasItem: !!item, hasEditor: !!state.editor });
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
        var wrapMap = {
            bold: ['**', '**', '粗体文本'],
            italic: ['*', '*', '斜体文本'],
            strikethrough: ['~~', '~~', '删除线文本'],
            code: ['`', '`', '代码']
        };
        if (wrapMap[action]) { wrapSelection(wrapMap[action][0], wrapMap[action][1], wrapMap[action][2]); renderPreview(); return; }
        if (action === 'link') { var url = prompt('请输入链接地址:', 'https://'); if (!url) return; wrapSelection('[', '](' + url + ')', '链接文本'); renderPreview(); return; }
        if (action === 'image') { triggerImageUpload(); return; }
        if (action === 'mention') { var name = prompt('请输入要提及的站内用户名:', ''); if (name == null) return; insertAtCursor('@' + String(name).replace(/^@+/, '').trim() + ' '); renderPreview(); return; }
        if (action === 'codeblock') { insertAtCursor('\n```\n代码内容\n```\n'); renderPreview(); return; }
        if (action === 'ul') { insertAtLineStart('- '); renderPreview(); return; }
        if (action === 'ol') { insertAtLineStart('1. '); renderPreview(); return; }
        if (action === 'quote') { insertAtLineStart('> '); renderPreview(); return; }
    }

    function onEditorKeydown(e) {
        if (state.mentionPopup && state.mentionPopup.style.display === 'block') {
            if (e.key === 'ArrowDown') { e.preventDefault(); moveMentionSelection(1); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); moveMentionSelection(-1); return; }
            if (e.key === 'Enter') { e.preventDefault(); chooseMentionSelection(); return; }
            if (e.key === 'Escape') { e.preventDefault(); hideMentionAutocomplete(); return; }
        }
        if (e.key === 'Tab') { e.preventDefault(); insertAtCursor('    '); }
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
            if (Date.now() - state.orderedListHandledAt < 80) { e.preventDefault(); return; }
            if (handleOrderedListContinue()) { state.orderedListHandledAt = Date.now(); e.preventDefault(); }
        }
    }

    function onEditorKeyup(e) { if (!['ArrowUp','ArrowDown','Enter','Escape'].includes(e.key)) syncMentionAutocomplete(); }
    function onDocumentClick(e) { if (!state.mentionPopup) return; if (e.target === state.editor || state.mentionPopup.contains(e.target)) return; hideMentionAutocomplete(); }

    function ensureMentionPopup() {
        if (state.mentionPopup || !state.editor) return;
        var popup = document.createElement('div');
        popup.className = 'md-mention-popup';
        popup.style.display = 'none';
        popup.innerHTML = '<div class="md-mention-list"></div>';
        document.body.appendChild(popup);
        popup.addEventListener('mousedown', function(e) {
            var item = e.target.closest('.md-mention-item');
            if (!item) return;
            e.preventDefault();
            state.selectedMentionIndex = parseInt(item.getAttribute('data-index') || '0', 10) || 0;
            chooseMentionSelection();
        });
        state.mentionPopup = popup;
    }

    function syncMentionAutocomplete() {
        var info = getActiveMentionQuery();
        if (!info || !info.query) { hideMentionAutocomplete(); return; }
        state.mentionRange = info;
        fetchMentionSuggestions(info.query);
    }

    function getActiveMentionQuery() {
        var cursor = state.editor.selectionStart;
        if (cursor !== state.editor.selectionEnd) return null;
        var before = state.editor.value.slice(0, cursor);
        var match = before.match(/(^|[^\u4e00-\u9fa5A-Za-z0-9_])@([\u4e00-\u9fa5A-Za-z0-9_]*)$/);
        if (!match) return null;
        return { query: match[2], start: cursor - match[2].length - 1, end: cursor };
    }

    function fetchMentionSuggestions(query) {
        var token = ++state.mentionRequestToken;
        fetch('/api/mention.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (token !== state.mentionRequestToken) return;
                if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) { hideMentionAutocomplete(); return; }
                state.mentionItems = data.users;
                state.selectedMentionIndex = 0;
                renderMentionAutocomplete();
            })
            .catch(function() { if (token !== state.mentionRequestToken) return; hideMentionAutocomplete(); });
    }

    function renderMentionAutocomplete() {
        if (!state.mentionPopup || !state.mentionItems.length) { hideMentionAutocomplete(); return; }
        state.mentionPopup.querySelector('.md-mention-list').innerHTML = state.mentionItems.map(function(user, index) {
            return '<button type="button" class="md-mention-item' + (index === state.selectedMentionIndex ? ' is-active' : '') + '" data-index="' + index + '">@' + escapeHtml(user.username) + '</button>';
        }).join('');
        positionMentionAutocomplete();
        state.mentionPopup.style.display = 'block';
    }

    function positionMentionAutocomplete() {
        if (!state.mentionPopup || !state.editor) return;
        var rect = state.editor.getBoundingClientRect();
        state.mentionPopup.style.left = (window.scrollX + rect.left + 16) + 'px';
        state.mentionPopup.style.top = (window.scrollY + rect.bottom - 12) + 'px';
        state.mentionPopup.style.width = Math.min(rect.width - 32, 320) + 'px';
    }

    function moveMentionSelection(step) { if (!state.mentionItems.length) return; state.selectedMentionIndex = (state.selectedMentionIndex + step + state.mentionItems.length) % state.mentionItems.length; renderMentionAutocomplete(); }
    function chooseMentionSelection() { if (!state.mentionItems.length || !state.mentionRange) return; var selected = state.mentionItems[state.selectedMentionIndex]; if (!selected) return; var replacement = '@' + selected.username + ' '; state.editor.value = state.editor.value.slice(0, state.mentionRange.start) + replacement + state.editor.value.slice(state.mentionRange.end); state.editor.selectionStart = state.editor.selectionEnd = state.mentionRange.start + replacement.length; hideMentionAutocomplete(); state.editor.focus(); }
    function hideMentionAutocomplete() { if (state.mentionPopup) state.mentionPopup.style.display = 'none'; state.mentionItems = []; state.mentionRange = null; }

    function wrapSelection(before, after, fallbackText) { var s = state.editor.selectionStart, e = state.editor.selectionEnd; var sel = state.editor.value.substring(s, e); var ins = sel ? before + sel + after : before + fallbackText + after; state.editor.value = state.editor.value.substring(0, s) + ins + state.editor.value.substring(e); state.editor.selectionStart = s + before.length; state.editor.selectionEnd = s + before.length + (sel ? sel.length : fallbackText.length); }
    function insertAtCursor(text) { var s = state.editor.selectionStart; state.editor.value = state.editor.value.substring(0, s) + text + state.editor.value.substring(s); state.editor.selectionStart = state.editor.selectionEnd = s + text.length; }
    function insertAtLineStart(prefix) { var s = state.editor.selectionStart; var lineStart = state.editor.value.lastIndexOf('\n', s - 1) + 1; state.editor.value = state.editor.value.substring(0, lineStart) + prefix + state.editor.value.substring(lineStart); state.editor.selectionStart = state.editor.selectionEnd = s + prefix.length; }
    function handleOrderedListContinue() { return false; }

    function renderPreview() {
        if (!state.preview || typeof marked === 'undefined') return;
        var html = state.editor.value ? marked.parse(state.editor.value) : '<div class="md-preview-empty">预览区域</div>';
        state.preview.innerHTML = '<div class="markdown-body">' + html + '</div>';
    }

    function ensureImageInput() {
        if (state.imageInput) return state.imageInput;
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.multiple = true;
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function() {
            var files = Array.prototype.slice.call(input.files || []);
            if (files.length) {
                uploadCommentImages(files);
            }
            input.value = '';
        });
        state.imageInput = input;
        return input;
    }

    function triggerImageUpload() {
        var input = ensureImageInput();
        if (input) input.click();
    }

    function fileToDataUrl(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = function() { reject(new Error('读取图片失败')); };
            reader.readAsDataURL(file);
        });
    }

    function uploadCommentImages(files) {
        var list = Array.prototype.slice.call(files || []);
        var images = [];
        list.forEach(function(file) {
            if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
            if (file.size > 4 * 1024 * 1024) {
                alert('图片大小不能超过 4MB');
                return;
            }
            images.push(file);
        });
        if (!images.length) return;

        var uploadOne = function(file) {
            var formData = new FormData();
            formData.append('action', 'upload_image');
            formData.append('image', file);
            return fetch('/api/comment.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: state.csrfToken ? { 'X-CSRF-Token': state.csrfToken } : {},
                body: formData
            }).then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            }).then(function(data) {
                if (!data || !data.success || !data.url) {
                    throw new Error((data && data.message) ? data.message : '图片上传失败');
                }
                return data.url;
            });
        };

        Promise.all(images.map(uploadOne))
            .then(function(urls) {
                urls.forEach(function(url) {
                    insertAtCursor('![](' + url + ')\n');
                });
                renderPreview();
                state.editor.focus();
            })
            .catch(function(err) {
                alert(err && err.message ? err.message : '图片上传失败');
            });
    }

    function discussionSubmitCommentImpl() {
        if (window.__discussionSubmitting) {
            debugWarn('submit blocked: already submitting');
            return;
        }
        window.__discussionSubmitting = true;
        var rawContent = state.editor && state.editor.value || '';
        debugLog('submitComment start', {
            hasEditor: !!state.editor,
            hasSubmitButton: !!state.submitButton,
            editingCommentId: state.editingCommentId,
            replyParentId: state.replyParentId,
            contentLength: rawContent.length,
            contentType: 'discussion',
            discussionId: getDiscussionId()
        });
        if (!rawContent.trim()) { window.__discussionSubmitting = false; debugWarn('submitComment aborted: empty content'); alert('请输入评论内容'); return; }
        var btn = state.submitButton;
        if (btn) {
            btn.disabled = true;
            btn.textContent = state.editingCommentId ? '保存中...' : '提交中...';
        } else {
            debugWarn('submitComment: submit button missing');
        }

        var payload = state.editingCommentId
            ? { action: 'update', id: state.editingCommentId, content: rawContent }
            : { action: 'create', content_type: 'discussion', content_id: getDiscussionId(), content: rawContent, parent_id: state.replyParentId || null };
        var requestBody = Object.assign({ _csrf: state.csrfToken }, payload);

        debugLog('submitComment payload', requestBody);
        fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken }, body: JSON.stringify(requestBody) })
            .then(function(r) {
                debugLog('submitComment response status', r.status, r.ok);
                return r.json();
            })
            .then(function(data) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                }
                window.__discussionSubmitting = false;
                debugLog('submitComment response body', data);
                if (!data || !data.success) { alert((data && data.message) || '提交失败'); return; }
                window.location.assign(data.redirect_url || window.location.pathname + window.location.search);
            })
            .catch(function(err) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                }
                window.__discussionSubmitting = false;
                debugWarn('submitComment failed', err);
                alert('网络错误，请稍后重试');
            });
    }
    function handleDeleteComment(id) {
        var commentId = parseInt(id || 0, 10);
        if (!commentId) return;
        if (!window.confirm('确认删除这条评论吗？删除后无法恢复。')) return;
        var payload = {
            action: 'delete',
            id: commentId,
            _csrf: state.csrfToken
        };
        fetch('/api/comment.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken },
            body: JSON.stringify(payload)
        }).then(function(r) {
            return r.json().then(function(data) { return { status: r.status, data: data }; });
        }).then(function(result) {
            if (!result.data || !result.data.success) {
                alert((result.data && result.data.message) || '删除失败');
                return;
            }
            var el = byId('comment-' + commentId);
            if (el && el.parentNode) el.parentNode.removeChild(el);
        }).catch(function() {
            alert('网络错误，请稍后重试');
        });
    }
    function deleteDiscussion(id) {
        var discussionId = parseInt(id || 0, 10);
        if (!discussionId) return;
        if (!window.confirm('确认删除这个话题吗？删除后无法恢复。')) return;
        window.location.href = '/discussion_delete.php?id=' + discussionId;
    }

    window.GalCommentInteractions = window.GalCommentInteractions || {};
    window.GalCommentInteractions.init = function() {};

    function openCommentModal() { if (state.modalOverlay) state.modalOverlay.style.display = 'flex'; document.body.style.overflow = 'hidden'; if (state.editor) state.editor.focus(); }
    function closeCommentModal() { if (state.modalOverlay) state.modalOverlay.style.display = 'none'; document.body.style.overflow = ''; state.replyParentId = 0; state.editingCommentId = 0; setMode('create'); cancelReply(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
