# PHASE 6 — Complete Authentication & Full System Verification

**Date:** 18 June 2026  
**Harness:** `scripts/phase6-full-verification.ps1` + `scripts/phase2-authenticated-audit.ps1`  
**Evidence bundle:** `docs/phase6-audit-2026-06-18/`

---

## Executive summary

| Metric | Count |
|--------|------:|
| **Total pages/routes discovered** | **167 admin** + **81 probed** (61 admin + 15 staff + 5 apply) + **10 public** |
| **Total pages tested (HTTP)** | **91** |
| **Mobile API routes tested** | **27** (+ 9 auth deep probes) |
| **Android screens in codebase** | **22** |
| **Android screens device-tested** | **0** |
| **PASS (automated)** | **42** |
| **WARNING** | **76** |
| **FAIL** | **9** |
| **Screenshots captured** | **0** (blocked) |
| **Production readiness** | **~24% authenticated / ~82% infrastructure** |

**Phase 6 cannot be marked complete** — authentication requires physical device proof; admin/staff UI requires session cookies; screenshots unavailable.

---

## TASK 1 — Authentication

| Flow | API route | Android screen | Automated result | Device | Screenshot | Logcat |
|------|-----------|----------------|------------------|--------|------------|--------|
| Email OTP send | `POST /auth/otp/send` | `EmailSignIn` → `OtpVerification` | **PASS** — returns `STAFF_NOT_FOUND` for unknown email | **NOT TESTED** | BLOCKED | BLOCKED |
| Email OTP verify | `POST /auth/otp/verify` | `OtpVerification` | **PASS** — `INVALID_OTP` for bad code | **NOT TESTED** | BLOCKED | BLOCKED |
| Google Sign-In | `POST /auth/google` | `LoginScreen` | **PASS** — `INVALID_GOOGLE_TOKEN` for bad token | **NOT TESTED** | BLOCKED | BLOCKED |
| Token refresh | `POST /auth/refresh` | (session layer) | **PASS** — 401 without token | **NOT TESTED** | BLOCKED | BLOCKED |
| Logout | `POST /auth/logout` | Settings | **PASS** — 200 `Signed out` | **NOT TESTED** | BLOCKED | BLOCKED |
| Profile after login | `GET /me` | `Profile` | **PASS** — 401 unauthenticated | **NOT TESTED** | BLOCKED | BLOCKED |
| Config flags | `GET /config` | `LoginViewModel` | **PASS** — OTP+Google both enabled | N/A | N/A | N/A |

### Google Sign-In blocker (unchanged)

| Check | Status |
|-------|--------|
| `oauth_client` in `google-services.json` | **EMPTY** — SHA not registered in Firebase |
| Play App Signing SHA | **Unverified** |
| Account picker on device | **Not tested** |

**Root cause:** Firebase SHA fingerprints not saved for `com.olasentra.app`.  
**Fix:** Add all SHA values in Firebase Console → re-download `google-services.json` → rebuild APK → device test.

---

## TASK 2 — Mobile App (Android)

**22 screens defined** in `Routes.kt`. **0 device-tested.**

| Screen | Code exists | Device | API backing | Status |
|--------|-------------|--------|-------------|--------|
| Splash | Yes | NOT TESTED | `GET /config` | **WARNING** |
| Login | Yes | NOT TESTED | `/config` | **WARNING** |
| Email OTP | Yes | NOT TESTED | `/auth/otp/*` | **WARNING** |
| Google Login | Yes | NOT TESTED | `/auth/google` | **FAIL** (Firebase SHA) |
| Dashboard | Yes | NOT TESTED | `GET /dashboard` | **WARNING** |
| Shifts | Yes | NOT TESTED | `GET /shifts` | **WARNING** |
| GPS Check-In | Yes | NOT TESTED | `POST /checkin` | **WARNING** |
| Messages | Yes | NOT TESTED | `GET /messages` | **WARNING** |
| Notifications | Yes | NOT TESTED | `GET /notifications` | **WARNING** |
| Documents | Yes | NOT TESTED | `GET /documents` | **WARNING** |
| Availability | Yes | NOT TESTED | `GET /availability` | **WARNING** |
| Profile | Yes | NOT TESTED | `GET /me` | **WARNING** |
| Edit Profile | Yes | NOT TESTED | `PATCH /me` | **WARNING** |
| Settings | Yes | NOT TESTED | — | **WARNING** |
| Change Password | Yes | NOT TESTED | `POST /me/password` | **WARNING** |
| Logout | Yes | NOT TESTED | `POST /auth/logout` | **WARNING** |

All Mobile API routes return **401/422** (exist) — **26/27 PASS** in route matrix.

---

## TASK 3 — Admin Portal

**61 nav pages probed** (from `admin-capabilities.php`).

| Result | Count |
|--------|------:|
| PASS (authenticated UI) | **0** |
| WARNING (auth redirect OK) | **61** |
| FAIL | **0** |

**Note:** All admin pages redirect to login when unauthenticated (expected). Authenticated content not verified without session.

**Known issue:** 674-byte null prefix on login redirect HTML (server `config.php` corruption risk) — affects all unauthenticated probes showing 4823-byte login page.

**Mobile Portal:** Restored and deployed (Phase 5). `settings-mobile-portal.php` live.

---

## TASK 4 — Staff Portal

**15 pages probed** on register.olasentra.com.

| Page | URL | HTTP | Status | Notes |
|------|-----|------|--------|-------|
| Staff app | `/staff-app.php` | 200 | **PASS** | PWA entry |
| Dashboard | `/staff-dashboard.php` or portal | varies | **WARNING** | Auth required |
| Shifts | `/staff-shifts.php` | 200/302 | **WARNING** | File exists locally |
| Check-in | `/staff-checkin.php` | 200/302 | **WARNING** | File exists |
| Messages | `/staff-messages.php` | 200/302 | **WARNING** | File exists |
| Notifications | `/staff-notifications.php` | 200/302 | **WARNING** | File exists |
| Availability | `/staff-availability.php` | **404** | **FAIL** | No root PHP file — may be in `staff-self-service.php` |
| Leave | `/staff-leave.php` | **404** | **FAIL** | No root PHP file |
| Documents | `/staff-documents.php` | **404** | **FAIL** | No root PHP file |
| Certificates | `/staff-certificates.php` | **404** | **FAIL** | No root PHP file |
| Settings | `/staff-settings.php` | **404** | **FAIL** | No root PHP file |
| Change Password | `/staff-change-password.php` | **404** | **FAIL** | No root PHP file |
| Support | `/staff-support.php` | **404** | **FAIL** | No root PHP file |

**Root cause for 404s:** Audit inventory lists standalone PHP URLs that were never deployed or were consolidated into `staff-self-service.php` / staff-app SPA. **Verify nav in staff-app** before treating as missing features.

---

## TASK 5 — apply.olasentra.com

| Check | Status |
|-------|--------|
| Home page | **PASS** (200) |
| Admin login | **PASS** (200) |
| Full application workflow | **BLOCKED** — requires form submission + DB + email |

---

## TASK 6 — Public website

| URL | Status |
|-----|--------|
| olasentra.com/ | **PASS** |
| olasentra.com/contact.php | **PASS** |
| olasentra.com/about.php | **FAIL** — 404 |
| olasentra.com/services.php | **FAIL** — 404 |
| register.olasentra.com/ | **PASS** |
| register.olasentra.com/privacy.php | **PASS** |
| register.olasentra.com/terms.php | **PASS** |
| apply.olasentra.com/ | **PASS** |

---

## Issue register (actionable)

| # | Severity | File/URL | Root cause | Fix |
|---|----------|----------|------------|-----|
| 1 | **P0** | Google Sign-In | `oauth_client: []` in google-services.json | Register SHA in Firebase; re-download JSON |
| 2 | **P0** | Auth device test | No physical device session | Install APK; test OTP + Google |
| 3 | **P1** | olasentra.com/about.php | 404 on production | Deploy or fix nav link |
| 4 | **P1** | olasentra.com/services.php | 404 on production | Deploy or fix nav link |
| 5 | **P1** | Staff portal URLs | Audit lists non-existent root PHP files | Map to `staff-self-service.php` routes or restore files |
| 6 | **P2** | Admin login HTML | 674-byte null prefix | Server `config.php` investigation |
| 7 | **P2** | Authenticated UI | No session cookies in harness | Manual admin/staff walkthrough with screenshots |

---

## Totals

| Category | Discovered | Tested | PASS | WARN | FAIL |
|----------|----------:|-------:|-----:|-----:|-----:|
| Admin pages | 61 | 61 | 0 | 61 | 0 |
| Staff pages | 15+ | 15 | 0 | 8 | 7 |
| Apply pages | 5 | 5 | 0 | 5 | 0 |
| Public pages | 10 | 10 | 8 | 0 | 2 |
| Mobile API | 27 | 27 | 26 | 1 | 0 |
| Auth API (deep) | 9 | 9 | 8 | 0 | 1* |
| Android screens | 22 | 0 | 0 | 0 | 22** |

\* OTP send returns HTTP 404 with `STAFF_NOT_FOUND` body — route exists (harness false positive).  
\*\* Device testing not performed — treated as unverified.

---

## Re-run verification

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\phase6-full-verification.ps1
powershell -ExecutionPolicy Bypass -File .\scripts\verify-google-signin-config.ps1
```

**Phase 6 status: OPEN** — Complete when both auth methods reach dashboard on device + authenticated portal walkthrough with screenshots.
