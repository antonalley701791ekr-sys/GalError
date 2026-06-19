(function() {
    'use strict';

    function initDraftEnhancer() {
        var textarea = document.getElementById('md-editor');
        var wrap = document.querySelector('.md-editor-wrap');
        var tabs = document.querySelectorAll('.md-mode-tab');
        if (!textarea || !wrap || !tabs.length) return;

        var form = textarea.closest('form');
        var titleInput = form ? form.querySelector('input[name="title"]') : null;
        var customTagInput = form ? form.querySelector('input[name="custom_tag"]') : null;
        var tagSelector = document.getElementById('tagSelector');
        var tagCount = document.getElementById('tagCount');
        var addCustomTagBtn = document.getElementById('addCustomTagBtn');

        var draftKey = window.MARKDOWN_DRAFT_KEY || '';
        var allowDraftRestore = window.MARKDOWN_DRAFT_ALLOW_RESTORE !== false;
        if (!draftKey) return;

        function getCookie(name) {
            var prefix = name + '=';
            var parts = document.cookie ? document.cookie.split(';') : [];
            for (var i = 0; i < parts.length; i++) {
                var c = parts[i].trim();
                if (c.indexOf(prefix) === 0) {
                    return decodeURIComponent(c.substring(prefix.length));
                }
            }
            return '';
        }

        function clearDraftStorageByKey(key) {
            if (!key) return;
            try {
                localStorage.removeItem(key);
                localStorage.removeItem(key + ':title');
                localStorage.removeItem(key + ':content');
                localStorage.removeItem(key + ':tags');
                localStorage.removeItem(key + ':custom_tag');
            } catch (e) {}
        }

        var shouldClearDraftKey = getCookie('clear_markdown_draft');
        if (shouldClearDraftKey) {
            clearDraftStorageByKey(shouldClearDraftKey);
            document.cookie = 'clear_markdown_draft=; path=/; max-age=0';
        }

        var autosaveDelay = 2000;
        var autosaveTimer = null;
        var lastSavedValue = '';

        var status = document.createElement('div');
        status.className = 'md-draft-status is-idle';

        var statusText = document.createElement('span');
        statusText.className = 'md-draft-status-text';
        status.appendChild(statusText);

        var actions = document.createElement('div');
        actions.className = 'md-draft-actions';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'md-draft-action-btn';
        saveBtn.textContent = '立即保存';

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'md-draft-action-btn is-danger';
        clearBtn.textContent = '清空草稿';

        actions.appendChild(saveBtn);
        actions.appendChild(clearBtn);
        status.appendChild(actions);

        var hint = wrap.querySelector('.md-editor-hint');
        if (hint && hint.parentNode) {
            hint.parentNode.insertBefore(status, hint.nextSibling);
        } else {
            wrap.appendChild(status);
        }

        function setStatus(message, state, flash) {
            statusText.textContent = message;
            status.className = 'md-draft-status';
            status.classList.add('is-' + (state || 'idle'));
            if (flash) {
                status.classList.add('is-flash');
                clearTimeout(status._flashTimer);
                status._flashTimer = setTimeout(function() {
                    status.classList.remove('is-flash');
                }, 1800);
            }
        }

        function getCheckedTags() {
            if (!tagSelector) return [];
            return Array.prototype.slice.call(
                tagSelector.querySelectorAll('input[name="tags[]"]:checked')
            ).map(function(input) {
                return String(input.value || '').trim();
            }).filter(Boolean);
        }

        function collectDraftData() {
            return {
                version: 2,
                title: titleInput ? (titleInput.value || '') : '',
                content: textarea.value || '',
                tags: getCheckedTags(),
                custom_tag: customTagInput ? (customTagInput.value || '') : ''
            };
        }

        function serializeDraft(data) {
            try {
                return JSON.stringify(data);
            } catch (e) {
                return '';
            }
        }

        function parseDraft(raw) {
            if (!raw) return null;
            try {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    return {
                        version: parsed.version || 2,
                        title: parsed.title || '',
                        content: parsed.content || '',
                        tags: Array.isArray(parsed.tags) ? parsed.tags : [],
                        custom_tag: parsed.custom_tag || ''
                    };
                }
            } catch (e) {}

            return {
                version: 1,
                title: '',
                content: String(raw || ''),
                tags: [],
                custom_tag: ''
            };
        }

        function syncTagStylesAndCount() {
            if (!tagSelector) return;
            var checkboxes = tagSelector.querySelectorAll('input[name="tags[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                var chip = checkboxes[i].closest('.tag-chip');
                if (chip) chip.classList.toggle('selected', !!checkboxes[i].checked);
            }
            if (tagCount) {
                tagCount.textContent = String(getCheckedTags().length);
            }
        }

        function ensureTagChecked(tag) {
            if (!tagSelector) return;
            var value = String(tag || '').trim();
            if (!value) return;

            var checkboxes = tagSelector.querySelectorAll('input[name="tags[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                if ((checkboxes[i].value || '').trim() === value) {
                    checkboxes[i].checked = true;
                    return;
                }
            }

            var label = document.createElement('label');
            label.className = 'tag-chip selected';
            label.setAttribute('data-custom-tag', '1');

            var input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'tags[]';
            input.value = value;
            input.checked = true;

            label.appendChild(input);
            label.appendChild(document.createTextNode(value));
            tagSelector.appendChild(label);
        }

        function applyDraftData(data) {
            if (!data) return;

            if (titleInput) titleInput.value = data.title || '';
            textarea.value = data.content || '';
            if (customTagInput) customTagInput.value = data.custom_tag || '';

            if (tagSelector) {
                var oldCustomChips = tagSelector.querySelectorAll('.tag-chip[data-custom-tag="1"]');
                for (var i = 0; i < oldCustomChips.length; i++) {
                    oldCustomChips[i].remove();
                }

                var allTags = tagSelector.querySelectorAll('input[name="tags[]"]');
                for (var j = 0; j < allTags.length; j++) {
                    allTags[j].checked = false;
                }

                var tags = Array.isArray(data.tags) ? data.tags : [];
                for (var k = 0; k < tags.length; k++) {
                    ensureTagChecked(tags[k]);
                }
            }

            syncTagStylesAndCount();
            textarea.dispatchEvent(new Event('input'));
        }

        function saveDraft(showTip, forceSave) {
            var payload = collectDraftData();
            var snapshot = serializeDraft(payload);
            if (!snapshot) {
                setStatus('草稿保存失败', 'error', true);
                return;
            }

            if (!forceSave && snapshot === lastSavedValue) {
                if (showTip) {
                    setStatus('草稿已保存', 'saved', false);
                }
                return;
            }

            setStatus('正在保存草稿...', 'saving', false);
            try {
                localStorage.setItem(draftKey, snapshot);
                localStorage.setItem(draftKey + ':title', payload.title || '');
                localStorage.setItem(draftKey + ':content', payload.content || '');
                localStorage.setItem(draftKey + ':tags', JSON.stringify(payload.tags || []));
                localStorage.setItem(draftKey + ':custom_tag', payload.custom_tag || '');
                lastSavedValue = snapshot;
                setStatus(showTip ? '草稿已保存' : '草稿已同步', 'saved', !!showTip);
            } catch (e) {
                setStatus('草稿保存失败', 'error', true);
            }
        }

        function clearDraft() {
            clearTimeout(autosaveTimer);
            try {
                localStorage.removeItem(draftKey);
                localStorage.removeItem(draftKey + ':title');
                localStorage.removeItem(draftKey + ':content');
                localStorage.removeItem(draftKey + ':tags');
                localStorage.removeItem(draftKey + ':custom_tag');
            } catch (e) {}

            if (titleInput) titleInput.value = '';
            textarea.value = '';
            if (customTagInput) customTagInput.value = '';

            if (tagSelector) {
                var customChips = tagSelector.querySelectorAll('.tag-chip[data-custom-tag="1"]');
                for (var i = 0; i < customChips.length; i++) {
                    customChips[i].remove();
                }

                var allTags = tagSelector.querySelectorAll('input[name="tags[]"]');
                for (var j = 0; j < allTags.length; j++) {
                    allTags[j].checked = false;
                }
            }

            syncTagStylesAndCount();
            lastSavedValue = serializeDraft(collectDraftData());
            textarea.dispatchEvent(new Event('input'));
            clearTimeout(autosaveTimer);
            setStatus('草稿未保存', 'idle', true);
            textarea.focus();
        }

        function markDirtyAndScheduleSave() {
            setStatus('草稿未保存', 'dirty', false);
            clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(function() {
                saveDraft(true, false);
            }, autosaveDelay);
        }

        saveBtn.addEventListener('click', function() {
            clearTimeout(autosaveTimer);
            saveDraft(true, true);
        });

        clearBtn.addEventListener('click', function() {
            clearDraft();
        });

        try {
            var saved = localStorage.getItem(draftKey);
            var parsedSaved = parseDraft(saved);

            if (!saved) {
                var legacyContent = localStorage.getItem(draftKey + ':content');
                if (legacyContent !== null) {
                    parsedSaved = {
                        version: 2,
                        title: localStorage.getItem(draftKey + ':title') || '',
                        content: legacyContent || '',
                        tags: (function() {
                            try {
                                var raw = localStorage.getItem(draftKey + ':tags');
                                var arr = raw ? JSON.parse(raw) : [];
                                return Array.isArray(arr) ? arr : [];
                            } catch (e) {
                                return [];
                            }
                        })(),
                        custom_tag: localStorage.getItem(draftKey + ':custom_tag') || ''
                    };
                    saved = serializeDraft(parsedSaved);
                    if (saved) {
                        localStorage.setItem(draftKey, saved);
                    }
                }
            }

            var currentSnapshot = serializeDraft(collectDraftData());

            if (parsedSaved && saved && saved !== currentSnapshot) {
                if (allowDraftRestore) {
                    var shouldRestore = true;
                    if (currentSnapshot && currentSnapshot !== serializeDraft({
                        version: 2,
                        title: '',
                        content: '',
                        tags: [],
                        custom_tag: ''
                    })) {
                        var isEditingExistingDiscussion = !!document.querySelector('input[name="title"]') && !!document.querySelector('input[name="content"]');
                        if (!isEditingExistingDiscussion) {
                            shouldRestore = window.confirm('检测到本地草稿，是否恢复上次未完成内容？');
                        } else {
                            shouldRestore = false;
                        }
                    }

                    if (shouldRestore) {
                        applyDraftData(parsedSaved);
                        lastSavedValue = serializeDraft(collectDraftData());
                        setStatus('已恢复本地草稿', 'restored', true);
                    } else {
                        lastSavedValue = currentSnapshot;
                        setStatus('草稿已保存', 'saved', false);
                    }
                } else {
                    lastSavedValue = currentSnapshot;
                    setStatus('已加载现有话题内容', 'saved', false);
                }
            } else {
                lastSavedValue = currentSnapshot;
                if ((textarea.value || '').trim() || (titleInput && (titleInput.value || '').trim()) || getCheckedTags().length) {
                    setStatus('草稿已保存', 'saved', false);
                } else {
                    setStatus('草稿未保存', 'idle', false);
                }
            }
        } catch (e) {
            lastSavedValue = serializeDraft(collectDraftData());
            setStatus('草稿状态不可用', 'error', false);
        }

        textarea.addEventListener('input', markDirtyAndScheduleSave);

        if (titleInput) {
            titleInput.addEventListener('input', markDirtyAndScheduleSave);
        }

        if (customTagInput) {
            customTagInput.addEventListener('input', markDirtyAndScheduleSave);
            customTagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    setTimeout(markDirtyAndScheduleSave, 0);
                }
            });
        }

        if (tagSelector) {
            tagSelector.addEventListener('change', function(e) {
                if (e.target && e.target.name === 'tags[]') {
                    syncTagStylesAndCount();
                    markDirtyAndScheduleSave();
                }
            });
        }

        if (addCustomTagBtn) {
            addCustomTagBtn.addEventListener('click', function() {
                setTimeout(function() {
                    syncTagStylesAndCount();
                    markDirtyAndScheduleSave();
                }, 0);
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var mode = this.getAttribute('data-mode');
                if (mode !== 'edit') {
                    clearTimeout(autosaveTimer);
                    saveDraft(true, false);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDraftEnhancer);
    } else {
        initDraftEnhancer();
    }
})();
