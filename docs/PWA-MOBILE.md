# Mobile app / PWA — roadmap

**Status:** Phase 29 — PWA foundation built (install, offline shell, push subscribe).

Staff use phones almost exclusively. Ship **PWA first**, native wrappers later.

---

## Route map

| Phase | What | Cost |
|-------|------|------|
| **1 — PWA (now)** | Install, offline shell, push subscribe, staff app hub | Low |
| **2 — Android** | TWA / Capacitor wrapper around same URLs | Medium |
| **3 — iPhone** | App Store wrapper or fully native | Higher |

---

## Phase 1 — What works

| Feature | How |
|---------|-----|
| **Install on phone** | Chrome/Safari → Add to Home Screen; install banner on staff pages |
| **Staff app home** | `staff-app.php` — register, check-in help, status link |
| **Offline** | Service worker caches shell; `offline.php` when no network |
| **Camera** | QR / venue sign-in (requires HTTPS or localhost) |
| **Push** | Subscribe on status page; VAPID keys in Settings → PWA; sent on approval |

**Requires HTTPS in production** for install + push + camera on real domains.

---

## Staff URLs (PWA scope)

- `staff-app.php` — start URL (home screen opens here)
- `index.php` — registration
- `check-in.php?token=…` — personal check-in link
- `status.php?token=…` — my events / push subscribe
- Venue sign-in pages (`event-sign.php` / flow)

Admin QR scan stays in `/admin/` (optional install; not primary PWA).

---

## Push setup (once)

1. Admin → **Settings → System → PWA & push notifications**
2. Click **Generate VAPID keys** (or run `php database/generate-vapid-keys.php`)
3. Staff open **status link** → allow notifications when prompted
4. On **approval**, system sends push (needs `vendor/` — run `composer install`)

---

## Native wrappers (later)

**Android (TWA):** Same `staff-app.php` URL in a Trusted Web Activity (Bubblewrap).

**iOS:** WKWebView shell or Capacitor; App Store review; push via APNs if not using Web Push in WebView.

---

## Key files

| File | Purpose |
|------|---------|
| `manifest.php` | Web app manifest |
| `sw.js` | Service worker |
| `staff-app.php` | Mobile hub |
| `offline.php` | Offline fallback |
| `assets/js/pwa-install.js` | Install banner |
| `assets/js/pwa-push.js` | Push subscribe |
| `includes/pwa-schema.php` | DB tables |
| `api/push-subscribe.php` | Save subscription |
| `includes/pwa-push.php` | Send notifications |
