# Phase 8B — Reliability Deployment Report

**Verdict:** **DEPLOYMENT SUCCESSFUL**

**Deployed at:** 2026-06-21T07:29:37+01:00  
**Target:** `register.olasentra.com` (`public_html` via FTP)  
**Scope:** P8-01 Service Worker reliability · P8-04 Application Status label · P8-05 Guest messages redirect

**Rollback status:** Not required — all uploads verified by size + SHA-256; production HTTP probes pass.

---

## Pre-deploy requirements

| Requirement | Result |
|-------------|--------|
| Deploy safety gate run | **PASS** |
| `deploy_allowed = true` | **PASS** (`docs/phase2-deploy-safety-gate.json`, checked 2026-06-21) |
| Phase 8B static regression tests | **PASS** — 23/23 |
| Full FTP backup (3/3 files) | **PASS** |
| SHA-256 recorded | **PASS** — see tables below |
| Production copies exist | **PASS** — all paths downloaded before upload |

**Protection rules respected:** No attendance, clock-in, GPS, BIB, login, OAuth, OTP, database, or API file changes in deploy bundle.

---

## 1. Deployment file manifest (3 files)

| # | File | Local SHA-256 (deployed) | Verified on production |
|---|------|--------------------------|------------------------|
| 1 | `sw.js` | `ed8084a283a3af18e735732c49c3d92feb470e788aed8c9515d0a7b0c4d30144` | **MATCH** |
| 2 | `includes/staff-app-v3-pages.php` | `9a019e88dc459f6c747488e2d8527d560ca35cc7608a94e2890a92f48da32baf` | **MATCH** |
| 3 | `staff-messages.php` | `ca5f1db343667bcd82fb3a16217f8f49314a8853bbd71d0824f6068eaf0f51b6` | **MATCH** |

**Note:** Pre-deploy production backup already contained Phase 8B content (same hashes before/after upload). Formal deploy pipeline re-uploaded and re-verified idempotently.

Full deploy metadata: `storage/backups/phase8b-pre-deploy-20260621-072919/deploy-result.json`

---

## 2. Backup location

```
storage/backups/phase8b-pre-deploy-20260621-072919/
├── manifest.json
├── deploy-result.json
├── post-deploy-verify/          (FTP re-download after upload)
├── sw.js                        (pre-deploy production copy)
├── staff-messages.php
└── includes/staff-app-v3-pages.php
```

**Rollback:** Re-upload files from backup root (not `post-deploy-verify/`) via FTP to same remote paths, or run:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase8b-reliability.ps1
```

(restore local copies from backup first if rolling back content)

---

## 3. Post-deploy verification results

### Automated HTTP probes (2026-06-21)

| Test | Method | Result |
|------|--------|--------|
| **P8-01** SW cache `event-staff-v10-v3-staff-pwa` | GET `sw.js` | **PASS** |
| **P8-01** Precache includes `staff-app-v3.css` | GET `sw.js` | **PASS** |
| **P8-01** Offline PWA styling (`offline.php`) | GET `offline.php` — `es-ds__empty`, no legacy `staff-app.css` | **PASS** |
| **P8-04** Application Status label | Pre-deploy backup + deployed file contain `Application status`; old `View Roster` absent | **PASS** |
| **P8-05** Guest messages redirect | GET `staff-messages.php` → **302** `staff-app.php?return=staff-messages.php` | **PASS** |
| **P8-05** Signed-in messages path | Static test: v3 shell gate + token path preserved; guest no longer sees legacy lookup | **PASS** (code + static) |
| Google Login | GET `staff-app.php` — `Sign in with Google` present | **PASS** |
| Email OTP UI | GET `staff-app.php` — OTP section + `#staff-portal-email-otp` | **PASS** |
| Email OTP API | GET `api/staff-portal-otp-send.php` → **405** (endpoint exists, GET rejected) | **PASS** |

### Device-required (not blocking deploy)

| Test | Status |
|------|--------|
| Fresh PWA install → airplane mode → v3 login styling from SW precache | **DEVICE REQUIRED** — SW updated; users may need one refresh/reinstall for new cache name |
| Signed-in home → **Application status** opens `status.php` | **DEVICE REQUIRED** — needs portal session |
| Signed-in Messages bottom nav (v3 dark UI) | **DEVICE REQUIRED** — needs portal session |

---

## 4. Change summary (deployed)

| Fix | Change |
|-----|--------|
| P8-01 | Cache bumped to `event-staff-v10-v3-staff-pwa`; v3 CSS/JS precached; legacy v1/v2 CSS removed from install precache |
| P8-04 | Home action label **View Roster** → **Application status** (href unchanged) |
| P8-05 | Guest GET `/staff-messages.php` → 302 to `staff-app.php?return=staff-messages.php` |

---

## 5. Risk assessment (post-deploy)

| Area | Risk | Status |
|------|------|--------|
| Login / OAuth / OTP | None expected | **PASS** — unchanged files; HTTP probes confirm UI + API |
| Attendance / GPS / BIB | None expected | **PASS** — not in deploy bundle |
| Guest messages | Low | **PASS** — redirect confirmed; token deep links preserved in code |
| PWA offline styling | Low | **PASS (HTTP)** — SW + offline page; device SW activation pending |

---

## 6. Rollback triggers (monitor)

Restore from backup if any of: login broken, signed-in messages inaccessible, PWA fails to register SW, offline page unstyled after fresh install.

**Current status:** No rollback triggers observed.

---

**Related:** [`PHASE8B-RELIABILITY-IMPLEMENTATION-REPORT.md`](PHASE8B-RELIABILITY-IMPLEMENTATION-REPORT.md) · [`PHASE8-POST-DEPLOY-VALIDATION-REPORT.md`](PHASE8-POST-DEPLOY-VALIDATION-REPORT.md)
