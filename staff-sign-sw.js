/* Staff Sign Hub — offline shell + sign-out reminders (app can be closed). */
const CACHE = 'staff-sign-v4';
const PRECACHE = [
  '/staff-sign-hub-app.php?app=1&from_gate=1&v=20260928d',
  '/sign.php',
  '/staff-sign-manifest.php',
  '/staff-sign-geo-lock.php',
  '/staff-sign-geo-lock-manifest.php',
  '/api/pwa-icon.php?size=192',
  '/api/pwa-icon.php?size=180'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE).catch(() => undefined)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/api/') === 0) return;
  event.respondWith(
    fetch(req).then((res) => {
      const copy = res.clone();
      if (res.ok && (url.pathname.indexOf('staff-sign') >= 0 || url.pathname === '/sign.php' || url.pathname.indexOf('geo-lock') >= 0)) {
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => undefined);
      }
      return res;
    }).catch(() => caches.match(req))
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification && event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : '/staff-sign-hub-app.php?app=1&from_gate=1&v=20260928d';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if (c.url && c.url.indexOf('staff-sign-hub-app.php') >= 0 && 'focus' in c) {
          return c.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(target);
      }
      return undefined;
    })
  );
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SIGN_OUT_DUE') {
    event.waitUntil(showSignOutAlarm(data));
  }
  if (data.type === 'CLEAR_ALARM') {
    event.waitUntil(
      self.registration.getNotifications({ tag: 'staff-sign-out' }).then((list) => {
        list.forEach((n) => n.close());
      })
    );
  }
});

async function showSignOutAlarm(data) {
  const title = data.title || 'Sign out now — Olasentra Staff Sign';
  const body = data.body || 'Event end time reached. Open Staff Sign and tap Sign out.';
  const opts = {
    body,
    tag: 'staff-sign-out',
    renotify: true,
    requireInteraction: true,
    vibrate: [400, 200, 400, 200, 400],
    icon: '/api/pwa-icon.php?size=192',
    badge: '/api/pwa-icon.php?size=192',
    data: {
      url: data.url || '/staff-sign-hub-app.php?app=1&from_gate=1&v=20260928d'
    }
  };
  try {
    await self.registration.showNotification(title, opts);
  } catch (e) {
    // ignore
  }
}
