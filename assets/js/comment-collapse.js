(function() {
    var DEFAULT_MAX_LINES = 6;
    var COLLAPSE_EPSILON = 4;
    var resizeTimer = null;

    function getLineHeight(element) {
        var styles = window.getComputedStyle(element);
        var lineHeight = parseFloat(styles.lineHeight);
        if (!isFinite(lineHeight) || lineHeight <= 0) {
            var fontSize = parseFloat(styles.fontSize) || 16;
            lineHeight = fontSize * 1.75;
        }
        return lineHeight;
    }

    function getToggleElement(contentEl) {
        var next = contentEl.nextElementSibling;
        if (next && next.classList.contains('comment-collapse-toggle')) {
            return next;
        }
        return null;
    }

    function updateToggleText(toggleEl, expanded) {
        toggleEl.textContent = expanded ? '收起评论' : '展开全部';
        toggleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function setExpandedState(contentEl, expanded) {
        contentEl.dataset.expanded = expanded ? 'true' : 'false';
        contentEl.classList.toggle('is-expanded', expanded);
        contentEl.classList.toggle('is-collapsed', !expanded);

        if (expanded) {
            contentEl.style.maxHeight = 'none';
            contentEl.style.overflow = 'visible';
        } else {
            var collapseHeight = contentEl.style.getPropertyValue('--comment-collapse-height');
            contentEl.style.maxHeight = collapseHeight || '10.5em';
            contentEl.style.overflow = 'hidden';
        }

        var toggleEl = getToggleElement(contentEl);
        if (toggleEl) {
            updateToggleText(toggleEl, expanded);
        }
    }

    function ensureToggle(contentEl) {
        var toggleEl = getToggleElement(contentEl);
        if (toggleEl) {
            return toggleEl;
        }

        toggleEl = document.createElement('button');
        toggleEl.type = 'button';
        toggleEl.className = 'comment-collapse-toggle';
        toggleEl.addEventListener('click', function() {
            var expanded = contentEl.dataset.expanded === 'true';
            setExpandedState(contentEl, !expanded);
        });

        contentEl.insertAdjacentElement('afterend', toggleEl);
        return toggleEl;
    }

    function removeToggle(contentEl) {
        var toggleEl = getToggleElement(contentEl);
        if (toggleEl) {
            toggleEl.remove();
        }
    }

    function resetCollapsibleState(contentEl) {
        contentEl.classList.remove('comment-content-collapsible', 'is-collapsed', 'is-expanded');
        contentEl.style.removeProperty('--comment-collapse-height');
        contentEl.style.removeProperty('max-height');
        contentEl.style.removeProperty('overflow');
    }

    function measureAndApply(contentEl) {
        if (!contentEl) return;

        var maxLines = parseInt(contentEl.getAttribute('data-collapse-lines') || DEFAULT_MAX_LINES, 10);
        if (!isFinite(maxLines) || maxLines <= 0) {
            maxLines = DEFAULT_MAX_LINES;
        }

        var previousExpanded = contentEl.dataset.expanded === 'true';
        resetCollapsibleState(contentEl);

        var lineHeight = getLineHeight(contentEl);
        var collapseHeight = Math.ceil(lineHeight * maxLines);
        var fullHeight = Math.ceil(contentEl.scrollHeight);

        if (fullHeight <= collapseHeight + COLLAPSE_EPSILON) {
            contentEl.dataset.expanded = 'false';
            removeToggle(contentEl);
            return;
        }

        contentEl.classList.add('comment-content-collapsible');
        contentEl.style.setProperty('--comment-collapse-height', collapseHeight + 'px');
        ensureToggle(contentEl);
        setExpandedState(contentEl, previousExpanded);
    }

    function initCommentCollapses(root) {
        var scope = root || document;
        var contentList = scope.querySelectorAll('.comment-main-content');
        contentList.forEach(function(contentEl) {
            measureAndApply(contentEl);
        });
    }

    function scheduleRefresh() {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function() {
            initCommentCollapses(document);
        }, 120);
    }

    window.initCommentCollapses = initCommentCollapses;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initCommentCollapses(document);
        });
    } else {
        initCommentCollapses(document);
    }

    window.addEventListener('load', function() {
        initCommentCollapses(document);
    });
    window.addEventListener('resize', scheduleRefresh);
})();
