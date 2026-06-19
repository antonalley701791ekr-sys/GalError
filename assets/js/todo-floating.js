(function() {
    'use strict';

    function initTodoFloatingDock() {
        var dock = document.getElementById('todoFloatingDock');
        if (!dock) return;

        var toggle = dock.querySelector('.todo-floating-toggle');
        var entry = dock.querySelector('.todo-floating-entry');

        if (entry && !entry.querySelector('svg')) {
            entry.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5V5.75A1.75 1.75 0 0 1 5.75 4h4.1c.46 0 .9.18 1.23.51l1.4 1.4c.33.33.77.51 1.23.51h4.54A1.75 1.75 0 0 1 20 8.16v11.34A1.5 1.5 0 0 1 18.5 21H5.5A1.5 1.5 0 0 1 4 19.5Z"></path><path d="M7 9h7"></path><path d="M7 13h10"></path></svg>';
        }

        if (toggle && !toggle.getAttribute('aria-expanded')) {
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var isOpen = dock.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        dock.addEventListener('click', function(e) {
            if (e.target === dock) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        document.addEventListener('click', function(e) {
            if (!dock.contains(e.target) && dock.classList.contains('is-open')) {
                dock.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dock.classList.contains('is-open')) {
                dock.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTodoFloatingDock);
    } else {
        initTodoFloatingDock();
    }
})();
