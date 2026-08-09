(function () {
    'use strict';

    var board = document.getElementById('attendance-live-board');
    if (!board) {
        return;
    }

    var eventId = board.getAttribute('data-event-id') || '0';
    var endpoint = '../api/attendance-live.php?event_id=' + encodeURIComponent(eventId);
    var updatedEl = document.getElementById('attendance-live-updated');

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = String(value);
        }
    }

    function renderRecent(items, message) {
        var list = document.getElementById('attendance-live-recent');
        if (!list) {
            return;
        }

        if (message) {
            list.innerHTML = '<li class="attendance-live__empty">' + escapeHtml(message) + '</li>';
            return;
        }

        if (!items || !items.length) {
            list.innerHTML = '<li class="attendance-live__empty">No check-ins yet.</li>';
            return;
        }

        list.innerHTML = items.map(function (item) {
            return '<li class="attendance-live__item">'
                + '<strong>' + escapeHtml(item.name) + '</strong>'
                + '<span>' + escapeHtml(item.event) + '</span>'
                + '<time>' + escapeHtml(item.checked_in) + '</time>'
                + '</li>';
        }).join('');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateProgress(stats) {
        var bar = document.getElementById('attendance-capacity-bar');
        var label = document.getElementById('attendance-capacity-label');
        if (!bar || stats.staff_needed === null || stats.staff_needed === undefined) {
            if (bar) {
                bar.closest('.attendance-capacity').hidden = true;
            }
            return;
        }

        var needed = Number(stats.staff_needed) || 0;
        var approved = Number(stats.approved) || 0;
        var pct = needed > 0 ? Math.min(100, Math.round((approved / needed) * 100)) : 0;

        bar.closest('.attendance-capacity').hidden = false;
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', String(pct));

        if (label) {
            label.textContent = approved + ' / ' + needed + ' approved'
                + (stats.spaces_remaining !== null ? ' · ' + stats.spaces_remaining + ' spaces left' : '');
        }
    }

    function refresh() {
        fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Network error');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || data.ok === false) {
                    throw new Error((data && data.error) ? String(data.error) : 'Unable to load live attendance');
                }

                var stats = data.stats || {};
                setText('live-stat-approved', stats.approved);
                setText('live-stat-checked-in', stats.checked_in);
                setText('live-stat-missing', stats.missing);
                setText('live-stat-today', stats.today);

                if (stats.staff_needed !== null && stats.staff_needed !== undefined) {
                    setText('live-stat-needed', stats.staff_needed);
                    setText('live-stat-spaces', stats.spaces_remaining);
                    document.querySelectorAll('[data-staff-capacity]').forEach(function (el) {
                        el.hidden = false;
                    });
                }

                updateProgress(stats);
                renderRecent(data.recent || []);

                if (updatedEl && data.updated_at) {
                    var time = new Date(data.updated_at);
                    updatedEl.textContent = 'Updated ' + time.toLocaleTimeString();
                }
            })
            .catch(function (err) {
                renderRecent([], 'Live refresh unavailable — reload the page if this persists.');
                if (updatedEl) {
                    updatedEl.textContent = 'Live refresh paused';
                }
            });
    }

    refresh();
    setInterval(refresh, 15000);
})();
