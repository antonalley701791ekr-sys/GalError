/**
 * article-comments.js —— 文章详情页评论交互
 * 契约（与 api/comment.php 对齐，详见 docs/评论系统梳理.md）：
 *   content_type : 'article'
 *   端点         : POST /api/comment.php
 *   action       : create | edit | delete（图片上传额外返回 url + markdown）
 *   返回字段     : { success, message, code? }
 *   按钮绑定     : 自带事件委托（document click 代理 .comment-reply-btn/.comment-edit-btn/.comment-delete-btn/#commentSubmitBtn），不依赖 GalCommentInteractions
 *   导出全局     : openCommentModal/closeCommentModal/switchCommentEditorMode/
 *                  submitComment/cancelReply/replyToComment/editComment/deleteComment/deleteDiscussion
 */
(function() {
    'use strict';

    function byId(id) { return document.getElementById(id); }
    function getViewConfig() { return byId('view-counter-config'); }
    function getArticleId() { var el = getViewConfig(); return el ? parseInt(el.getAttribute('data-id') || '0', 10) : 0; }
    function getCsrfToken() { var el = getViewConfig(); return el ? (el.getAttribute('data-csrf') || '') : ''; }
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
        var msg = '[comment-debug][article] ' + args.map(function(v) {
            if (typeof v === 'string') return v;
            try { return JSON.stringify(v); } catch (e) { return String(v); }
        }).join(' ');
        appendDebugLine(msg);
        if (!isCommentDebugEnabled() || !window.console || !console.log) return;
        args.unshift('[comment-debug][article]');
        console.log.apply(console, args);
    }
    function debugWarn() {
        var args = Array.prototype.slice.call(arguments);
        var msg = '[comment-debug][article] WARN ' + args.map(function(v) {
            if (typeof v === 'string') return v;
            try { return JSON.stringify(v); } catch (e) { return String(v); }
        }).join(' ');
        appendDebugLine(msg);
        if (!isCommentDebugEnabled() || !window.console || !console.warn) return;
        args.unshift('[comment-debug][article]');
        console.warn.apply(console, args);
    }

    var state = {
        editor: null,
        previewPane: null,
        modalOverlay: null,
        submitButton: null,
        cancelReplyButton: null,
        replyIndicator: null,
        modalTitle: null,
        editorBody: null,
        csrfToken: getCsrfToken(),
        replyParentId: 0,
        editingCommentId: 0,
        mentionPopup: null,
        mentionItems: [],
        selectedMentionIndex: 0,
        mentionRange: null,
        mentionRequestToken: 0
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
        debugLog('init', {
            hasEditor: !!state.editor,
            hasPreview: !!state.previewPane,
            hasModal: !!state.modalOverlay,
            hasSubmit: !!state.submitButton,
            hasCancelReply: !!state.cancelReplyButton,
            hasReplyIndicator: !!state.replyIndicator,
            hasTitle: !!state.modalTitle,
            hasEditorBody: !!state.editorBody,
            script: 'article-comments.js'
        });

        bindGlobalHooks();

        if (!state.editor || !state.modalOverlay) {
            debugWarn('init pending: waiting for modal/editor to mount');
            window.setTimeout(function() {
                hydrateDomRefs();
                if (state.editor && state.modalOverlay) {
                    bindToolbar();
                    bindEditor();
                    bindModal();
                    bindToc();
                    ensureMentionPopup();
                    renderPreview();
                }
            }, 50);
            return;
        }

        bindToolbar();
        bindEditor();
        bindModal();
        bindToc();
        ensureMentionPopup();
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
        state.editor.addEventListener('input', renderPreview);
        state.editor.addEventListener('keydown', onEditorKeydown);
        state.editor.addEventListener('keyup', onEditorKeyup);
        state.editor.addEventListener('click', syncMentionAutocomplete);
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
        document.addEventListener('click', function(e) {
            var hit = e.target && e.target.closest ? e.target.closest('.comment-reply-btn, .comment-edit-btn, .comment-delete-btn, #commentSubmitBtn') : null;
            if (hit) {
                debugLog('document click hit', hit.className || hit.id || hit.tagName);
                if (hit.id === 'commentSubmitBtn') {
                    e.preventDefault();
                    submitComment();
                    return;
                }
                if (hit.classList.contains('comment-delete-btn')) {
                    e.preventDefault();
                    deleteComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0);
                    return;
                }
                if (hit.classList.contains('comment-edit-btn')) {
                    e.preventDefault();
                    editComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0);
                    return;
                }
                if (hit.classList.contains('comment-reply-btn')) {
                    e.preventDefault();
                    replyToComment(parseInt(hit.getAttribute('data-comment-id') || '0', 10) || 0, hit.getAttribute('data-comment-username') || '');
                    return;
                }
            }
            onDocumentClick(e);
        });
        window.addEventListener('resize', positionMentionPopup);
        window.addEventListener('scroll', positionMentionPopup, true);
    }

    function bindGlobalHooks() {
        window.openCommentModal = openCommentModal;
        window.closeCommentModal = closeCommentModal;
        window.switchCommentEditorMode = switchCommentEditorMode;
        window.submitComment = submitComment;
        window.cancelReply = cancelReply;
        window.deleteComment = deleteComment;
        window.deleteDiscussion = deleteDiscussion;
        window.replyToComment = replyToComment;
        window.editComment = editComment;
        window.GalCommentBridge = {
            openCommentModal: openCommentModal,
            closeCommentModal: closeCommentModal,
            submitComment: submitComment,
            replyToComment: replyToComment,
            editComment: editComment,
            deleteComment: deleteComment
        };
        debugLog('globals bound', {
            hasOpenCommentModal: typeof window.openCommentModal === 'function',
            hasSubmitComment: typeof window.submitComment === 'function',
            hasReplyToComment: typeof window.replyToComment === 'function',
            hasEditComment: typeof window.editComment === 'function',
            hasDeleteComment: typeof window.deleteComment === 'function'
        });
    }

    function bindToc() {
        var tocNav = byId('tocNav');
        if (!tocNav) return;
        tocNav.addEventListener('click', onTocClick);
        window.addEventListener('scroll', updateTocActive, { passive: true });
        updateTocActive();

        var tocToggle = byId('tocToggle');
        var tocCard = byId('tocCard');
        if (tocToggle && tocCard && window.innerWidth <= 768) {
            tocCard.classList.add('collapsed');
            tocToggle.addEventListener('click', function() { tocCard.classList.toggle('collapsed'); });
        }
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
        debugLog('replyToComment', { commentId: commentId, username: username });
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
        if (action === 'image') { uploadImage(); return; }
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
        input.addEventListener('change', function() {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (file.size > 2 * 1024 * 1024) { alert('图片大小不能超过 2MB'); return; }
            var formData = new FormData();
            formData.append('action', 'upload_image');
            formData.append('image', file);
            fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: state.csrfToken ? { 'X-CSRF-Token': state.csrfToken } : {}, body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) { if (!data || !data.success || !data.url) { alert((data && data.message) ? data.message : '图片上传失败'); return; } insertAtCursor('![](' + data.url + ')'); renderPreview(); })
                .catch(function() { alert('网络错误，图片上传失败'); });
        });
        input.click();
    }

    function submitComment() {
        var rawContent = state.editor && state.editor.value || '';
        debugLog('submitComment start', {
            hasEditor: !!state.editor,
            hasSubmitButton: !!state.submitButton,
            editingCommentId: state.editingCommentId,
            replyParentId: state.replyParentId,
            contentLength: rawContent.length,
            contentType: 'article',
            articleId: getArticleId()
        });
        if (!rawContent.trim()) { debugWarn('submitComment aborted: empty content'); alert('请输入评论内容'); return; }
        var btn = state.submitButton;
        if (!btn) {
            debugWarn('submitComment aborted: submit button missing');
            return;
        }
        btn.disabled = true;
        btn.textContent = state.editingCommentId ? '保存中...' : '提交中...';

        var payload = state.editingCommentId
            ? { action: 'update', id: state.editingCommentId, content: rawContent }
            : { action: 'create', content_type: 'article', content_id: getArticleId(), content: rawContent, parent_id: state.replyParentId || null };

        debugLog('submitComment payload', payload);
        var requestBody = Object.assign({ _csrf: state.csrfToken }, payload);
        fetch('/api/comment.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': state.csrfToken
            },
            body: JSON.stringify(requestBody)
        })
            .then(function(r) {
                debugLog('submitComment response status', r.status, r.ok);
                return r.json();
            })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                debugLog('submitComment response body', data);
                if (!data || !data.success) { alert((data && data.message) || '提交失败'); return; }
                if (state.editingCommentId && data.comment) {
                    var item = byId('comment-' + state.editingCommentId);
                    if (item) {
                        item.setAttribute('data-comment-content', data.comment.content || rawContent);
                        var contentEl = item.querySelector('.comment-main-content');
                        if (contentEl) contentEl.innerHTML = data.comment.content_html || '';
                    }
                } else {
                    window.location.assign(data.redirect_url || window.location.pathname + window.location.search);
                }
                closeCommentModal();
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = state.editingCommentId ? '保存修改' : '提交评论';
                debugWarn('submitComment failed', err);
                alert('网络错误，请稍后重试');
            });
    }

    function deleteComment(commentId) { if (!confirm('确定删除此评论？')) return; fetch('/api/comment.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken }, body: JSON.stringify({ _csrf: state.csrfToken, action: 'delete', id: commentId }) }).then(function(r) { return r.json(); }).then(function(data) { if (!data || !data.success) { alert((data && data.message) || '删除失败'); return; } var el = byId('comment-' + commentId); if (el) el.remove(); }).catch(function() { alert('网络错误'); }); }
    function deleteDiscussion(id) { if (!confirm('确定删除此话题？删除后不可恢复。')) return; fetch('/api/discussion.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: id }) }).then(function(r) { return r.json(); }).then(function(data) { if (data && data.success) window.location.href = '/discussions'; else alert((data && data.message) || '删除失败'); }).catch(function() { alert('网络错误'); }); }

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
        var info = getMentionQuery();
        if (!info || !info.query) { hideMentionPopup(); return; }
        state.mentionRange = info;
        fetchMentionSuggestions(info.query);
    }

    function getMentionQuery() {
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
                if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) { hideMentionPopup(); return; }
                state.mentionItems = data.users;
                state.selectedMentionIndex = 0;
                renderMentionPopup();
            })
            .catch(function() { if (token !== state.mentionRequestToken) return; hideMentionPopup(); });
    }

    function renderMentionPopup() {
        if (!state.mentionPopup || !state.mentionItems.length) { hideMentionPopup(); return; }
        state.mentionPopup.querySelector('.md-mention-list').innerHTML = state.mentionItems.map(function(user, index) {
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

    function normalizePlainImageUrls(text) { return String(text || '').replace(/(^|[\s>\(])((?:https?:)?\/\/[^\s<]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<]*)?(?:#[^\s<]*)?)(?=$|[\s<\)])/gi, function(match, prefix, url) { return prefix + '![](' + url + ')'; }); }
    function preserveExtraBlankLines(text) { return String(text || '').replace(/\n{3,}/g, function(match) { var extraCount = match.length - 2; var result = '\n\n'; for (var i = 0; i < extraCount; i++) result += '@@MD_EXTRA_BLANK_LINE@@\n'; return result; }); }
    function restoreExtraBlankLines(html) { var output = String(html || ''); output = output.replace(/<p>@@MD_EXTRA_BLANK_LINE@@<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>'); output = output.replace(/@@MD_EXTRA_BLANK_LINE@@<br\s*\/?\s*>/g, '<br>'); output = output.replace(/@@MD_EXTRA_BLANK_LINE@@/g, '<br>'); return output; }
    function escapeHtml(str) { var div = document.createElement('div'); div.textContent = str; return div.innerHTML; }

    function onTocClick(e) {
        var link = e.target.closest('a');
        if (!link) return;
        e.preventDefault();
        var targetId = link.getAttribute('href').substring(1);
        var target = document.getElementById(targetId);
        if (!target) return;
        var navHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 64;
        var top = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
        window.scrollTo({ top: top, behavior: 'smooth' });
    }

    function updateTocActive() {
        var tocLinks = document.querySelectorAll('#tocNav a');
        if (!tocLinks.length) return;
        var headings = [];
        tocLinks.forEach(function(link) {
            var id = link.getAttribute('href').substring(1);
            var el = document.getElementById(id);
            if (el) headings.push({ el: el, link: link });
        });
        if (!headings.length) return;
        var navHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 64;
        var scrollTop = window.pageYOffset;
        var threshold = navHeight + 40;
        var currentIndex = -1;
        for (var i = headings.length - 1; i >= 0; i--) {
            if (headings[i].el.getBoundingClientRect().top + window.pageYOffset - threshold <= scrollTop) { currentIndex = i; break; }
        }
        tocLinks.forEach(function(link) { link.classList.remove('active'); });
        if (currentIndex >= 0) {
            headings[currentIndex].link.classList.add('active');
            var tocCard = byId('tocCard');
            var activeLink = headings[currentIndex].link;
            if (tocCard && activeLink) {
                var linkTop = activeLink.offsetTop - tocCard.offsetTop;
                var cardScroll = tocCard.scrollTop;
                var cardHeight = tocCard.clientHeight;
                if (linkTop < cardScroll + 40 || linkTop > cardScroll + cardHeight - 40) tocCard.scrollTop = linkTop - cardHeight / 3;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() { init(); });
})();
