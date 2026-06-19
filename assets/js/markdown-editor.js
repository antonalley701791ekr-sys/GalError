/**
 * Markdown 编辑器组件
 * 依赖：marked.js（需在此脚本之前加载）
 */
var MarkdownEditor = (function() {
    'use strict';

    var editor = null;  // textarea 元素
    var preview = null; // 预览面板元素
    var uploadUrl = ''; // 图片上传地址
    var previewImageTokenMap = Object.create(null);
    var debounceTimer = null;
    var draftKey = '';
    var draftStatusEl = null;
    var currentMode = 'edit';
    var mentionState = createMentionAutocompleteState();
    var textTableModalState = createTextTableModalState();
    var orderedListHandledAt = 0;

    /**
     * 初始化编辑器
     */
    function init(config) {
        editor = document.getElementById(config.textareaId);
        preview = document.getElementById(config.previewId);
        uploadUrl = config.imageUploadUrl || '';

        if (!editor || !preview) return;

        // 配置 marked
        if (typeof marked !== 'undefined') {
            var renderer = new marked.Renderer();
            renderer.link = function(token) {
                var href = token.href || '';
                var title = token.title || '';
                var text = token.text || href;
                var normalizedLink = splitUrlSuffix(href);
                href = normalizedLink.url || href;
                var embeddedImageUrl = extractEmbeddedImageUrl(href);
                if (embeddedImageUrl && isRenderableImageUrl(embeddedImageUrl)) {
                    href = normalizePreviewImageUrl(embeddedImageUrl);
                } else if (isRenderableImageUrl(href)) {
                    href = normalizePreviewImageUrl(href);
                }
                if (normalizedLink.suffix) {
                    text += escapeHtml(normalizedLink.suffix);
                }
                var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
                return '<a href="' + escapeHtml(href) + '"' + titleAttr + ' target="_blank" rel="noopener noreferrer">' + text + '</a>';
            };
            renderer.image = function(token) {
                var href = token.href || '';
                var title = token.title || '';
                var text = token.text || '';
                var normalizedImage = splitUrlSuffix(href);
                href = normalizedImage.url || href;
                var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
                var resolvedSrc = normalizePreviewImageUrl(href);
                return '<img src="' + escapeHtml(resolvedSrc) + '" alt="' + escapeHtml(text) + '"' + titleAttr + ' style="max-width:100%;">';
            };
            marked.setOptions({ breaks: true, renderer: renderer });
        }

        // 绑定工具栏按钮
        bindToolbar();

        // 绑定实时预览
        editor.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            clearTimeout(mentionState.inputTimer);
            mentionState.inputTimer = setTimeout(function() {
                syncMentionAutocomplete();
            }, 120);
            debounceTimer = setTimeout(renderPreview, 300);
        });

        editor.addEventListener('click', syncMentionAutocomplete);

        editor.addEventListener('beforeinput', function(e) {
            if (!e || e.inputType !== 'insertLineBreak') return;
            if (handleOrderedListContinue()) {
                orderedListHandledAt = Date.now();
                e.preventDefault();
            }
        });

        editor.addEventListener('keyup', function(e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Enter' || e.key === 'Escape') {
                return;
            }
            syncMentionAutocomplete();
        });

        // Tab 键支持
        editor.addEventListener('keydown', function(e) {
            if (mentionState.visible) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    moveMentionSelection(1);
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    moveMentionSelection(-1);
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    chooseMentionSelection();
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    hideMentionAutocomplete();
                    return;
                }
            }
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                if (Date.now() - orderedListHandledAt < 80) {
                    e.preventDefault();
                    return;
                }
                if (handleOrderedListContinue()) {
                    orderedListHandledAt = Date.now();
                    e.preventDefault();
                    return;
                }
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                if (e.shiftKey) {
                    removeIndent();
                } else {
                    insertAtCursor('    ');
                }
            }
        });

        // 图片上传
        var imgInput = document.getElementById('md-image-uploader');
        if (imgInput) {
            imgInput.addEventListener('change', handleImageUpload);
        }

        // 模式切换
        bindModeTabs();

        document.addEventListener('click', function(e) {
            if (!mentionState.popup) return;
            if (e.target === editor || editor.contains && editor.contains(e.target) || mentionState.popup.contains(e.target)) {
                return;
            }
            hideMentionAutocomplete();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && textTableModalState.overlay && textTableModalState.overlay.classList.contains('is-open')) {
                closeTextTableModal();
            }
        });
        window.addEventListener('resize', positionMentionAutocomplete);
        window.addEventListener('scroll', positionMentionAutocomplete, true);

        // 表单提交
        var form = editor.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                var hiddenInput = document.getElementById('contentInput');
                if (hiddenInput) {
                    hiddenInput.value = editor.value;
                }
            });
        }

        // 初始渲染预览
        renderPreview();
        ensureMentionAutocomplete();
        ensureTextTableModal();
    }

    function createMentionAutocompleteState() {
        return {
            popup: null,
            list: [],
            selectedIndex: 0,
            visible: false,
            activeRange: null,
            requestToken: 0,
            inputTimer: null
        };
    }

    function createTextTableModalState() {
        return {
            overlay: null,
            textarea: null,
            confirmBtn: null,
            closeBtn: null,
            cancelBtn: null,
            isBound: false
        };
    }

    function ensureMentionAutocomplete() {
        if (mentionState.popup || !editor) return;
        var popup = document.createElement('div');
        popup.className = 'md-mention-popup';
        popup.style.display = 'none';
        popup.innerHTML = '<div class="md-mention-list"></div>';
        document.body.appendChild(popup);
        popup.addEventListener('mousedown', function(e) {
            var item = e.target.closest('.md-mention-item');
            if (!item) return;
            e.preventDefault();
            mentionState.selectedIndex = parseInt(item.getAttribute('data-index') || '0', 10) || 0;
            chooseMentionSelection();
        });
        mentionState.popup = popup;
    }

    function syncMentionAutocomplete() {
        if (!editor) return;
        var mentionInfo = getActiveMentionQuery();
        if (!mentionInfo || !mentionInfo.query) {
            hideMentionAutocomplete();
            return;
        }
        mentionState.activeRange = mentionInfo;
        fetchMentionSuggestions(mentionInfo.query);
    }

    function getActiveMentionQuery() {
        var cursor = editor.selectionStart;
        if (cursor !== editor.selectionEnd) return null;
        var beforeCursor = editor.value.slice(0, cursor);
        var match = beforeCursor.match(/(^|[^\u4e00-\u9fa5A-Za-z0-9_])@([\u4e00-\u9fa5A-Za-z0-9_]*)$/);
        if (!match) return null;
        return {
            query: match[2],
            start: cursor - match[2].length - 1,
            end: cursor
        };
    }

    function fetchMentionSuggestions(query) {
        ensureMentionAutocomplete();
        var currentToken = ++mentionState.requestToken;
        fetch('/api/mention.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (currentToken !== mentionState.requestToken) return;
                if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) {
                    hideMentionAutocomplete();
                    return;
                }
                mentionState.list = data.users;
                mentionState.selectedIndex = 0;
                renderMentionAutocomplete();
            })
            .catch(function() {
                if (currentToken !== mentionState.requestToken) return;
                hideMentionAutocomplete();
            });
    }

    function renderMentionAutocomplete() {
        if (!mentionState.popup || !mentionState.list.length) {
            hideMentionAutocomplete();
            return;
        }
        var listHtml = mentionState.list.map(function(user, index) {
            return '<button type="button" class="md-mention-item' + (index === mentionState.selectedIndex ? ' is-active' : '') + '" data-index="' + index + '">@' + escapeHtml(user.username) + '</button>';
        }).join('');
        mentionState.popup.querySelector('.md-mention-list').innerHTML = listHtml;
        positionMentionAutocomplete();
        mentionState.popup.style.display = 'block';
        mentionState.visible = true;
    }

    function positionMentionAutocomplete() {
        if (!mentionState.popup || !editor) return;
        var rect = editor.getBoundingClientRect();
        mentionState.popup.style.left = (window.scrollX + rect.left + 16) + 'px';
        mentionState.popup.style.top = (window.scrollY + rect.bottom - 12) + 'px';
        mentionState.popup.style.width = Math.min(rect.width - 32, 320) + 'px';
    }

    function moveMentionSelection(step) {
        if (!mentionState.visible || !mentionState.list.length) return;
        var total = mentionState.list.length;
        mentionState.selectedIndex = (mentionState.selectedIndex + step + total) % total;
        renderMentionAutocomplete();
    }

    function chooseMentionSelection() {
        if (!mentionState.visible || !mentionState.list.length || !mentionState.activeRange) return;
        var selected = mentionState.list[mentionState.selectedIndex];
        if (!selected) return;
        var text = editor.value;
        var replacement = '@' + selected.username + ' ';
        editor.value = text.slice(0, mentionState.activeRange.start) + replacement + text.slice(mentionState.activeRange.end);
        var newPos = mentionState.activeRange.start + replacement.length;
        editor.selectionStart = editor.selectionEnd = newPos;
        hideMentionAutocomplete();
        triggerInput();
        editor.focus();
    }

    function hideMentionAutocomplete() {
        mentionState.visible = false;
        mentionState.list = [];
        mentionState.activeRange = null;
        if (mentionState.popup) {
            mentionState.popup.style.display = 'none';
        }
    }

    /**
     * 绑定工具栏按钮事件
     */
    function bindToolbar() {
        var toolbar = document.querySelector('.md-editor-toolbar');
        if (!toolbar) return;

        toolbar.addEventListener('click', function(e) {
            var btn = e.target.closest('button[data-action]');
            if (!btn) return;

            var action = btn.getAttribute('data-action');
            handleAction(action);
            editor.focus();
        });
    }

    /**
     * 处理工具栏按钮动作
     */
    function handleAction(action) {
        switch (action) {
            case 'h1': insertAtLineStart('# '); break;
            case 'h2': insertAtLineStart('## '); break;
            case 'h3': insertAtLineStart('### '); break;
            case 'bold': wrapSelection('**', '**', '粗体文本'); break;
            case 'italic': wrapSelection('*', '*', '斜体文本'); break;
            case 'strikethrough': wrapSelection('~~', '~~', '删除线文本'); break;
            case 'link': insertLinkAction(); break;
            case 'mention': insertMentionAction(); break;
            case 'image': triggerImageUpload(); break;
            case 'code': wrapSelection('`', '`', '代码'); break;
            case 'codeblock': insertCodeBlock(); break;
            case 'ul': insertAtLineStart('- '); break;
            case 'ol': insertOrderedList(); break;
            case 'quote': insertAtLineStart('> '); break;
            case 'hr': insertHorizontalRule(); break;
            case 'table': insertTable(); break;
            case 'tsvtable': insertTableFromDelimitedText(); break;
        }
    }

    function insertMentionAction() {
        var username = prompt('请输入要提及的站内用户名:', '');
        if (username === null) return;
        username = String(username).trim().replace(/^@+/, '');
        if (!username) return;
        insertAtCursor('@' + username + ' ');
    }

    /**
     * 在选区前后包裹字符
     */
    function wrapSelection(before, after, defaultText) {
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        var text = editor.value;
        var selected = text.substring(start, end);

        if (selected) {
            // 检查是否已经被包裹，若是则取消
            var beforeMatch = text.substring(start - before.length, start) === before;
            var afterMatch = text.substring(end, end + after.length) === after;
            if (beforeMatch && afterMatch) {
                editor.value = text.substring(0, start - before.length) + selected + text.substring(end + after.length);
                editor.selectionStart = start - before.length;
                editor.selectionEnd = end - before.length;
            } else {
                var replacement = before + selected + after;
                editor.value = text.substring(0, start) + replacement + text.substring(end);
                editor.selectionStart = start + before.length;
                editor.selectionEnd = end + before.length;
            }
        } else {
            var insert = before + defaultText + after;
            editor.value = text.substring(0, start) + insert + text.substring(end);
            editor.selectionStart = start + before.length;
            editor.selectionEnd = start + before.length + defaultText.length;
        }

        triggerInput();
    }

    /**
     * 在当前行首插入/切换前缀
     */
    function insertAtLineStart(prefix) {
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        var text = editor.value;

        // 找到选区覆盖的所有行
        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = text.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = text.length;

        var lines = text.substring(lineStart, lineEnd).split('\n');
        var allHavePrefix = lines.every(function(line) {
            return line.indexOf(prefix) === 0;
        });

        var newLines;
        var lengthDiff;
        if (allHavePrefix) {
            // 移除前缀
            newLines = lines.map(function(line) {
                return line.substring(prefix.length);
            });
            lengthDiff = -prefix.length * lines.length;
        } else {
            // 添加前缀
            newLines = lines.map(function(line) {
                return prefix + line;
            });
            lengthDiff = prefix.length * lines.length;
        }

        var replacement = newLines.join('\n');
        editor.value = text.substring(0, lineStart) + replacement + text.substring(lineEnd);

        // 调整选区
        if (start === end) {
            // 光标模式
            var newPos = start + (allHavePrefix ? -prefix.length : prefix.length);
            editor.selectionStart = editor.selectionEnd = Math.max(lineStart, newPos);
        } else {
            editor.selectionStart = lineStart;
            editor.selectionEnd = lineStart + replacement.length;
        }

        triggerInput();
    }

    /**
     * 插入链接
     */
    function insertLinkAction() {
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        var text = editor.value;
        var selected = text.substring(start, end);

        var url = prompt('请输入链接地址:', 'https://');
        if (!url) return;

        var insert;
        if (selected) {
            // 选中了文本：用选中文本作为链接文字
            insert = '[' + selected + '](' + url + ')';
            editor.value = text.substring(0, start) + insert + text.substring(end);
            editor.selectionStart = start;
            editor.selectionEnd = start + insert.length;
        } else {
            // 未选中文本：直接插入链接地址
            insert = url;
            editor.value = text.substring(0, start) + insert + text.substring(end);
            editor.selectionStart = editor.selectionEnd = start + insert.length;
        }

        triggerInput();
    }

    /**
     * 插入代码块
     */
    function insertCodeBlock() {
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        var text = editor.value;
        var selected = text.substring(start, end) || '代码内容';

        var needNewlineBefore = start > 0 && text[start - 1] !== '\n' ? '\n' : '';
        var insert = needNewlineBefore + '```\n' + selected + '\n```\n';
        editor.value = text.substring(0, start) + insert + text.substring(end);

        var codeStart = start + needNewlineBefore.length + 4; // after ```\n
        editor.selectionStart = codeStart;
        editor.selectionEnd = codeStart + selected.length;

        triggerInput();
    }

    /**
     * 插入有序列表
     */
    function insertOrderedList() {
        var start = editor.selectionStart;
        var end = editor.selectionEnd;
        var text = editor.value;

        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = text.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = text.length;

        var lines = text.substring(lineStart, lineEnd).split('\n');
        var allOrdered = lines.every(function(line) {
            return /^\d+\.\s/.test(line);
        });

        var newLines;
        if (allOrdered) {
            newLines = lines.map(function(line) {
                return line.replace(/^\d+\.\s/, '');
            });
        } else {
            newLines = lines.map(function(line, i) {
                return (i + 1) + '. ' + line;
            });
        }

        var replacement = newLines.join('\n');
        editor.value = text.substring(0, lineStart) + replacement + text.substring(lineEnd);
        editor.selectionStart = lineStart;
        editor.selectionEnd = lineStart + replacement.length;

        triggerInput();
    }

    /**
     * 插入水平线
     */
    function insertHorizontalRule() {
        var start = editor.selectionStart;
        var text = editor.value;

        var needNewlineBefore = start > 0 && text[start - 1] !== '\n' ? '\n' : '';
        var insert = needNewlineBefore + '\n---\n\n';
        editor.value = text.substring(0, start) + insert + text.substring(start);

        var newPos = start + insert.length;
        editor.selectionStart = editor.selectionEnd = newPos;

        triggerInput();
    }

    /**
     * 插入表格模板
     */
    function insertTable() {
        var start = editor.selectionStart;
        var text = editor.value;

        var colsInput = prompt('请输入表格列数（1-20）:', '3');
        if (colsInput === null) return;
        var rowsInput = prompt('请输入数据行数（1-50，不含表头）:', '2');
        if (rowsInput === null) return;

        var cols = clampInt(colsInput, 1, 20, 3);
        var rows = clampInt(rowsInput, 1, 50, 2);

        var headerCells = [];
        var separatorCells = [];
        for (var c = 1; c <= cols; c++) {
            headerCells.push('标题' + c);
            separatorCells.push('---');
        }

        var lines = [];
        lines.push('| ' + headerCells.join(' | ') + ' |');
        lines.push('| ' + separatorCells.join(' | ') + ' |');

        for (var r = 0; r < rows; r++) {
            var dataCells = [];
            for (var i = 0; i < cols; i++) {
                dataCells.push('内容');
            }
            lines.push('| ' + dataCells.join(' | ') + ' |');
        }

        var needNewlineBefore = start > 0 && text[start - 1] !== '\n' ? '\n' : '';
        var table = needNewlineBefore + lines.join('\n') + '\n';

        editor.value = text.substring(0, start) + table + text.substring(start);

        var firstHeaderPos = start + needNewlineBefore.length + 2;
        var firstHeaderText = headerCells[0] || '标题1';
        editor.selectionStart = firstHeaderPos;
        editor.selectionEnd = firstHeaderPos + firstHeaderText.length;

        triggerInput();
    }

    /**
     * 从制表符/分隔文本快速插入表格
     */
    function insertTableFromDelimitedText() {
        ensureTextTableModal();
        if (!textTableModalState.overlay || !textTableModalState.textarea) return;

        var sample = [
            '列1\t列2\t列3',
            '值A\t值B\t值C'
        ].join('\n');

        textTableModalState.textarea.value = sample;
        textTableModalState.overlay.classList.add('is-open');
        textTableModalState.overlay.setAttribute('aria-hidden', 'false');
        setTimeout(function() {
            textTableModalState.textarea.focus();
            textTableModalState.textarea.select();
        }, 0);
    }

    function ensureTextTableModal() {
        if (textTableModalState.overlay) return;

        var overlay = document.createElement('div');
        overlay.className = 'md-text-table-modal-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML =
            '<div class="md-text-table-modal" role="dialog" aria-modal="true" aria-label="文本转表格">' +
                '<div class="md-text-table-modal-header">' +
                    '<h3>文本转表格</h3>' +
                    '<button type="button" class="md-text-table-close" data-close="1" aria-label="关闭">×</button>' +
                '</div>' +
                '<div class="md-text-table-modal-body">' +
                    '<p class="md-text-table-tip">请粘贴 Tab 分列文本，每行一条记录。</p>' +
                    '<textarea class="md-text-table-input" placeholder="示例：\n列1\t列2\t列3\n值A\t值B\t值C"></textarea>' +
                    '<div class="md-text-table-example">示例：<code>列1\t列2\t列3</code><br><code>值A\t值B\t值C</code></div>' +
                '</div>' +
                '<div class="md-text-table-modal-footer">' +
                    '<button type="button" class="btn btn-secondary" data-close="1">关闭</button>' +
                    '<button type="button" class="btn" data-confirm="1">确定</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);
        textTableModalState.overlay = overlay;
        textTableModalState.textarea = overlay.querySelector('.md-text-table-input');
        textTableModalState.confirmBtn = overlay.querySelector('[data-confirm="1"]');
        textTableModalState.closeBtn = overlay.querySelector('.md-text-table-close');
        textTableModalState.cancelBtn = overlay.querySelector('.btn.btn-secondary[data-close="1"]');

        if (!textTableModalState.isBound) {
            overlay.addEventListener('click', function(e) {
                var target = e.target;
                var closeTrigger = target && target.closest ? target.closest('[data-close="1"]') : null;
                if (closeTrigger) {
                    closeTextTableModal();
                }
            });

            textTableModalState.confirmBtn.addEventListener('click', function() {
                applyTextTableConversion();
            });

            textTableModalState.isBound = true;
        }
    }

    function closeTextTableModal() {
        if (!textTableModalState.overlay) return;
        textTableModalState.overlay.classList.remove('is-open');
        textTableModalState.overlay.setAttribute('aria-hidden', 'true');
        editor.focus();
    }

    function applyTextTableConversion() {
        if (!textTableModalState.textarea) return;

        var raw = textTableModalState.textarea.value;
        var lines = String(raw || '')
            .split(/\r\n|\r|\n/)
            .map(function(line) { return line.trim(); })
            .filter(function(line) { return line.length > 0; });

        if (!lines.length) {
            alert('请输入要转换的文本');
            return;
        }

        var rows = lines.map(function(line) {
            var cells;
            if (line.indexOf('\t') !== -1) {
                cells = line.split('\t');
            } else {
                cells = line.split(/\s{2,}/);
                if (cells.length <= 1) {
                    cells = line.split(/\s+/);
                }
            }
            return cells.map(function(cell) { return String(cell || '').trim(); });
        });

        var colCount = rows.reduce(function(max, row) {
            return Math.max(max, row.length);
        }, 0);

        if (colCount <= 0) {
            alert('未检测到有效列');
            return;
        }

        rows = rows.map(function(row) {
            while (row.length < colCount) row.push('');
            return row.slice(0, colCount);
        });

        var header = rows[0];
        var bodyRows = rows.slice(1);
        var sep = [];
        for (var i = 0; i < colCount; i++) sep.push('---');

        var mdLines = [];
        mdLines.push('| ' + header.join(' | ') + ' |');
        mdLines.push('| ' + sep.join(' | ') + ' |');
        bodyRows.forEach(function(row) {
            mdLines.push('| ' + row.join(' | ') + ' |');
        });

        var start = editor.selectionStart;
        var text = editor.value;
        var needNewlineBefore = start > 0 && text[start - 1] !== '\n' ? '\n' : '';
        var table = needNewlineBefore + mdLines.join('\n') + '\n';

        editor.value = text.substring(0, start) + table + text.substring(start);
        editor.selectionStart = editor.selectionEnd = start + table.length;

        closeTextTableModal();
        triggerInput();
    }

    function clampInt(raw, min, max, fallback) {
        var num = parseInt(String(raw || '').trim(), 10);
        if (!isFinite(num)) return fallback;
        if (num < min) return min;
        if (num > max) return max;
        return num;
    }

    /**
     * 触发图片上传
     */
    function triggerImageUpload() {
        var input = document.getElementById('md-image-uploader');
        if (input) input.click();
    }

    /**
     * 处理图片上传
     */
    function handleImageUpload() {
        var file = this.files[0];
        if (!file) return;

        var start = editor.selectionStart;
        var text = editor.value;
        var placeholderToken = 'uploading-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        var placeholder = '![上传中...](' + placeholderToken + ')';

        previewImageTokenMap[placeholderToken] = URL.createObjectURL(file);

        // 插入占位符
        editor.value = text.substring(0, start) + placeholder + text.substring(start);
        triggerInput();

        var formData = new FormData();
        formData.append('image', file);
        formData.append('action', 'upload_image');

        fetch(uploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(r) {
            return r.json().catch(function() {
                throw new Error('invalid_json');
            });
        })
        .then(function(data) {
            var currentText = editor.value;
            releasePreviewImageToken(placeholderToken);
            if (data.success && data.url) {
                var imgMd = '![图片](' + data.url + ')';
                editor.value = currentText.replace(placeholder, imgMd);
            } else {
                editor.value = currentText.replace(placeholder, '');
                alert(handleApiError(data, '上传失败'));
            }
            triggerInput();
        })
        .catch(function() {
            releasePreviewImageToken(placeholderToken);
            editor.value = editor.value.replace(placeholder, '');
            triggerInput();
            alert('上传失败');
        });

        this.value = '';
    }

    /**
     * 在光标处插入文本
     */
    function insertAtCursor(text) {
        var start = editor.selectionStart;
        var val = editor.value;
        editor.value = val.substring(0, start) + text + val.substring(start);
        editor.selectionStart = editor.selectionEnd = start + text.length;
        triggerInput();
    }

    /**
     * 移除缩进（Shift+Tab）
     */
    function removeIndent() {
        var start = editor.selectionStart;
        var text = editor.value;
        var lineStart = text.lastIndexOf('\n', start - 1) + 1;
        var lineText = text.substring(lineStart, start);

        if (lineText.indexOf('    ') === 0) {
            editor.value = text.substring(0, lineStart) + text.substring(lineStart + 4);
            editor.selectionStart = editor.selectionEnd = start - 4;
        } else if (lineText.indexOf('\t') === 0) {
            editor.value = text.substring(0, lineStart) + text.substring(lineStart + 1);
            editor.selectionStart = editor.selectionEnd = start - 1;
        }

        triggerInput();
    }

    /**
     * 有序列表回车自动续号
     */
    function handleOrderedListContinue() {
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
            triggerInput();
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
        triggerInput();
        return true;
    }

    function normalizeCurrentTableAtCursor() {
        var start = editor.selectionStart;
        var before = editor.value.slice(0, start);
        var marker = '__MD_TABLE_FIX_CURSOR__';
        var withMarker = before + marker + editor.value.slice(start);
        var normalized = normalizeMarkdownTables(withMarker);
        var markerPos = normalized.indexOf(marker);

        if (markerPos === -1) {
            editor.value = normalizeMarkdownTables(editor.value);
            editor.selectionStart = editor.selectionEnd = Math.min(start, editor.value.length);
        } else {
            editor.value = normalized.replace(marker, '');
            editor.selectionStart = editor.selectionEnd = markerPos;
        }

        triggerInput();
    }

    function preserveExtraBlankLines(text) {
        var source = String(text || '');
        if (!source) return '';

        var lines = source.split(/\r\n|\r|\n/);
        var out = [];
        var blankCount = 0;
        var inFence = false;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var trimmed = String(line || '').trim();

            if (/^(```|~~~)/.test(trimmed)) {
                inFence = !inFence;
                blankCount = 0;
                out.push(line);
                continue;
            }

            if (inFence) {
                out.push(line);
                continue;
            }

            if (!trimmed) {
                blankCount++;
                out.push(blankCount === 1 ? '' : '\u00A0');
                continue;
            }

            blankCount = 0;
            out.push(line);
        }

        return out.join('\n');
    }

    function restoreExtraBlankLinesHtml(html) {
        return String(html || '');
    }

    function normalizeMarkdownTables(text) {
        var source = String(text || '');
        if (!source) return '';

        var lines = source.split(/\r\n|\r|\n/);
        var out = [];

        function isTableRow(line) {
            var v = String(line || '').trim();
            return v && (v.match(/\|/g) || []).length >= 2;
        }

        function splitCells(line) {
            var v = String(line || '').trim();
            v = v.replace(/^\|\s*/, '').replace(/\s*\|$/, '');
            if (!v) return [];
            return v.split(/\s*\|\s*/).map(function(cell) { return cell.trim(); });
        }

        function isSeparatorLine(line) {
            if (!isTableRow(line)) return false;
            var cells = splitCells(line);
            if (!cells.length) return false;
            return cells.some(function(cell) {
                return /^:?-{3,}:?$/.test(cell);
            });
        }

        function padCells(cells, target, fallback) {
            var arr = cells.slice(0, target);
            while (arr.length < target) arr.push(fallback || '');
            return arr;
        }

        function buildRow(cells) {
            return '| ' + cells.join(' | ') + ' |';
        }

        for (var i = 0; i < lines.length;) {
            var header = lines[i];
            var sep = i + 1 < lines.length ? lines[i + 1] : '';

            if (!isTableRow(header) || !isSeparatorLine(sep)) {
                out.push(header);
                i++;
                continue;
            }

            var headerCells = splitCells(header);
            var sepCells = splitCells(sep);
            var cols = Math.max(headerCells.length, sepCells.length);

            if (!cols) {
                out.push(header);
                i++;
                continue;
            }

            out.push(buildRow(padCells(headerCells, cols, '')));
            out.push(buildRow(padCells(sepCells, cols, '---').map(function(cell) {
                return /^:?-{3,}:?$/.test(cell) ? cell : '---';
            })));

            var j = i + 2;
            while (j < lines.length && isTableRow(lines[j])) {
                out.push(buildRow(padCells(splitCells(lines[j]), cols, '')));
                j++;
            }

            i = j;
        }

        return out.join('\n');
    }

    /**
     * 渲染预览
     */
    function renderPreview() {
        if (!preview || typeof marked === 'undefined') return;

        var source = preserveExtraBlankLines(editor.value);
        var normalized = normalizeMarkdownTables(source);
        var text = normalizePlainImageUrls(normalized);
        if (text) {
            var html = marked.parse(text);
            preview.innerHTML = '<div class="markdown-body">' + restoreExtraBlankLinesHtml(html) + '</div>';
        } else {
            preview.innerHTML = '<div class="md-preview-empty">预览区域（输入 Markdown 内容后自动渲染）</div>';
        }
    }

    function decodeUrlIfNeeded(value) {
        var url = String(value || '').trim();
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        if (/^https?%3A\/\//i.test(url)) {
            try {
                var decoded = decodeURIComponent(url);
                if (/^https?:\/\//i.test(decoded)) {
                    return decoded;
                }
            } catch (e) {}
        }
        return url;
    }

    function normalizePreviewImageUrl(href) {
        var normalizedImage = splitUrlSuffix(href);
        var value = decodeUrlIfNeeded(normalizedImage.url || '');
        if (!value) return '';

        if (previewImageTokenMap[value]) {
            return previewImageTokenMap[value];
        }

        if (/^(?:https?:)?\/\//i.test(value)) {
            if (value.indexOf('//') === 0) {
                value = window.location.protocol + value;
            }
            return '/image_proxy.php?url=' + encodeURIComponent(value);
        }

        return value;
    }

    function trimTrailingUrlPunctuation(value) {
        var url = String(value || '');
        var suffix = '';

        while (url) {
            var lastChar = url.slice(-1);
            if (!/[.,!?:;，。！？；：、]/.test(lastChar)) {
                break;
            }
            suffix = lastChar + suffix;
            url = url.slice(0, -1);
        }

        return { url: url, suffix: suffix };
    }

    function extractEmbeddedImageUrl(value) {
        var url = String(value || '').trim();
        if (!url) return '';

        var match = url.match(/!\[[^\]]*\]\(([^)]+)\)/);
        if (match && match[1]) {
            return decodeUrlIfNeeded(match[1]);
        }

        return '';
    }

    function splitUrlSuffix(value) {
        var result = trimTrailingUrlPunctuation(decodeUrlIfNeeded(value));
        var url = result.url;
        var suffix = result.suffix;

        while (url) {
            var lastChar = url.slice(-1);
            if (lastChar !== ')' && lastChar !== ']' && lastChar !== '}') {
                break;
            }

            var openingChar = lastChar === ')' ? '(' : (lastChar === ']' ? '[' : '{');
            var openingCount = (url.match(new RegExp('\\' + openingChar, 'g')) || []).length;
            var closingCount = (url.match(new RegExp('\\' + lastChar, 'g')) || []).length;
            if (openingCount >= closingCount) {
                break;
            }

            suffix = lastChar + suffix;
            url = url.slice(0, -1);
        }

        return { url: url, suffix: suffix };
    }

    function isRenderableImageUrl(url) {
        var value = decodeUrlIfNeeded(url);
        return /^(?:https?:)?\/\/[^\s]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s]*)?(?:#[^\s]*)?$/i.test(String(value || '').trim());
    }

    function normalizePlainImageUrls(text) {
        return String(text || '').replace(
            /(^|[\s>\(\[\{])((?:https?:\/\/|https?%3A\/\/)[^\s<]+?)(?=$|[\s<\)\]\}>"'，。！？；：、]|[\u4E00-\u9FFF])/gi,
            function(match, prefix, rawUrl) {
                var normalized = splitUrlSuffix(rawUrl);
                if (!normalized.url || !isRenderableImageUrl(normalized.url)) {
                    return match;
                }
                return prefix + '![](' + decodeUrlIfNeeded(normalized.url) + ')' + normalized.suffix;
            }
        );
    }

    function releasePreviewImageToken(token) {
        if (previewImageTokenMap[token]) {
            URL.revokeObjectURL(previewImageTokenMap[token]);
            delete previewImageTokenMap[token];
        }
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[ch];
        });
    }

    /**
     * 手动触发 input 事件以更新预览
     */
    function triggerInput() {
        editor.dispatchEvent(new Event('input'));
    }

    /**
     * 绑定模式切换 tab
     */
    function bindModeTabs() {
        var tabs = document.querySelectorAll('.md-mode-tab');
        var body = document.querySelector('.md-editor-body');
        if (!tabs.length || !body) return;

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var mode = this.getAttribute('data-mode');

                // 更新 tab 选中状态
                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

                // 切换模式
                body.className = 'md-editor-body mode-' + mode;

                // 切换到预览或分栏时渲染
                if (mode === 'preview' || mode === 'split') {
                    renderPreview();
                }

                // 切回编辑模式时聚焦
                if (mode === 'edit') {
                    editor.focus();
                }
            });
        });
    }

    return { init: init };
})();
