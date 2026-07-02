# Phase 8B — Reliability Fix Implementation

**Status:** Implemented locally — **NOT DEPLOYED** (awaiting review and approval)

**Scope:** P8-01 (Service Worker), P8-04 (Status label), P8-05 (Guest messages redirect)

---

## 1. Files modified

| File | Change |
|------|--------|
| `sw.js` | Bump cache to `event-staff-v10-v3-staff-pwa`; add v3 CSS/JS to precache; remove legacy `staff-app.css` / `staff-app-v2.css` from precache |
| `includes/staff-app-v3-pages.php` | Rename home action label **View Roster** → **Application status** (href unchanged: `$ctx['status_url']`) |
| `staff-messages.php` | Redirect guests (no portal session, no token) to `staff-app.php?return=staff-messages.php` |
| `scripts/phase8b-reliability-test.php` | **New** — static regression checks (23 assertions) |
| `scripts/deploy-phase8b-reliability.ps1` | **New** — approved deploy bundle (run after approval only) |

**Not modified:** Auth, OAuth, OTP APIs, attendance, GPS, BIB, schema, manifest, login layout.

---

## 2. Change detail

### P8-01 — Service worker

**Before:** `event-staff-v9-pwa-ios-fix` precached legacy `staff-app.css`, `staff-app-v2.css`; no v3 assets.

**After:** Precache includes:

- `assets/css/staff-app-v3.css`
- `assets/css/notifications.css`
- `assets/js/staff-app-v3.js`
- `assets/js/staff-portal-email-otp.js`

Legacy staff-app v1/v2 CSS removed from install precache (runtime fetch handler still caches them if legacy pages are visited online).

**Unchanged:** Navigate fallback → `offline.php`; push/notification handlers; static stale-while-revalidate fetch logic.

### P8-04 — Status label

```118:120:includes/staff-app-v3-pages.php
            <a href="<?= h((string) $ctx['status_url']) ?>" class="es-v3__action-card">
                ...
                <span>Application status</span>
```

Route remains `status.php?token=…` — personal application status dashboard.

### P8-05 — Guest messages

```108:116:staff-messages.php
$useV3Shell = $portalStaff !== null && !$showLookup;

if ($portalStaff === null && $token === '' && $showLookup) {
    header('Location: staff-app.php?return=' . urlencode('staff-messages.php'));
    exit;
}
```

| Access path | Behaviour |
|-------------|-----------|
| Signed-in portal session | v3 shell (unchanged) |
| `?token=` valid link | Legacy token thread (unchanged) |
| Guest GET `/staff-messages.php` | **302 → staff-app.php** |
| Invalid token | Redirect to login (token cleared, showLookup true) |

---

## 3. Risk assessment

| Fix | Risk | Mitigation |
|-----|------|------------|
| SW precache + cache bump | **Low** | New cache name clears old caches on activate; test offline PWA after deploy |
| Remove legacy CSS from precache | **Low** | `status.php` / legacy pages still fetch CSS at runtime when online; offline legacy pages may need prior visit |
| Label rename | **None** | Copy only |
| Guest messages redirect | **Low** | Aligns with `staff-notifications.php`; token deep links preserved |

**Rollback triggers:** Login broken, messages inaccessible when signed in, PWA fails to register SW, offline page unstyled after fresh install.

---

## 4. Regression tests

### Automated (local)

```powershell
php scripts/phase8b-reliability-test.php
```

**Result:** 23/23 PASS

Also recommended before deploy:

```powershell
php scripts/phase5c-login-parity-test.php
php scripts/phase7-design-system-test.php
```

### Manual post-deploy checklist

| # | Test | Expected |
|---|------|----------|
| 1 | Open `staff-app.php` (guest) | Google + OTP + Register visible |
| 2 | Home → **Application status** | Opens `status.php` with token when signed in |
| 3 | Label reads **Application status** (not View Roster) | Pass |
| 4 | Signed-in → Messages (bottom nav) | v3 dark messages UI |
| 5 | Guest → `staff-messages.php` | Redirect to `staff-app.php?return=staff-messages.php` |
| 6 | `staff-messages.php?token=…` (valid) | Legacy thread still loads |
| 7 | DevTools → Application → Service Workers | New SW active; cache `event-staff-v10-v3-staff-pwa` |
| 8 | Fresh PWA install → airplane mode → open app | v3 login styling + offline page styled |
| 9 | Google login + OTP send/verify | Unchanged |
| 10 | Check-in / GPS / BIB | Unchanged (smoke only) |

---

## 5. Deployment plan

**Do not deploy until explicitly approved.**

### Deploy command (after approval)

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase8b-reliability.ps1
```

Or full pipeline:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy.ps1
```

(Ensure Phase 8B files are included — use dedicated script for minimal blast radius.)

### Deploy bundle (3 files)

| Local | Remote (`register.olasentra.com` / `public_html`) |
|-------|---------------------------------------------------|
| `sw.js` | `sw.js` |
| `includes/staff-app-v3-pages.php` | `includes/staff-app-v3-pages.php` |
| `staff-messages.php` | `staff-messages.php` |

**Note:** SW precache references existing v3 assets (already on production from Phase 7B). No CSS/JS re-upload required unless hashes drift.

### Post-deploy verification

1. Run `scripts/phase8b-reliability-test.php` locally (already pass).
2. HTTP probe: `sw.js` contains `event-staff-v10-v3-staff-pwa`.
3. HTTP probe: guest `staff-messages.php` → 302 to `staff-app.php`.
4. Device: installed PWA offline styling (P8-01 acceptance test).

---

## 6. Rollback plan

**Backup:** Script creates `storage/backups/phase8b-pre-deploy-{timestamp}/` with production copies before upload.

**Restore (if needed):**

1. Copy backed-up files from backup folder to project root.
2. Re-run FTP upload for the 3 files only, **or** restore individually via cPanel File Manager.
3. Prior `sw.js` cache name: `event-staff-v9-pwa-ios-fix` — restoring old `sw.js` reverts precache list; users may need one refresh to re-activate old SW.

**Per-file rollback:**

| Issue | Restore |
|-------|---------|
| Offline/PWA regression | `sw.js` from backup |
| Wrong label | `includes/staff-app-v3-pages.php` from backup |
| Messages redirect problem | `staff-messages.php` from backup |

Phase 7B backup (`storage/backups/phase7b-pre-deploy-20260621-065446/`) does **not** include `sw.js` or `staff-messages.php` — use Phase 8B pre-deploy backup after first deploy.

---

## 7. Approval gate

| Item | Status |
|------|--------|
| Implementation | **Complete** |
| Local regression tests | **23/23 PASS** |
| Deployment | **BLOCKED — awaiting approval** |
| Login compactness | **Not included** |
| Manifest colour (P8-02) | **Not included** |

**Approve deployment when:** Reviewer confirms scope matches Phase 8A recommendations and manual checklist is acceptable post-deploy.

---

**Related:** [`PHASE8A-RELIABILITY-ROUTING-INVESTIGATION.md`](PHASE8A-RELIABILITY-ROUTING-INVESTIGATION.md) · [`PHASE8-POST-DEPLOY-VALIDATION-REPORT.md`](PHASE8-POST-DEPLOY-VALIDATION-REPORT.md)
