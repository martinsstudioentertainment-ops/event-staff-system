/**
 * Admin console — service worker + PWA install hooks.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var swUrl = document.body.getAttribute('data-pwa-sw') || '../sw.js';
    var scope = document.body.getAttribute('data-pwa-scope') || '/';

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(swUrl, { scope: scope }).then(function (registration) {
            registration.update();

            if (registration.waiting) {
                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
        }).catch(function (err) {
            console.warn('[EventStaff Admin] Service worker registration failed:', err.message);
        });
    });
})();
