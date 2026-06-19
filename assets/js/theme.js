/**
 * Galgame 报错百科 - 主题切换 + Toast + 移动端导航
 */
(function() {
    'use strict';

    // ======== Theme Toggle ========
    var STORAGE_KEY = 'galerror-theme';

    function getPreferredTheme() {
        try {
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'light' || saved === 'dark') return saved;
        } catch(e) {}
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            return 'light';
        }
        return 'dark';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        updateToggleIcons(theme);
        try { localStorage.setItem(STORAGE_KEY, theme); } catch(e) {}
    }

    function updateToggleIcons(theme) {
        var toggles = document.querySelectorAll('.theme-toggle');
        var sunSVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
        var moonSVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].innerHTML = theme === 'dark' ? sunSVG : moonSVG;
            toggles[i].title = theme === 'dark' ? '切换到浅色模式' : '切换到深色模式';
        }
    }

    window.toggleTheme = function() {
        var current = document.documentElement.getAttribute('data-theme') || 'dark';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    };

    // Apply on load
    applyTheme(getPreferredTheme());

    // Listen for system theme changes
    if (window.matchMedia) {
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
                if (!localStorage.getItem(STORAGE_KEY)) {
                    applyTheme(e.matches ? 'dark' : 'light');
                }
            });
        } catch(e) {}
    }

    // ======== Toast Notifications ========
    window.showToast = function(message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;

        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.innerHTML = '<span class="toast-msg">' + escapeHtml(message) + '</span>' +
            '<button class="toast-close" onclick="this.parentElement.remove()">&times;</button>';
        container.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('toast-leaving');
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    };

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ======== Mobile Nav Toggle ========
    document.addEventListener('click', function(e) {
        var todoToggle = e.target.closest('.todo-floating-toggle');
        if (todoToggle) {
            var dock = todoToggle.closest('.todo-floating-dock');
            if (dock) {
                dock.classList.toggle('is-open');
                todoToggle.setAttribute('aria-expanded', dock.classList.contains('is-open') ? 'true' : 'false');
            }
            e.preventDefault();
            e.stopPropagation();
            return;
        }

        var todoDock = e.target.closest('.todo-floating-dock');
        if (todoDock) {
            return;
        }

        var toggle = e.target.closest('.nav-mobile-toggle');
        if (toggle) {
            var nav = document.querySelector('.nav');
            if (nav) {
                nav.classList.toggle('open');
                // Close all nav dropdowns when closing nav
                if (!nav.classList.contains('open')) {
                    var openDropdowns = nav.querySelectorAll('.nav-dropdown-wrapper.open');
                    for (var i = 0; i < openDropdowns.length; i++) {
                        openDropdowns[i].classList.remove('open');
                    }
                }
            }
            return;
        }

        // Mobile nav dropdown toggle
        var trigger = e.target.closest('.nav-dropdown-trigger');
        if (trigger && window.innerWidth <= 768) {
            e.preventDefault();
            e.stopPropagation();
            var wrapper = trigger.closest('.nav-dropdown-wrapper');
            if (wrapper) {
                var wasOpen = wrapper.classList.contains('open');
                // Close all sibling dropdowns
                var siblings = wrapper.parentElement.querySelectorAll('.nav-dropdown-wrapper.open');
                for (var j = 0; j < siblings.length; j++) {
                    siblings[j].classList.remove('open');
                }
                if (!wasOpen) {
                    wrapper.classList.add('open');
                }
            }
            return;
        }

        // Close nav if clicking outside
        if (!e.target.closest('.nav') && !e.target.closest('.nav-mobile-toggle') && !e.target.closest('.nav-dropdown-menu')) {
            var openNav = document.querySelector('.nav.open');
            if (openNav) openNav.classList.remove('open');
        }
    });

})();
