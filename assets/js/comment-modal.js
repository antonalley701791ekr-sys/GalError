/**
 * comment-modal.js —— 报错详情页评论交互
 * 契约（与 api/comment.php 对齐，详见 docs/评论系统梳理.md）：
 *   content_type : 'error'（取自 window.GalCommentConfig.contentType）
 *   端点         : POST /api/comment.php
 *   action       : create | edit | delete（图片上传额外返回 url + markdown）
 *   返回字段     : { success, message, code? }
 *   依赖共享层   : GalCommentInteractions（按钮事件委托 + 锚点高亮 highlightCommentByHash）、comment-collapse
 *   导出全局     : openCommentModal/closeCommentModal/switchCommentEditorMode/
 *                  replyToComment/editComment/cancelReply/submitComment/deleteComment
 */
(function() {
    var config = window.GalCommentConfig || {};
    var CommentEditor = (function() {
        var editor = null;
        var preview = null;

        function init() {
            editor = document.getElementById('commentTextarea');
            preview = document.getElementById('commentPreview');
            var toolbar = document.getElementById('commentToolbar');
            if (!editor || !toolbar) return;
            toolbar.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                handleAction(btn.getAttribute('data-action'));
                editor.focus();
            });
        }

        function insertAtCursor(text) {
            if (!editor) return;
            var s = editor.selectionStart;
            editor.value = editor.value.substring(0, s) + text + editor.value.substring(editor.selectionEnd);
            editor.selectionStart = editor.selectionEnd = s + text.length;
        }

        function wrapSelection(before, after, fallback) {
            if (!editor) return;
            var s = editor.selectionStart;
            var e = editor.selectionEnd;
            var selected = editor.value.substring(s, e) || fallback;
            editor.value = editor.value.substring(0, s) + before + selected + after + editor.value.substring(e);
            editor.selectionStart = s + before.length;
            editor.selectionEnd = s + before.length + selected.length;
        }

        function handleAction(action) {
            if (action === 'bold') wrapSelection('**', '**', '粗体文本');
            else if (action === 'italic') wrapSelection('*', '*', '斜体文本');
            else if (action === 'strikethrough') wrapSelection('~~', '~~', '删除线文本');
            else if (action === 'code') wrapSelection('`', '`', '代码');
            else if (action === 'codeblock') insertAtCursor('```\n代码内容\n```\n');
            else if (action === 'ul') insertAtCursor('- ');
            else if (action === 'ol') insertAtCursor('1. ');
            else if (action === 'quote') insertAtCursor('> ');
            else if (action === 'mention') insertAtCursor('@');
            else if (action === 'link') {
                var url = prompt('请输入链接地址:', 'https://');
                if (url) wrapSelection('[', '](' + url + ')', '链接文本');
            } else if (action === 'image') {
                var input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
                input.addEventListener('change', function() {
                    if (!input.files || !input.files[0]) return;
                    var file = input.files[0];
                    if (file.size > 4 * 1024 * 1024) { alert('图片大小不能超过 4MB'); return; }
                    var formData = new FormData();
                    formData.append('action', 'upload_image');
                    formData.append('image', file);
                    fetch('/api/comment.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: (window.GalCommentConfig && window.GalCommentConfig.csrfToken) ? { 'X-CSRF-Token': window.GalCommentConfig.csrfToken } : {},
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.success || !data.url) { alert((data && data.message) ? data.message : '图片上传失败'); return; }
                        insertAtCursor('![](' + data.url + ')');
                        renderPreview();
                    })
                    .catch(function() { alert('网络错误，图片上传失败'); });
                });
                input.click();
            }
        }

        function renderPreview() {
            if (!preview || !editor) return;
            preview.innerHTML = (typeof marked !== 'undefined' && editor.value)
                ? '<div class="markdown-body">' + marked.parse(editor.value) + '</div>'
                : '<div class="md-preview-empty">预览区域</div>';
        }

        return {
            init: init,
            getValue: function() { return editor ? editor.value : ''; },
            setValue: function(v) { if (editor) editor.value = v || ''; },
            clear: function() { if (editor) editor.value = ''; if (preview) preview.innerHTML = ''; },
            renderPreview: renderPreview
        };
    })();

    window.switchCommentEditorMode = function(mode) {
        document.querySelectorAll('#commentModalOverlay .md-mode-tab').forEach(function(tab) {
            tab.classList.toggle('active', tab.getAttribute('data-mode') === mode);
        });
        var body = document.getElementById('commentEditorBody');
        if (body) body.className = 'md-editor-body mode-' + mode;
        if (mode === 'preview') CommentEditor.renderPreview();
    };

    window._replyParentId = 0;
    window._editingCommentId = 0;

    window.openCommentModal = function() {
        var overlay = document.getElementById('commentModalOverlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeCommentModal = function() {
        var overlay = document.getElementById('commentModalOverlay');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
        window._replyParentId = 0;
        window._editingCommentId = 0;
        activeCommentRequestKey = '';
        setCommentSubmitState(false);
        CommentEditor.clear();
        window.cancelReply();
    };

    window.cancelReply = function() {
        window._replyParentId = 0;
        var el = document.getElementById('replyIndicator');
        if (el) {
            el.style.display = 'none';
            el.textContent = '';
        }
        var btn = document.getElementById('cancelReplyBtn');
        if (btn) btn.style.display = 'none';
    };

    window.replyToComment = function(commentId, username) {
        window._editingCommentId = 0;
        window._replyParentId = commentId;
        CommentEditor.clear();
        var el = document.getElementById('replyIndicator');
        if (el) {
            el.textContent = '回复 ' + username;
            el.style.display = 'inline';
        }
        var btn = document.getElementById('cancelReplyBtn');
        if (btn) btn.style.display = '';
        window.openCommentModal();
    };

    window.editComment = function(commentId) {
        var item = document.getElementById('comment-' + commentId);
        if (!item) return;
        window._replyParentId = 0;
        window._editingCommentId = commentId;
        CommentEditor.setValue(item.getAttribute('data-comment-content') || '');
        window.openCommentModal();
    };

    var isSubmittingComment = false;
    var activeCommentRequestKey = '';

    function setCommentSubmitState(submitting) {
        var submitBtn = document.getElementById('commentSubmitBtn');
        isSubmittingComment = !!submitting;
        if (!submitBtn) return;
        submitBtn.disabled = !!submitting;
        submitBtn.textContent = submitting ? '提交中...' : '提交评论';
    }

    function createCommentRequestKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return 'cmt_' + window.crypto.randomUUID().replace(/-/g, '');
        }
        return 'cmt_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text == null ? '' : String(text)));
        return div.innerHTML;
    }

    function getCommentList() {
        return document.getElementById('commentList');
    }

    function getCommentCountEl() {
        return document.getElementById('commentCount');
    }

    function updateCommentCount(delta) {
        var countEl = getCommentCountEl();
        if (!countEl) return;
        var current = parseInt((countEl.textContent || '0').replace(/\D+/g, ''), 10);
        if (isNaN(current)) current = 0;
        countEl.textContent = String(Math.max(0, current + delta));
    }

    function removeCommentEmptyState() {
        var empty = document.getElementById('commentEmpty');
        if (empty && empty.parentNode) {
            empty.parentNode.removeChild(empty);
        }
    }

    function buildCommentHtml(comment, rawContent) {
        if (!comment || !comment.id) return '';
        var avatarHtml = comment.avatar_url
            ? '<img src="' + escapeHtml(comment.avatar_url) + '" class="comment-author-avatar" alt="">'
            : '<span class="comment-author-avatar fallback">' + escapeHtml((comment.username || '').slice(0, 1)) + '</span>';

        var replyMeta = '';
        var replyContext = '';
        if (comment.parent_id && comment.reply_to_username) {
            var replyUserId = parseInt(comment.reply_to_user_id, 10) || 0;
            replyMeta = '<span class="comment-reply-to">回复 <a href="/profile?user_id=' + replyUserId + '" class="comment-user-link">' + escapeHtml(comment.reply_to_username) + '</a></span>';
            if (comment.reply_to_content) {
                replyContext = '' +
                    '<div class="comment-reply-context">' +
                        '<div class="comment-reply-header">回复 <span class="comment-user-link">@' + escapeHtml(comment.reply_to_username) + '</span></div>' +
                        '<div class="comment-reply-quote">' + escapeHtml(comment.reply_to_content) + '</div>' +
                        '<div class="comment-reply-divider"></div>' +
                    '</div>';
            }
        }

        return '' +
            '<div class="comment-item" id="comment-' + parseInt(comment.id, 10) + '" data-comment-content="' + escapeHtml(rawContent) + '">' +
                '<div class="comment-author">' +
                    '<span class="comment-author-info">' +
                        avatarHtml +
                        '<strong><a href="/profile?user_id=' + (parseInt(comment.user_id, 10) || 0) + '" class="comment-user-link">' + escapeHtml(comment.username || '') + '</a></strong>' +
                        replyMeta +
                    '</span>' +
                    '<span class="comment-meta-right">' +
                        '<span class="comment-time">' + escapeHtml(comment.created_at || '') + '</span>' +
                        '<button class="comment-reply-btn" data-comment-id="' + parseInt(comment.id, 10) + '" data-comment-username="' + escapeHtml(comment.username || '') + '">回复</button>' +
                        '<button class="comment-reply-btn comment-edit-btn" data-comment-id="' + parseInt(comment.id, 10) + '">编辑</button>' +
                    '</span>' +
                '</div>' +
                '<div class="comment-body markdown-body">' +
                    replyContext +
                    '<div class="comment-main-content">' + (comment.content_html || '') + '</div>' +
                '</div>' +
            '</div>';
    }

    function insertCommentIntoList(comment, rawContent) {
        var list = getCommentList();
        if (!list || !comment || !comment.id) return false;
        removeCommentEmptyState();
        list.insertAdjacentHTML('beforeend', buildCommentHtml(comment, rawContent));
        return true;
    }

    function updateExistingComment(commentId, contentHtml, rawContent) {
        var item = document.getElementById('comment-' + commentId);
        if (!item) return false;
        item.setAttribute('data-comment-content', rawContent || '');
        var mainContent = item.querySelector('.comment-main-content');
        if (mainContent) {
            mainContent.innerHTML = contentHtml || '';
        }
        return true;
    }

    function finishCommentSubmit() {
        activeCommentRequestKey = '';
        setCommentSubmitState(false);
        window.closeCommentModal();
    }

    function goToComment(commentId, redirectUrl) {
        var hash = '#comment-' + commentId;
        var target = document.getElementById('comment-' + commentId);

        if (target) {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, document.title, window.location.pathname + window.location.search + hash);
            } else {
                window.location.hash = hash;
            }
            if (window.GalCommentInteractions && typeof window.GalCommentInteractions.highlightCommentByHash === 'function') {
                window.GalCommentInteractions.highlightCommentByHash(hash);
            } else {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (redirectUrl) {
            window.location.assign(redirectUrl);
            return;
        }

        window.location.reload();
    }

    window.submitComment = function() {
        if (isSubmittingComment) {
            return;
        }

        var content = CommentEditor.getValue();
        if (!content.trim()) {
            alert('请输入评论内容');
            return;
        }

        var payload;
        if (window._editingCommentId) {
            payload = { action: 'update', id: window._editingCommentId, content: content };
        } else {
            activeCommentRequestKey = createCommentRequestKey();
            payload = {
                action: 'create',
                content_type: config.contentType,
                content_id: config.contentId,
                content: content,
                parent_id: window._replyParentId || null,
                idempotency_key: activeCommentRequestKey
            };
        }

        setCommentSubmitState(true);

        fetch('/api/comment.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: (window.GalCommentConfig && window.GalCommentConfig.csrfToken) ? {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.GalCommentConfig.csrfToken
            } : { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function(data) {
                if (!data.success) {
                    setCommentSubmitState(false);
                    if (!window._editingCommentId) {
                        activeCommentRequestKey = '';
                    }
                    alert((typeof handleApiError === 'function' ? handleApiError(data, '提交失败', {
                        preventReloadWhenDirty: false
                    }) : (data.message || '提交失败')));
                    return;
                }

                if (window._editingCommentId) {
                    var updated = updateExistingComment(window._editingCommentId, data.comment && data.comment.content_html, content);
                    finishCommentSubmit();
                    if (updated) {
                        goToComment(window._editingCommentId, data.redirect_url || '');
                    } else {
                        window.location.reload();
                    }
                    return;
                }

                var inserted = insertCommentIntoList(data.comment, content);
                updateCommentCount(1);
                finishCommentSubmit();
                if (inserted) {
                    goToComment(data.comment && data.comment.id, data.redirect_url || '');
                } else {
                    window.location.assign(data.redirect_url || (window.location.pathname + window.location.search + (data.comment && data.comment.id ? '#comment-' + data.comment.id : '')));
                }
            })
            .catch(function(err) {
                setCommentSubmitState(false);
                if (!window._editingCommentId) {
                    activeCommentRequestKey = '';
                }
                alert('网络错误，请稍后重试（' + (err && err.message ? err.message : '未知错误') + '）');
            });
    };

    window.deleteComment = function(commentId) {
        if (!confirm('确定删除此评论？')) return;
        fetch('/api/comment.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: (window.GalCommentConfig && window.GalCommentConfig.csrfToken) ? {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.GalCommentConfig.csrfToken
            } : { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: commentId })
        })
            .then(function(r) { return r.json(); })
            .then(function(data) { if (data.success) location.reload(); else alert(data.message || '删除失败'); })
            .catch(function() { alert('网络错误'); });
    };

    document.addEventListener('DOMContentLoaded', function() {
        CommentEditor.init();
        if (window.GalCommentInteractions && typeof window.GalCommentInteractions.init === 'function') {
            window.GalCommentInteractions.init({ onReply: window.replyToComment, onEdit: window.editComment, onDelete: window.deleteComment });
        }
    });
})();
