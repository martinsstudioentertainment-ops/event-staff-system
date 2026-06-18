/**
 * Event Staff System — Service worker (PWA: offline shell + asset cache)
 */
const CACHE_NAME = 'event-staff-v9-pwa-ios-fix';
const OFFLINE_URL = './offline.php';
const OFFLINE_CHECKIN_QUEUE = 'staff-offline-checkins-v1';

const CORE_ASSETS = [
    './offline.php',
    './staff-app.php',
    './assets/css/staff-app.css',
    './assets/css/staff-app-v2.css',
    './assets/css/pwa-install.css',
    './assets/css/variables.css',
    './assets/theme.css.php',
    './assets/css/style.css',
    './assets/css/mobile.css',
    './assets/js/mobile.js',
    './assets/js/pwa.js',
    './assets/js/pwa-install.js',
    './assets/js/signin-countdown.js',
    './assets/icons/icon.svg',
];

self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(CORE_ASSETS).catch(function () {
                return undefined;
            });
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) { return key !== CACHE_NAME; })
                    .map(function (key) { return caches.delete(key); })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('push', function (event) {
    var data = { title: 'Event Staff', body: 'You have an update.', url: './staff-app.php' };
    if (event.data) {
        try {
            data = Object.assign(data, event.data.json());
        } catch (e) {
            data.body = event.data.text();
        }
    }
    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: './api/pwa-icon.php?size=192',
            badge: './api/pwa-icon.php?size=192',
            data: { url: data.url || './staff-app.php' },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || './staff-app.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                var client = list[i];
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(target);
            }
        })
    );
});

// Background sync for offline check-in disabled (Sprint 6.5)

self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET') {
        return;
    }

    var url = new URL(event.request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(function () {
                return caches.match(OFFLINE_URL).then(function (cached) {
                    return cached || caches.match('./assets/css/style.css');
                });
            })
        );
        return;
    }

    var isStatic = /\.(css|js|svg|png|woff2?)(\?|$)/i.test(url.pathname) ||
        url.pathname.indexOf('/api/pwa-icon.php') !== -1 ||
        url.pathname.indexOf('/assets/theme.css.php') !== -1;

    if (isStatic) {
        event.respondWith(
            caches.match(event.request).then(function (cached) {
                var network = fetch(event.request).then(function (response) {
                    if (response && response.status === 200) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function (cache) {
                            cache.put(event.request, clone);
                        });
                    }
                    return response;
                });
                return cached || network;
            })
        );
    }
});
