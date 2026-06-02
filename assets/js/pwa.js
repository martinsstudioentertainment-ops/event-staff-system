(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').then(function (registration) {
            registration.update();

            if (registration.waiting) {
                registration.waiting.postMessage({ type: 'SKIP_WAITING' });
            }

            registration.addEventListener('updatefound', function () {
                var worker = registration.installing;
                if (!worker) return;

                worker.addEventListener('statechange', function () {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        worker.postMessage({ type: 'SKIP_WAITING' });
                    }
                });
            });
        }).catch(function (err) {
            console.warn('[EventStaff] Service worker registration failed:', err.message);
        });

        var reloaded = false;
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (reloaded) return;
            reloaded = true;
            window.location.reload();
        });
    });
})();
