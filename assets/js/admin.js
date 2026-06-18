/**
 * Event Staff System — Admin UI (sidebar, theme)
 */
(function () {
    'use strict';

    const THEME_KEY = 'eventStaffTheme';

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
        initThemeToggle();
        initMobileTables();
        initCopyButtons();
        initSensitiveReveal();
        initStaffBulkSelect();
        initEventsSheetBulkSelect();
        initEventDeleteModal();
        initUserMenu();
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

    function initEventsSheetBulkSelect() {
        var selectAll = document.getElementById('events-sheet-select-all');
        var checks = document.querySelectorAll('.events-sheet-row-check');
        var countEl = document.getElementById('events-sheet-selected-count');
        var form = document.getElementById('events-sheets-bulk-form');
        if (!selectAll || !checks.length || !form) {
            return;
        }

        function updateCount() {
            var selected = document.querySelectorAll('.events-sheet-row-check:checked').length;
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

        form.addEventListener('submit', function (event) {
            var selected = document.querySelectorAll('.events-sheet-row-check:checked').length;
            if (selected === 0) {
                event.preventDefault();
                alert('Select at least one event.');
                return;
            }

            var submitter = event.submitter;
            var action = submitter && submitter.value ? submitter.value : '';
            var message;
            if (action === 'unlink_selected') {
                message = 'Unlink ' + selected + ' selected event(s) from Google Sheets? Files in Drive are not deleted.';
            } else if (action === 'create_selected') {
                message = 'Create new Google Sheet(s) for ' + selected + ' selected event(s)? Events that still have a link are skipped — unlink them first.';
            } else {
                message = 'Link ' + selected + ' selected event(s) by matching file names in your Drive folder?';
            }
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    }

    function initEventDeleteModal() {
        var modal = document.getElementById('event-delete-modal');
        if (!modal) {
            return;
        }

        var form = document.getElementById('event-delete-modal-form');
        var eventIdInput = document.getElementById('event-delete-modal-event-id');
        var eventNameEl = document.getElementById('event-delete-modal-event-name');
        var summaryEl = document.getElementById('event-delete-modal-summary');
        var confirmInput = document.getElementById('event-delete-modal-confirm');

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('event-delete-modal-open');
            if (confirmInput) {
                confirmInput.value = '';
            }
        }

        function openModal(trigger) {
            if (!eventIdInput || !eventNameEl || !summaryEl) {
                return;
            }

            eventIdInput.value = trigger.getAttribute('data-event-id') || '';
            eventNameEl.textContent = trigger.getAttribute('data-event-name') || 'Event';
            var regs = trigger.getAttribute('data-event-regs') || '0';
            summaryEl.textContent = 'Permanently removes this event, ' + regs + ' registration(s), attendance, invoices, and all related history. This cannot be undone.';
            if (confirmInput) {
                confirmInput.value = '';
            }
            modal.hidden = false;
            document.body.classList.add('event-delete-modal-open');
            if (confirmInput) {
                confirmInput.focus();
            }
        }

        document.querySelectorAll('[data-event-delete-trigger]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                openModal(trigger);
            });
        });

        modal.querySelectorAll('[data-event-delete-close]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!confirmInput || confirmInput.value.trim().toUpperCase() !== 'DELETE') {
                    event.preventDefault();
                    alert('Type DELETE in capital letters to confirm.');
                    if (confirmInput) {
                        confirmInput.focus();
                    }
                    return;
                }

                if (!window.confirm('Delete this event permanently?')) {
                    event.preventDefault();
                }
            });
        }
    }

    function initUserMenu() {
        var btn = document.getElementById('admin-user-menu-btn');
        var menu = document.getElementById('admin-user-menu');
        if (!btn || !menu) {
            return;
        }

        function closeMenu() {
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = menu.hidden;
            if (open) {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            } else {
                closeMenu();
            }
        });

        document.addEventListener('click', function (event) {
            if (!menu.hidden && !menu.contains(event.target) && event.target !== btn) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
            }
        });
    }

})();
