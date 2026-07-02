# Phase 10 — Usability Deployment Report

**Verdict:** **DEPLOYMENT SUCCESSFUL**

**Deployed at:** 2026-06-21T07:42:43+01:00  
**Target:** `register.olasentra.com` (`public_html` via FTP)  
**Scope:** P10-01 Manifest theme · P10-02 Single install · P10-03 Status v3 · P10-04 Profile v3

**Rollback status:** Not required — all 8 uploads verified by size + SHA-256; production HTTP probes pass.

---

## Pre-deploy requirements

| Requirement | Result |
|-------------|--------|
| Deploy safety gate run | **PASS** |
| `deploy_allowed = true` | **PASS** (`docs/phase2-deploy-safety-gate.json`, checked 2026-06-21T07:42:23) |
| Phase 10 static regression tests | **PASS** — 31/31 |
| Phase 5C login parity regression | **PASS** — 29/29 |
| Full FTP backup (8/8 files) | **PASS** |
| SHA-256 recorded | **PASS** — see tables below |
| Production copies exist | **PASS** — all paths downloaded before upload |

**Protection rules respected:** No attendance, clock-in, GPS, BIB, authentication, OAuth, OTP logic, database, or API file changes in deploy bundle.

---

## 1. Deployment file manifest (8 files)

| # | File | Pre-deploy SHA-256 | Deployed SHA-256 | Verified on production |
|---|------|-------------------|------------------|------------------------|
| 1 | `manifest.php` | `1ce7efc02c3a67652120bbd8cdbaed9fbd3db75d6532345ffc23cf204fb03466` | `4a9a8d720384124472494ef501436ca644b3304daf359a7b434b6d27b64b31b1` | **MATCH** |
| 2 | `assets/js/pwa-install.js` | `dc8d38e7f375103e90aec13db6260d44c70da00fa3485054cdb3b0937b0cb96c` | `7f62853adee323d3da13f6d941e0561c0d7db542ea924e94a9ffb535d9436047` | **MATCH** |
| 3 | `assets/js/staff-app-v3.js` | `0ee324f7c4c09461a7cbd0452c9b8658c53ea7503d351b6a50d09ec856fee2ac` | `40257f324b3d0d40ee3a58cec1fcacbf0fadb1c7eb7afece1f0a9e4f977d2da8` | **MATCH** |
| 4 | `assets/css/staff-app-v3.css` | `9e6576850c29c1a0c8f6a49acb46d6da739a9cf89c3af45f7a7a77b702d2e095` | `dc11d46378bcc53b24bee603e8a84a90b84c27723fa30238c42c9383e20457ed` | **MATCH** |
| 5 | `includes/staff-app-v3-shell.php` | `1812996bee04fecdc222364964b5c8b0170812b4382aa8fddd97298311e48c68` | `8ed4bc1c9b16afa28ccec7463f6b3828b646da645626cb183a31ceed5c9a6050` | **MATCH** |
| 6 | `includes/staff-app-v3-pages.php` | `9a019e88dc459f6c747488e2d8527d560ca35cc7608a94e2890a92f48da32baf` | `aeffb5c86e46b8004ab7d3f3e89a56044545b4a4421ef4616003500ff031b5dd` | **MATCH** |
| 7 | `status.php` | `0615f9f28cb4422ef05b847f284f819ca945fa4311afdd512ed5dc53a5c40d6b` | `3b8a315770259ac39cec46bd28fbee16a41b2c7575639d0d107a1e69efcb6f07` | **MATCH** |
| 8 | `staff-profile.php` | `8e45bde0a483e8a650d487971de19543ce342f50f3ff177ad334f6565eaa9717` | `b1088caaeaea8de60cce10eecaafee493f463ea43ffb18a8843250f495e08692` | **MATCH** |

Full deploy metadata: `storage/backups/phase10-pre-deploy-20260621-074213/deploy-result.json`

---

## 2. Backup location

```
storage/backups/phase10-pre-deploy-20260621-074213/
├── manifest.json
├── deploy-result.json
├── post-deploy-verify/              (FTP re-download after upload — hash verified)
├── manifest.php                     (pre-deploy production copy)
├── status.php
├── staff-profile.php
├── assets/js/pwa-install.js
├── assets/js/staff-app-v3.js
├── assets/css/staff-app-v3.css
├── includes/staff-app-v3-shell.php
└── includes/staff-app-v3-pages.php
```

**Rollback:** Re-upload the 8 pre-deploy files from backup root (not `post-deploy-verify/`) via FTP to the same remote paths.

---

## 3. Post-deploy verification results

### Automated HTTP probes (2026-06-21T07:43+)

| Test | Method | Result |
|------|--------|--------|
| **Login page** | GET `staff-app.php` — v3 shell, Google + OTP UI | **PASS** |
| **Google Login** | GET `staff-app.php` — `Sign in with Google` present | **PASS** |
| **Email OTP UI** | GET `staff-app.php` — OTP section + `staff-portal-email-otp.js` | **PASS** |
| **Email OTP API** | GET `api/staff-portal-otp-send.php` → **405** (endpoint live, GET rejected) | **PASS** |
| **Application Status** | GET `status.php` — v3 CSS, `Application status`, `es-v3` | **PASS** |
| **Profile Edit** | GET `staff-profile.php` — HTTP 200, v3 assets (auth gate → login when guest) | **PASS** |
| **Install Prompt (shell)** | GET `staff-app.php` — `es-v3-pwa-banner`, `es-v3-pwa-install` | **PASS** |
| **Install Prompt (JS)** | GET `assets/js/staff-app-v3.js` — single banner + `beforeinstallprompt` | **PASS** |
| **Legacy install skip** | GET `assets/js/pwa-install.js` — v3 early return | **PASS** |
| **Manifest colours** | GET `manifest.php` — `theme_color` `#F58220`, `background_color` `#0B1020` | **PASS** |
| **Offline PWA** | GET `offline.php` — `staff-app-v3.css`, `es-ds__empty` | **PASS** |
| **Service worker** | GET `sw.js` — HTTP 200, offline route present | **PASS** |

### Device-required (not blocking deploy)

| Test | Status |
|------|--------|
| **Installed PWA** — splash/status bar uses `#F58220` / `#0B1020` | **DEVICE REQUIRED** — manifest updated; reinstall or refresh may be needed |
| **Install Prompt** — single orange banner on mobile Chrome | **DEVICE REQUIRED** |
| **Email OTP** — send/receive 6-digit code in inbox | **DEVICE REQUIRED** — API reachable; live send needs mailbox test |
| **Profile Edit** — signed-in form save | **DEVICE REQUIRED** — needs portal session |
| **Application Status** — token lookup + PSA update | **DEVICE REQUIRED** — needs valid token or session |

---

## 4. Change summary (deployed)

| Item | Change |
|------|--------|
| P10-01 | Manifest `theme_color` `#F58220`, `background_color` `#0B1020` (fixed brand tokens) |
| P10-02 | Single install flow — legacy `pwa-install.js` skipped on v3; one `es-v3-pwa-banner` only |
| P10-03 | `status.php` migrated to Olasentra v3 shell/renderer; lookup/PSA logic unchanged |
| P10-04 | `staff-profile.php` migrated to Olasentra v3 shell/renderer; validation/save unchanged |

---

## 5. Risk assessment (post-deploy)

| Area | Risk | Status |
|------|------|--------|
| Login / OAuth / OTP | None expected | **PASS** — unchanged auth files; UI + API probes OK |
| Attendance / GPS / BIB | None expected | **PASS** — not in deploy bundle |
| Status / Profile logic | Low | **PASS** — presentation only; handlers preserved |
| PWA install UX | Low | **PASS (HTTP)** — single banner deployed; device confirmation pending |
| Manifest / installed colours | Low | **PASS (HTTP)** — colours confirmed in live manifest |

---

## 6. Rollback triggers (monitor)

Restore from backup if any of: login broken, Google sign-in fails, OTP send returns 5xx, status lookup broken, profile save broken, duplicate install banners return, PWA fails to register SW.

**Current status:** No rollback triggers observed.

---

**Related:** [`PHASE10-USABILITY-IMPLEMENTATION-REPORT.md`](PHASE10-USABILITY-IMPLEMENTATION-REPORT.md) · [`PHASE8B-DEPLOYMENT-REPORT.md`](PHASE8B-DEPLOYMENT-REPORT.md)
