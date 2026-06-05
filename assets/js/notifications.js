(function () {
    'use strict';

    var apiBase = document.body.classList.contains('erp-admin') ? '../api/' : 'api/';

    function markReadOnClick() {
        document.querySelectorAll('.notif-item__link[data-notif-id]').forEach(function (link) {
            link.addEventListener('click', function () {
                var id = link.getAttribute('data-notif-id');
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
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(apiBase + 'notifications-mark-read.php', new Blob([body], { type: 'application/json' }));
                }
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

    function updateAdminSidebarBadge() {
        if (!document.body.classList.contains('erp-admin')) {
            return;
        }
        var badge = document.querySelector('[data-admin-notif-badge]');
        if (!badge) {
            return;
        }
        fetch(apiBase + 'notifications.php?audience=admin', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                applyBadgeCount(badge, parseInt(data.unread, 10) || 0);
            })
            .catch(function () {});
    }

    markReadOnClick();
    updateStaffBadge();
    updateAdminSidebarBadge();

    if (document.body.classList.contains('erp-admin')) {
        setInterval(updateAdminSidebarBadge, 120000);
    }
})();
