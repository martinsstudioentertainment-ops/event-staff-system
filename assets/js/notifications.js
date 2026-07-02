(function () {
    'use strict';

    var apiBase = document.body.classList.contains('erp-admin') ? '../api/' : 'api/';

    function markReadOnClick() {
        document.querySelectorAll('[data-notif-id].notif-item__cta, [data-notif-id].notif-item__link, [data-notif-id].es-v3__notif-card-cta').forEach(function (link) {
            link.addEventListener('click', function () {
                var id = link.getAttribute('data-notif-id');
                if (!id) {
                    return;
                }
                var item = link.closest('.notif-item') || link.closest('.es-v3__notif-card');
                if (item) {
                    item.classList.remove('notif-item--unread');
                    item.classList.remove('es-v3__notif-card--unread');
                }
                markNotificationRead(id);
            });
        });
    }

    function applyBadgeCount(badge, count) {
        if (!badge) {
            return;
        }
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = false;
            badge.setAttribute('aria-label', count + ' unread notifications');
        } else {
            badge.hidden = true;
            badge.removeAttribute('aria-label');
        }
    }

    function updateStaffBadge() {
        var badge = document.querySelector('[data-notif-badge]');
        if (!badge) {
            return;
        }
        var token = badge.getAttribute('data-status-token') || '';
        var url = apiBase + 'notifications.php?audience=staff';
        if (token) {
            url += '&token=' + encodeURIComponent(token);
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                applyBadgeCount(badge, parseInt(data.unread, 10) || 0);
            })
            .catch(function () {});
    }

    function updateAdminBadges() {
        if (!document.body.classList.contains('erp-admin')) {
            return;
        }
        var sidebarBadge = document.querySelector('[data-admin-notif-badge]');
        var headerBadge = document.querySelector('[data-admin-notif-header-badge]');
        if (!sidebarBadge && !headerBadge) {
            return;
        }
        fetch(apiBase + 'notifications.php?audience=admin', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                var count = parseInt(data.unread, 10) || 0;
                applyBadgeCount(sidebarBadge, count);
                applyBadgeCount(headerBadge, count);
            })
            .catch(function () {});
    }

    function markNotificationRead(id) {
        if (!id) {
            return;
        }
        var tokenEl = document.querySelector('[data-status-token]');
        var token = tokenEl ? tokenEl.getAttribute('data-status-token') : '';
        var body = JSON.stringify({
            id: parseInt(id, 10),
            audience: document.body.classList.contains('erp-admin') ? 'admin' : 'staff',
            token: token || undefined
        });
        var url = apiBase + 'notifications-mark-read.php';
        var sent = false;
        if (navigator.sendBeacon) {
            sent = navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
        }
        if (!sent) {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: body
            }).catch(function () {});
        }
        if (!document.body.classList.contains('erp-admin')) {
            updateStaffBadge();
        } else {
            updateAdminBadges();
        }
    }

    function initExpandableNotifications() {
        document.querySelectorAll('.notif-item__toggle, .es-v3__notif-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = btn.closest('.notif-item') || btn.closest('.es-v3__notif-card');
                if (!item) {
                    return;
                }
                var open = item.classList.contains('es-v3__notif-card')
                    ? item.classList.toggle('es-v3__notif-card--open')
                    : item.classList.toggle('notif-item--open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    item.classList.remove('notif-item--unread');
                    item.classList.remove('es-v3__notif-card--unread');
                    markNotificationRead(btn.getAttribute('data-notif-id'));
                }
            });
        });
    }

    markReadOnClick();
    initExpandableNotifications();
    updateStaffBadge();
    updateAdminBadges();

    if (document.body.classList.contains('erp-admin')) {
        setInterval(updateAdminBadges, 120000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                updateAdminBadges();
            }
        });
    }

    if (document.querySelector('[data-notif-badge]')) {
        setInterval(updateStaffBadge, 90000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                updateStaffBadge();
            }
        });
    }
})();
