/**
 * Staff PWA v2 — offline attendance queue + background sync hook
 */
(function () {
    'use strict';

    var QUEUE_KEY = 'staff_pwa_v2_offline_checkins';

    function readQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function writeQueue(items) {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(items));
    }

    window.staffPwaV2 = {
        queueOfflineCheckin: function (payload) {
            var queue = readQueue();
            queue.push({ id: Date.now(), payload: payload, at: new Date().toISOString() });
            writeQueue(queue);
            if ('serviceWorker' in navigator && navigator.serviceWorker.ready) {
                navigator.serviceWorker.ready.then(function (reg) {
                    if (reg.sync && reg.sync.register) {
                        reg.sync.register('staff-offline-checkin').catch(function () {});
                    }
                });
            }
            return queue.length;
        },
        flushOfflineQueue: function () {
            var queue = readQueue();
            if (!queue.length || !navigator.onLine) {
                return Promise.resolve(0);
            }
            return fetch('./api/staff-offline-sync.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ items: queue })
            }).then(function (res) {
                return res.json().catch(function () { return {}; });
            }).then(function (data) {
                if (data && data.ok) {
                    writeQueue([]);
                    return data.synced || queue.length;
                }
                return 0;
            }).catch(function () { return 0; });
        },
        pendingCount: function () {
            return readQueue().length;
        }
    };

    window.addEventListener('online', function () {
        window.staffPwaV2.flushOfflineQueue();
    });

    document.addEventListener('DOMContentLoaded', function () {
        window.staffPwaV2.flushOfflineQueue();
    });
})();
