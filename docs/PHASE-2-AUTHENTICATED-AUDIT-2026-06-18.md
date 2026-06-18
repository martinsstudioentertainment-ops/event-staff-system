# PHASE 2 DEEP AUDIT — Authenticated Page-by-Page Verification

**Date:** 18 June 2026  
**Baseline:** Phase 1 discovery (`docs/FULL-SYSTEM-AUDIT-2026-06-16.md`, `.audit-admin.json` — 167 admin PHP files)  
**Harness:** `scripts/phase2-authenticated-audit.ps1`  
**Evidence bundle:** `docs/phase2-audit-2026-06-18/` (CSV + `summary.json`)

---

## Executive summary

Phase 2 ran **live production HTTP probes** against the Phase 1 inventory. **Authenticated UI verification and screenshots are BLOCKED** in this environment because:

1. No admin or staff session cookies / JWT tokens were supplied
2. No browser automation (Playwright/npx) available for screenshot capture
3. No physical Android device or emulator attached for screen recordings
4. Full apply recruitment workflow (uploads, email, DB) requires interactive submission

### What was verified with evidence (18 Jun 2026)

| Area | Probed | Structural pass | Authenticated pass | Screenshots |
|------|--------|-----------------|-------------------|-------------|
| Admin portal (61 nav pages) | 61 | 59 auth-gate OK | **0** — blocked | **0** — blocked |
| Staff portal (15 routes) | 15 | 8 reachable / 7 missing routes | **0** — blocked | **0** — blocked |
| Apply workflow (5 URLs) | 5 | 5 reachable | **0** — blocked | **0** — blocked |
| Mobile API (27 routes) | 27 | **26 pass** | **0** with token | **0** — blocked |
| Android app (14 screens) | 14 code routes | NavHost wired | **0** device tests | **0** — blocked |
| Google Login | OAuth init | Redirect to Google OK | **0** successful login | **0** — blocked |

### Production readiness (Phase 2)

| Metric | Value |
|--------|-------|
| Pages tested (HTTP probe) | **81** |
| Pages PASS (authenticated UI) | **0** |
| Pages WARNING (auth gate / corruption risk) | **74** |
| Pages FAIL | **7** |
| APIs tested | **27** |
| APIs PASS (route exists, expected status) | **26** |
| APIs FAIL | **0** |
| Zero-byte admin files (local repo) | **37** (22 in nav probe set) |
| **Authenticated production readiness** | **~35%** (API layer strong; UI layer unverified + corruption) |
| **Play Store readiness (unchanged until device pass)** | **~68%** per Phase 1, pending device Google login |

---

## Critical blockers before audit can close

| ID | Blocker | Impact |
|----|---------|--------|
| B-01 | **No authenticated sessions** | Cannot verify tables, filters, forms, AJAX, exports while logged in |
| B-02 | **674-byte null prefix on all PHP responses** | Systemic file corruption; may break parsers/browsers |
| B-03 | **37 admin PHP files 0 bytes locally** | Deploy from local tree risks re-wiping production |
| B-04 | **2 admin pages return null-only body (80–82 B)** | `communication-centre.php`, `contracts-centre.php` — **FAIL even unauthenticated** |
| B-05 | **Staff web routes 404** | `staff-availability`, `staff-leave`, `staff-documents`, `staff-certificates`, `staff-settings`, `staff-change-password`, `staff-support` — web staff uses hub + mobile API instead |
| B-06 | **Screenshots/recordings** | Cannot be produced without browser session + device |

---

## ADMIN PORTAL — 61 navigation pages probed

**Login URL:** `https://admin.olasentra.com/admin/login.php`  
**Unauthenticated probe:** All protected pages correctly redirect to `login.php` (HTTP 200, final URL = login).  
**Screenshot:** BLOCKED — no session cookie  
**Permission test:** UNVERIFIED — requires Admin login

### Confirmed FAIL (production evidence)

| Page | URL | Status | Evidence |
|------|-----|--------|----------|
| Communications centre | `/admin/communication-centre.php` | **FAIL** | HTTP 200, body **82 bytes**, all null characters — blank page |
| Contracts centre | `/admin/contracts-centre.php` | **FAIL** | HTTP 200, body **80 bytes**, all null characters — blank page |

### High-risk WARNING (0-byte local file — likely blank when authenticated)

| Page | URL | Local bytes |
|------|-----|-------------|
| Messages | `/admin/staff-inbox.php` | 0 |
| Communication Hub | `/admin/communication-hub.php` | 0 |
| Performance | `/admin/workforce-performance.php` | 0 |
| Risk management | `/admin/workforce-risk.php` | 0 |
| Event staffing | `/admin/event-staffing.php` | 0 |
| Compliance | `/admin/compliance-centre.php` | 0 |
| Documents | `/admin/staff-documents.php` | 0 |
| Smart search | `/admin/staff-search.php` | 0 |
| Availability | `/admin/staff-availability.php` | 0 |
| Executive | `/admin/executive-dashboard.php` | 0 |
| Audit logs | `/admin/compliance-audit.php` | 0 |
| Event rostering | `/admin/event-rostering.php` | 0 |
| Recruitment | `/admin/recruitment-centre.php` | 0 |
| Training | `/admin/training-centre.php` | 0 |
| Incidents | `/admin/incident-centre.php` | 0 |
| Clients | `/admin/client-centre.php` | 0 |
| Data integrity | `/admin/data-integrity.php` | 0 |
| Website CMS | `/admin/website-global.php` | 0 |
| Geo audits | `/admin/geo-audits.php` | 0 |
| Mobile portal | `/admin/mobile-portal.php` | 0 |

### Core pages — auth gate OK, authenticated UI UNVERIFIED

All remaining admin nav pages (Dashboard, Queue, Events, Attendance, Invoices, Settings, etc.) redirect to login with no PHP fatal errors. Full per-page CSV: `docs/phase2-audit-2026-06-18/admin-pages.csv`.

**Tables / filters / search / forms / AJAX / exports / reports:** NOT TESTED — requires authenticated Admin session.

---

## STAFF PORTAL — 15 routes probed

**Base:** `https://register.olasentra.com`

| Page | URL | Status | Notes | Screenshot |
|------|-----|--------|-------|------------|
| Dashboard / Home | `staff-app.php` | **WARNING** | Sign-in gate renders (9013 B); null-byte prefix | BLOCKED |
| Shifts | `staff-shifts.php` | **WARNING** | Redirects to sign-in; local file corrupt (374 B) | BLOCKED |
| Check-In | `staff-checkin.php` | **WARNING** | Redirects to sign-in; local 0 B | BLOCKED |
| Messages | `staff-messages.php` | **WARNING** | Renders "Messages \| Olasentra" (5588 B) without session — email gate | BLOCKED |
| Notifications | `staff-notifications.php` | **WARNING** | Redirects to sign-in | BLOCKED |
| Profile | `staff-profile-hub.php` | **WARNING** | Redirects to sign-in | BLOCKED |
| Profile legacy | `staff-profile.php` | **WARNING** | Redirects to sign-in | BLOCKED |
| Google sign-in | `staff-google-signin.php` | **WARNING** | OAuth redirect to Google initiated (client_id present) | BLOCKED |
| Availability | `staff-availability.php` | **FAIL** | HTTP 404 — use Mobile API `/availability` |
| Leave Requests | `staff-leave.php` | **FAIL** | HTTP 404 — use Mobile API `POST /leave` |
| Documents | `staff-documents.php` | **FAIL** | HTTP 404 — use Mobile API `/documents` |
| Certificates | `staff-certificates.php` | **FAIL** | HTTP 404 — use Mobile API `/documents` |
| Settings | `staff-settings.php` | **FAIL** | HTTP 404 — no dedicated web route |
| Change Password | `staff-change-password.php` | **FAIL** | HTTP 404 — use Mobile API `POST /me/password` |
| Support | `staff-support.php` | **FAIL** | HTTP 404 — no dedicated web route |

Full CSV: `docs/phase2-audit-2026-06-18/staff-pages.csv`

---

## APPLY.OLASENTRA.COM — recruitment workflow

| Step | URL | Probe status | Authenticated test |
|------|-----|--------------|-------------------|
| Apply home | `https://apply.olasentra.com/` | **WARNING** — redirects to registration wizard | BLOCKED |
| Registration wizard | `register.olasentra.com/index.php` | **WARNING** — 99 KB HTML, wizard loads | BLOCKED |
| Submit handler | `register.olasentra.com/submit.php` | **WARNING** — redirects to index (no POST) | BLOCKED |
| Status page | `register.olasentra.com/status.php` | **WARNING** — 6 KB HTML | BLOCKED |
| Admin receipt | `admin/apply-portal.php` | **WARNING** — auth gate to login | BLOCKED |

**Not tested (requires interactive session):** personal details save, CV upload, ID upload, PSA upload, certificate upload, submission confirmation, email delivery, admin receipt, database save.

Phase A7 registration wizard QA (06 Jun 2026) remains the best prior evidence for wizard steps 1–8: `docs/phase-a7-qa-results.json`.

---

## ANDROID APP — screen inventory (code review + device BLOCKED)

**Canonical app:** `android/olasentra-staff` — v1.0.17 (versionCode 17)  
**Package:** `com.olasentra.app`

| Screen | Route | Code present | Device test | Screenshot |
|--------|-------|--------------|-------------|------------|
| Splash | `splash` | Yes | BLOCKED | BLOCKED |
| Login | `login` | Yes | BLOCKED | BLOCKED |
| Email OTP | `email_sign_in` → `otp_verification` | Yes | BLOCKED | BLOCKED |
| Google Login | LoginScreen Credentials API | Yes | BLOCKED | BLOCKED |
| Dashboard | `dashboard` | Yes | BLOCKED | BLOCKED |
| Messages | `messages` | Yes | BLOCKED | BLOCKED |
| Notifications | `notifications` | Yes | BLOCKED | BLOCKED |
| Documents | `documents` | Yes | BLOCKED | BLOCKED |
| Availability | `availability` | Yes | BLOCKED | BLOCKED |
| GPS Check-In | `check_in` | Yes | BLOCKED | BLOCKED |
| Profile | `profile` | Yes | BLOCKED | BLOCKED |
| Settings | `settings` | Yes | BLOCKED | BLOCKED |
| Change Password | `change_password` | Yes | BLOCKED | BLOCKED |
| Logout | Profile/Settings | Yes | BLOCKED | BLOCKED |

**Note:** `olasentra-fresh` is a reduced scaffold (Login/OTP/Dashboard only) — not the Play Store candidate.

---

## MOBILE API — 27 routes probed (no token)

**Base:** `https://register.olasentra.com/api/mobile/v1`

### Live config (GET `/config` — 200 JSON)

```json
{"ok":true,"mobile_api_enabled":true,"google_signin_enabled":true,"google_signin_required":true,"email_otp_enabled":true,"gps_attendance_v2_enabled":true,"features":{"availability":true,"shift_response":true,"offline_sync":true}}
```

### Route matrix

| Route | Method | Code | Status |
|-------|--------|------|--------|
| `config` | GET | 200 | **PASS** |
| `auth/otp/send` | POST | 422 | **PASS** |
| `auth/otp/verify` | POST | 422 | **PASS** |
| `auth/google` | POST | 422 | **PASS** |
| `auth/refresh` | POST | 401 | **PASS** |
| `auth/logout` | POST | 200 | **WARNING** — accepts empty body without token |
| `me` | GET | 401 | **PASS** |
| `me` | PATCH | 401 | **PASS** |
| `me/password` | POST | 401 | **PASS** — **fixed on production** (was 404 in Phase 1) |
| `dashboard` | GET | 401 | **PASS** |
| `shifts`, `shifts/today` | GET | 401 | **PASS** |
| `checkin`, `checkout` | POST | 401 | **PASS** |
| `gps/ping`, `gps/status` | GET/POST | 401 | **PASS** |
| `notifications`, `messages`, `documents`, `availability` | GET | 401 | **PASS** |
| `leave`, `sync/offline`, `events`, `events/register`, `push/register` | POST | 401 | **PASS** |

**Local repo gap:** `includes/mobile/mobile-router.php` still has **no `me/password` route** — production is ahead of local code.

Full CSV: `docs/phase2-audit-2026-06-18/mobile-api.csv`

**Authenticated JSON/validation/permissions:** BLOCKED — requires Bearer token from OTP or Google login.

---

## GOOGLE LOGIN — highest priority

| Check | Status | Evidence |
|-------|--------|----------|
| Mobile API `google_signin_enabled` | **PASS** | `/config` returns `true` |
| Web OAuth initiation | **PASS** | `staff-google-signin.php` redirects to `accounts.google.com` with `client_id=603350887421-...` |
| `google-services.json` (staff) | **PASS** | Present, 683 bytes |
| `google-services.json` (fresh) | **PASS** | Present, 683 bytes |
| `GOOGLE_WEB_CLIENT_ID` in build | **PASS** | `build.gradle.kts` reads `local.properties` |
| Release signing config | **PASS** | `keystore.properties` + `signingConfigs.release` wired |
| SHA-1 / SHA-256 in Firebase console | **UNVERIFIED** | Requires Firebase console screenshot |
| Successful Google login (Android) | **UNVERIFIED** | Requires device screenshot |
| Successful Google login (web staff) | **UNVERIFIED** | Requires OAuth callback completion |
| `staff-google-signin.php` local | **FAIL** | 0 bytes — restore before deploy |
| `staff-google-oauth-callback.php` local | **FAIL** | 0 bytes — restore before deploy |

---

## Database issues

| Check | Status |
|-------|--------|
| Direct DB query during audit | **Not performed** — no credentials |
| Apply submission save | **UNVERIFIED** |
| Admin receipt of application | **UNVERIFIED** |
| `config.php` local | **0 bytes** — never deploy from local |

---

## Permission issues

| Surface | Status |
|---------|--------|
| Admin role matrix (Manager vs Admin) | **UNVERIFIED** — no session |
| Staff token scoping | **UNVERIFIED** — no JWT |
| API 401 enforcement | **PASS** — all protected routes reject unauthenticated calls |

---

## Deliverables index

| Deliverable | Location |
|-------------|----------|
| Phase 2 report (this file) | `docs/PHASE-2-AUTHENTICATED-AUDIT-2026-06-18.md` |
| Admin page results | `docs/phase2-audit-2026-06-18/admin-pages.csv` |
| Staff page results | `docs/phase2-audit-2026-06-18/staff-pages.csv` |
| Apply workflow probes | `docs/phase2-audit-2026-06-18/apply-workflow.csv` |
| Mobile API matrix | `docs/phase2-audit-2026-06-18/mobile-api.csv` |
| Android/Google structural | `docs/phase2-audit-2026-06-18/android-google-structural.csv` |
| Summary JSON | `docs/phase2-audit-2026-06-18/summary.json` |
| Re-run harness | `scripts/phase2-authenticated-audit.ps1` |
| Phase 1 baseline | `docs/FULL-SYSTEM-AUDIT-2026-06-16.md` |

---

## To close Phase 2 (required inputs)

Provide **one** of the following so authenticated verification + screenshots can complete:

1. **Admin OTP session** — complete admin login; export `PHPSESSID` cookie, or run manual pass with screenshot folder
2. **Staff JWT** — `POST /auth/otp/verify` or `/auth/google` response token for API authenticated matrix
3. **Android device** — install release APK v1.0.17; capture Google login screenshot + screen recording per screen list
4. **Apply test applicant** — permission to submit one test application through full workflow

Re-run with token support:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\phase2-authenticated-audit.ps1
```

---

## Audit status: **OPEN**

Phase 2 structural probes are complete. **Authenticated workflows remain unverified.** The audit cannot close until manual/device sessions produce screenshot evidence for every workflow listed in the Phase 2 brief.
