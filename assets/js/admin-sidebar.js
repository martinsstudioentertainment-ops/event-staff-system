/**
 * ERP Premium Sidebar — collapse, sections, tooltips, mobile drawer
 */
(function () {
    'use strict';

    var STORAGE_COLLAPSED = 'erp_sidebar_collapsed_v2';
    var STORAGE_SECTIONS  = 'erp_sidebar_sections_v2';
    var MOBILE_BP         = 768;
    var TABLET_BP         = 1024;

    function init() {
        var sidebar   = document.getElementById('sidebar');
        var overlay   = document.getElementById('sidebar-overlay');
        var menuBtn   = document.getElementById('menu-toggle');
        var collapse  = document.getElementById('sidebar-collapse');
        var tooltip   = document.getElementById('erp-sidebar-tooltip');
        var shell     = document.body;

        if (!sidebar || !shell.classList.contains('admin-shell')) {
            return;
        }

        initCollapsedState(shell, collapse);
        initSectionGroups(sidebar);
        initMobileDrawer(sidebar, overlay, menuBtn);
        initCollapseToggle(shell, collapse);
        initTooltips(sidebar, tooltip, shell);
        initKeyboardNav(sidebar);
    }

    function initCollapsedState(shell, collapseBtn) {
        var saved = false;
        try {
            saved = localStorage.getItem(STORAGE_COLLAPSED) === '1';
        } catch (e) {}

        if (saved && window.innerWidth > MOBILE_BP) {
            shell.classList.add('sidebar-is-collapsed');
            if (collapseBtn) {
                collapseBtn.setAttribute('aria-pressed', 'true');
            }
        }
    }

    function initCollapseToggle(shell, collapseBtn) {
        if (!collapseBtn) {
            return;
        }

        collapseBtn.addEventListener('click', function () {
            if (window.innerWidth <= MOBILE_BP) {
                return;
            }

            var collapsed = shell.classList.toggle('sidebar-is-collapsed');
            collapseBtn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');

            try {
                localStorage.setItem(STORAGE_COLLAPSED, collapsed ? '1' : '0');
            } catch (e) {}
        });

        window.addEventListener('resize', debounce(function () {
            if (window.innerWidth <= MOBILE_BP) {
                shell.classList.remove('sidebar-is-collapsed');
            }
        }, 150));
    }

    function initSectionGroups(sidebar) {
        var saved = {};
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_SECTIONS) || '{}');
        } catch (e) {
            saved = {};
        }

        sidebar.querySelectorAll('.erp-sidebar__group[data-section]').forEach(function (details) {
            var id = details.getAttribute('data-section');
            if (!id) {
                return;
            }

            if (details.getAttribute('data-active') === '1') {
                details.open = true;
            } else if (Object.prototype.hasOwnProperty.call(saved, id)) {
                details.open = saved[id] === true;
            }

            details.addEventListener('toggle', function () {
                saved[id] = details.open;
                try {
                    localStorage.setItem(STORAGE_SECTIONS, JSON.stringify(saved));
                } catch (e) {}
            });
        });
    }

    function initMobileDrawer(sidebar, overlay, menuBtn) {
        if (!menuBtn) {
            return;
        }

        function closeSidebar() {
            sidebar.classList.remove('sidebar--open');
            if (overlay) {
                overlay.classList.remove('sidebar-overlay--visible');
            }
            document.body.style.overflow = '';
        }

        function openSidebar() {
            sidebar.classList.add('sidebar--open');
            if (overlay) {
                overlay.classList.add('sidebar-overlay--visible');
            }
            document.body.style.overflow = 'hidden';
        }

        menuBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('sidebar--open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        sidebar.querySelectorAll('.erp-sidebar__link, .erp-sidebar__action, .erp-sidebar__brand').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= MOBILE_BP) {
                    closeSidebar();
                }
            });
        });
    }

    function initTooltips(sidebar, tooltip, shell) {
        if (!tooltip) {
            return;
        }

        var activeTarget = null;

        function showTooltip(target) {
            var label = target.getAttribute('data-tooltip');
            if (!label || !shell.classList.contains('sidebar-is-collapsed') || window.innerWidth <= MOBILE_BP) {
                hideTooltip();
                return;
            }

            activeTarget = target;
            tooltip.textContent = label;
            tooltip.hidden = false;

            var rect = target.getBoundingClientRect();
            var top  = rect.top + rect.height / 2;
            var left = rect.right + 12;

            tooltip.style.top  = top + 'px';
            tooltip.style.left = left + 'px';
            tooltip.style.transform = 'translateY(-50%)';
            tooltip.classList.add('is-visible');
        }

        function hideTooltip() {
            activeTarget = null;
            tooltip.classList.remove('is-visible');
            tooltip.hidden = true;
        }

        sidebar.querySelectorAll('[data-tooltip]').forEach(function (el) {
            el.addEventListener('mouseenter', function () {
                showTooltip(el);
            });
            el.addEventListener('mouseleave', hideTooltip);
            el.addEventListener('focus', function () {
                showTooltip(el);
            });
            el.addEventListener('blur', hideTooltip);
        });

        window.addEventListener('scroll', hideTooltip, true);
    }

    function initKeyboardNav(sidebar) {
        sidebar.addEventListener('keydown', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (event.key === 'Escape') {
                var openGroup = sidebar.querySelector('.erp-sidebar__group[open] > summary:focus');
                if (openGroup && openGroup.parentElement instanceof HTMLDetailsElement) {
                    openGroup.parentElement.open = false;
                    event.preventDefault();
                }
            }
        });
    }

    function debounce(fn, wait) {
        var timer;
        return function () {
            clearTimeout(timer);
            timer = setTimeout(fn, wait);
        };
    }

    document.addEventListener('DOMContentLoaded', init);
})();
