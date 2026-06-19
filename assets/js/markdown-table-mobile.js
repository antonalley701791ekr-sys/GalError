(function() {
    function getScrollEl(wrapper) {
        return wrapper ? wrapper.querySelector(':scope > .markdown-table-scroll') : null;
    }

    function updateFadeState(wrapper) {
        if (!wrapper) return;
        var scroller = getScrollEl(wrapper);
        if (!scroller) return;
        var maxScroll = scroller.scrollWidth - scroller.clientWidth;
        var canScroll = maxScroll > 2;
        wrapper.classList.toggle('is-scrollable', canScroll);
        wrapper.classList.toggle('is-at-left', !canScroll || scroller.scrollLeft <= 2);
        wrapper.classList.toggle('is-at-right', !canScroll || scroller.scrollLeft >= maxScroll - 2);
    }

    function attachFades(wrapper) {
        if (!wrapper) return;
        var scroller = getScrollEl(wrapper);
        if (!scroller) return;

        if (!wrapper.querySelector(':scope > .markdown-table-fade-left')) {
            var leftFade = document.createElement('div');
            leftFade.className = 'markdown-table-fade markdown-table-fade-left';
            var rightFade = document.createElement('div');
            rightFade.className = 'markdown-table-fade markdown-table-fade-right';
            wrapper.appendChild(leftFade);
            wrapper.appendChild(rightFade);
        }

        var rafId = 0;
        function requestUpdate() {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(function() {
                rafId = 0;
                updateFadeState(wrapper);
            });
        }

        scroller.addEventListener('scroll', requestUpdate, { passive: true });
        window.addEventListener('resize', requestUpdate);
        requestUpdate();
    }

    function wrapTables() {
        var tables = document.querySelectorAll('.markdown-body table');
        tables.forEach(function(table) {
            if (!table) return;
            var wrapper = table.closest('.markdown-table-wrap');
            var scroller = wrapper ? getScrollEl(wrapper) : null;

            if (!wrapper) {
                wrapper = document.createElement('div');
                wrapper.className = 'markdown-table-wrap';
                scroller = document.createElement('div');
                scroller.className = 'markdown-table-scroll';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(scroller);
                scroller.appendChild(table);
            } else if (!scroller) {
                scroller = document.createElement('div');
                scroller.className = 'markdown-table-scroll';
                wrapper.insertBefore(scroller, wrapper.firstChild);
                scroller.appendChild(table);
            } else if (table.parentNode !== scroller) {
                scroller.appendChild(table);
            }

            attachFades(wrapper);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wrapTables);
    } else {
        wrapTables();
    }
})();
