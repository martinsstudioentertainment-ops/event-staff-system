/**
 * Event Staff System — Admin UI (sidebar, theme)
 */
(function () {
    'use strict';

    const THEME_KEY = 'eventStaffTheme';

    function initSidebar() {
        const menuBtn = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        if (!menuBtn || !sidebar) return;

        function closeSidebar() {
            sidebar.classList.remove('sidebar--open');
            if (overlay) overlay.classList.remove('sidebar-overlay--visible');
            document.body.style.overflow = '';
        }

        function openSidebar() {
            sidebar.classList.add('sidebar--open');
            if (overlay) overlay.classList.add('sidebar-overlay--visible');
            document.body.style.overflow = 'hidden';
        }

        menuBtn.addEventListener('click', function () {
            if (sidebar.classList.contains('sidebar--open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        if (overlay) overlay.addEventListener('click', closeSidebar);

        sidebar.querySelectorAll('.sidebar__link, .sidebar__logout, .sidebar__quick-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    }

    function initMobileTables() {
        document.querySelectorAll('.data-table').forEach(function (table) {
            const headers = Array.from(table.querySelectorAll('thead th')).map(function (th) {
                return th.textContent.trim();
            });

            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.querySelectorAll('td').forEach(function (cell, index) {
                    if (cell.classList.contains('data-table__empty')) {
                        return;
                    }

                    const label = headers[index] || (cell.querySelector('.action-group') ? 'Actions' : '');
                    if (label) {
                        cell.setAttribute('data-label', label);
                    }
                });
            });
        });
    }

    function initCopyButtons() {
        document.querySelectorAll('[data-copy-target]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const targetId = button.getAttribute('data-copy-target');
                const input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;

                const originalText = button.textContent;

                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(input.value);
                    } else {
                        input.focus();
                        input.select();
                        document.execCommand('copy');
                    }

                    button.textContent = 'Copied!';
                    button.classList.add('btn--success');
                    setTimeout(function () {
                        button.textContent = originalText;
                        button.classList.remove('btn--success');
                    }, 2000);
                } catch (err) {
                    button.textContent = 'Copy failed';
                    setTimeout(function () {
                        button.textContent = originalText;
                    }, 2000);
                }
            });
        });
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
        const toggle = document.getElementById('theme-toggle');
        if (toggle) {
            toggle.textContent = theme === 'dark' ? '☀️' : '🌙';
        }
    }

    function initThemeToggle() {
        const isAdmin = document.body.classList.contains('admin-shell');
        const saved = localStorage.getItem(THEME_KEY) || (isAdmin ? 'dark' : 'light');
        applyTheme(saved);

        const toggle = document.getElementById('theme-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', function () {
            const next = (localStorage.getItem(THEME_KEY) || 'light') === 'dark' ? 'light' : 'dark';
            localStorage.setItem(THEME_KEY, next);
            applyTheme(next);
        });
    }

    function initSensitiveReveal() {
        document.querySelectorAll('.js-sensitive-reveal').forEach(function (button) {
            button.addEventListener('click', function () {
                var field = button.closest('.sensitive-field');
                if (!field) return;

                var valueEl = field.querySelector('.js-sensitive-value');
                if (!valueEl) return;

                var full = valueEl.getAttribute('data-full') || '';
                var revealed = button.getAttribute('aria-pressed') === 'true';

                if (revealed) {
                    valueEl.textContent = valueEl.getAttribute('data-masked') || valueEl.textContent;
                    button.textContent = 'Reveal';
                    button.setAttribute('aria-pressed', 'false');
                } else {
                    if (!valueEl.getAttribute('data-masked')) {
                        valueEl.setAttribute('data-masked', valueEl.textContent);
                    }
                    valueEl.textContent = full;
                    button.textContent = 'Hide';
                    button.setAttribute('aria-pressed', 'true');
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initThemeToggle();
        initMobileTables();
        initCopyButtons();
        initSensitiveReveal();
        initStaffBulkSelect();
    });

    function initStaffBulkSelect() {
        var selectAll = document.getElementById('staff-select-all');
        var checks = document.querySelectorAll('.staff-row-check');
        var countEl = document.getElementById('bulk-selected-count');
        if (!selectAll || !checks.length) {
            return;
        }

        function updateCount() {
            var selected = document.querySelectorAll('.staff-row-check:checked').length;
            if (countEl) {
                countEl.textContent = String(selected);
            }
            selectAll.checked = selected > 0 && selected === checks.length;
            selectAll.indeterminate = selected > 0 && selected < checks.length;
        }

        selectAll.addEventListener('change', function () {
            checks.forEach(function (check) {
                check.checked = selectAll.checked;
            });
            updateCount();
        });

        checks.forEach(function (check) {
            check.addEventListener('change', updateCount);
        });

        document.getElementById('staff-bulk-form')?.addEventListener('submit', function (event) {
            var selected = document.querySelectorAll('.staff-row-check:checked').length;
            if (selected === 0) {
                event.preventDefault();
                alert('Select at least one registration.');
                return;
            }

            var submitter = event.submitter;
            var action = submitter && submitter.value ? submitter.value : 'update';
            if (!window.confirm('Apply "' + action + '" to ' + selected + ' registration(s)?')) {
                event.preventDefault();
            }
        });
    }
})();
