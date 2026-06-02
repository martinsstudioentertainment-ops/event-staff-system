(function () {
    'use strict';

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    var root = document.getElementById('pwa-push-root');
    if (!root) {
        return;
    }

    var statusToken = root.dataset.statusToken || '';
    var registrationId = root.dataset.registrationId || '';

    function urlBase() {
        var path = window.location.pathname.replace(/\/[^/]*$/, '/');
        return window.location.origin + path;
    }

    function showMessage(text, done) {
        root.innerHTML =
            '<div class="push-prompt' + (done ? ' push-prompt--done' : '') + '">' +
            '<p class="push-prompt__text">' + text + '</p>' +
            (done ? '' : '<button type="button" class="btn btn--primary btn--small" id="pwa-push-enable">Enable notifications</button>') +
            '</div>';
    }

    function subscribe() {
        return fetch(urlBase() + 'api/push-vapid-public.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok || !data.publicKey) {
                    throw new Error('Push not configured');
                }
                return navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(data.publicKey),
                    });
                });
            })
            .then(function (subscription) {
                var json = subscription.toJSON();
                return fetch(urlBase() + 'api/push-subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint: json.endpoint,
                        keys: json.keys,
                        status_token: statusToken,
                        registration_id: registrationId,
                    }),
                });
            });
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var output = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            output[i] = raw.charCodeAt(i);
        }
        return output;
    }

    navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription();
    }).then(function (existing) {
        if (existing) {
            showMessage('Notifications are enabled for this device.', true);
            return;
        }
        if (Notification.permission === 'denied') {
            showMessage('Notifications are blocked in browser settings.', true);
            return;
        }
        showMessage('Get notified when your registration is approved.');
        var btn = document.getElementById('pwa-push-enable');
        if (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                subscribe()
                    .then(function () {
                        showMessage('Notifications enabled.', true);
                    })
                    .catch(function () {
                        showMessage('Could not enable notifications. Try again later.', true);
                    });
            });
        }
    });
})();
