(function() {
    'use strict';

    var rejectOrderedListHandledAt = 0;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function preserveExtraBlankLinesForPreview(text) {
        var normalized = String(text || '').replace(/\r\n?/g, '\n');
        return normalized.replace(/\n{3,}/g, function(match) {
            var extraCount = match.length - 2;
            var result = '\n\n';
            for (var i = 0; i < extraCount; i++) {
                result += '<!--MD_EXTRA_BLANK_LINE-->\n';
            }
            return result;
        });
    }

    function restoreExtraBlankLinesPreviewHtml(html) {
        var out = String(html || '');
        out = out.replace(/<p>\s*&lt;!--MD_EXTRA_BLANK_LINE--&gt;\s*<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>');
        out = out.replace(/<p>\s*<!--MD_EXTRA_BLANK_LINE-->\s*<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>');
        out = out.replace(/&lt;!--MD_EXTRA_BLANK_LINE--&gt;<br\s*\/?\s*>/g, '<br>');
        out = out.replace(/<!--MD_EXTRA_BLANK_LINE--><br\s*\/?\s*>/g, '<br>');
        out = out.replace(/&lt;!--MD_EXTRA_BLANK_LINE--&gt;/g, '<br>');
        out = out.replace(/<!--MD_EXTRA_BLANK_LINE-->/g, '<br>');
        return out;
    }

    function renderRejectPreview() {
        var input = document.getElementById('rejectReasonInput');
        var preview = document.getElementById('rejectReasonPreview');
        if (!input || !preview) return;

        var text = input.value || '';
        if (!text.trim()) {
            preview.innerHTML = '<div class="md-preview-empty">暂无内容</div>';
            return;
        }
        if (typeof marked !== 'undefined') {
            var normalized = preserveExtraBlankLinesForPreview(text);
            var html = marked.parse(normalized, { breaks: true });
            preview.innerHTML = restoreExtraBlankLinesPreviewHtml(html);
        } else {
            preview.innerHTML = escapeHtml(text).replace(/\r\n?|\n/g, '<br>');
        }
    }

    function switchRejectEditorMode(mode) {
        var body = document.getElementById('rejectEditorBody');
        if (!body) return;
        var tabs = document.querySelectorAll('#rejectModal .md-mode-tab');
        tabs.forEach(function(tab) {
            tab.classList.toggle('active', tab.getAttribute('data-mode') === mode);
        });
        body.classList.remove('mode-edit', 'mode-preview');
        body.classList.add(mode === 'preview' ? 'mode-preview' : 'mode-edit');
        if (mode === 'preview') renderRejectPreview();
    }

    function rejectWrapSelection(before, after, defaultText) {
        var editor = document.getElementById('rejectReasonInput');
        if (!editor) return;
        var s = editor.selectionStart, e = editor.selectionEnd;
        var sel = editor.value.substring(s, e);
        if (sel) {
            editor.value = editor.value.substring(0, s) + before + sel + after + editor.value.substring(e);
            editor.selectionStart = s + before.length;
            editor.selectionEnd = e + before.length;
        } else {
            var ins = before + defaultText + after;
            editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e);
            editor.selectionStart = s + before.length;
            editor.selectionEnd = s + before.length + defaultText.length;
        }
        renderRejectPreview();
    }

    function rejectInsertAtLineStart(prefix) {
        var editor = document.getElementById('rejectReasonInput');
        if (!editor) return;
        var s = editor.selectionStart;
        var lineStart = editor.value.lastIndexOf('\n', s - 1) + 1;
        editor.value = editor.value.substring(0, lineStart) + prefix + editor.value.substring(lineStart);
        editor.selectionStart = editor.selectionEnd = s + prefix.length;
        renderRejectPreview();
    }

    function bindRejectToolbar() {
        var toolbar = document.getElementById('rejectToolbar');
        if (!toolbar || toolbar.dataset.bound === '1') return;
        toolbar.dataset.bound = '1';

        toolbar.addEventListener('click', function(e) {
            var btn = e.target.closest('button[data-action]');
            if (!btn) return;
            var action = btn.getAttribute('data-action');
            if (action === 'bold') rejectWrapSelection('**', '**', '粗体');
            if (action === 'italic') rejectWrapSelection('*', '*', '斜体');
            if (action === 'strikethrough') rejectWrapSelection('~~', '~~', '删除线');
            if (action === 'ul') rejectInsertAtLineStart('- ');
            if (action === 'ol') rejectInsertAtLineStart('1. ');
            if (action === 'quote') rejectInsertAtLineStart('> ');
            var input = document.getElementById('rejectReasonInput');
            if (input) input.focus();
        });
    }

    function openRejectModal(actionUrl) {
        var form = document.getElementById('rejectForm');
        var input = document.getElementById('rejectReasonInput');
        var err = document.getElementById('rejectReasonError');
        var modal = document.getElementById('rejectModal');
        if (!form || !input || !err || !modal) return;

        form.action = actionUrl;
        input.value = '';
        err.style.display = 'none';
        modal.style.display = 'flex';
        switchRejectEditorMode('edit');
        renderRejectPreview();
        setTimeout(function() { input.focus(); }, 100);
    }

    function closeRejectModal() {
        var modal = document.getElementById('rejectModal');
        if (modal) modal.style.display = 'none';
    }

    function bindRejectForm() {
        var form = document.getElementById('rejectForm');
        var input = document.getElementById('rejectReasonInput');
        var err = document.getElementById('rejectReasonError');
        if (!form || !input || !err || form.dataset.bound === '1') return;
        form.dataset.bound = '1';

        form.addEventListener('submit', function(e) {
            if (!input.value.trim()) {
                e.preventDefault();
                err.style.display = 'block';
                input.focus();
            }
        });

        input.addEventListener('beforeinput', function(e) {
            if (!e || e.inputType !== 'insertLineBreak') return;
            if (rejectHandleOrderedListContinue()) {
                rejectOrderedListHandledAt = Date.now();
                e.preventDefault();
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
            if (Date.now() - rejectOrderedListHandledAt < 80) {
                e.preventDefault();
                return;
            }
            if (rejectHandleOrderedListContinue()) {
                rejectOrderedListHandledAt = Date.now();
                e.preventDefault();
            }
        });

        input.addEventListener('keyup', function(e) {
            if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) return;
            if (Date.now() - rejectOrderedListHandledAt < 80) return;
            if (rejectHandleOrderedListContinue()) {
                rejectOrderedListHandledAt = Date.now();
            }
        });

        input.addEventListener('input', renderRejectPreview);
    }

    function rejectHandleOrderedListContinue() {
        var editor = document.getElementById('rejectReasonInput');
        if (!editor) return false;

        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        if (start !== end) return false;

        var text = editor.value;
        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = text.indexOf('\n', start);
        if (lineEnd === -1) lineEnd = text.length;

        var line = text.substring(lineStart, lineEnd);
        var match = line.match(/^(\s*)(\d+)\.\s*(.*)$/);
        if (!match) return false;

        var indent = match[1] || '';
        var number = parseInt(match[2], 10);
        if (!isFinite(number)) return false;
        var content = match[3] || '';

        if (!content.trim()) {
            var removeUntil = lineEnd;
            if (removeUntil < text.length && text.charAt(removeUntil) === '\n') {
                removeUntil += 1;
            }
            editor.value = text.substring(0, lineStart) + text.substring(removeUntil);
            editor.selectionStart = editor.selectionEnd = lineStart;
            renderRejectPreview();
            return true;
        }

        var contentStartInLine = match[0].length - content.length;
        var cursorInContent = start - (lineStart + contentStartInLine);
        if (cursorInContent < 0) cursorInContent = 0;
        if (cursorInContent > content.length) cursorInContent = content.length;

        var beforeContent = content.slice(0, cursorInContent);
        var afterContent = content.slice(cursorInContent);

        var currentPrefix = indent + number + '. ';
        var nextPrefix = indent + (number + 1) + '. ';

        var newCurrentLine = currentPrefix + beforeContent;
        var newNextLine = nextPrefix + afterContent.replace(/^\s+/, '');

        var replacement = newCurrentLine + '\n' + newNextLine;
        editor.value = text.substring(0, lineStart) + replacement + text.substring(lineEnd);

        var newPos = lineStart + newCurrentLine.length + 1 + nextPrefix.length;
        editor.selectionStart = editor.selectionEnd = newPos;
        renderRejectPreview();
        return true;
    }

    window.openRejectModal = openRejectModal;
    window.closeRejectModal = closeRejectModal;
    window.switchRejectEditorMode = switchRejectEditorMode;

    bindRejectToolbar();
    bindRejectForm();
})();
