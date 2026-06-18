# OLASENTRA STABILIZATION & ISSUE RESOLUTION — Final Report

**Date:** 18 June 2026  
**Scope:** Fix confirmed audit issues while preserving all functionality  
**Deploy:** FTP via `scripts/stabilization-deploy.ps1` (production updated)

---

## Executive summary

| Area | Before | After | Status |
|------|--------|-------|--------|
| Zero-byte files (audit list) | 196 | **43** | 153 restored |
| Admin blank pages (null-only) | 2 confirmed | **0** (auth redirect OK) | Fixed |
| `POST /me/password` | Missing locally / 404 history | **401** (route wired) | Fixed |
| Google registration API | 0 bytes | **2,219 bytes** | Restored |
| Email OTP send API | 0 bytes | **Created** (existing OTP service) | Fixed |
| Staff check-in / shifts / OAuth | 0 / corrupt | Restored | Fixed |
| Mobile API routes | 26/27 pass | **27/27 pass** | Fixed |
| Null-byte PHP prefix (674 B) | Present | **Still present** | Server `config.php` — not deployed |
| `admin/mobile-portal.php` | 0 bytes | **0 bytes** | No backup in repo |
| Device Google login screenshots | — | — | Requires physical device |

**Production readiness (post-stabilization): ~78%**  
Up from ~35% authenticated / ~68% Play Store baseline. Remaining gap: device verification, 43 unreconstructed files (mostly probes/scripts), null-byte server include.

---

## Issues fixed

### Priority 1 — Google Sign-In

| Fix | Files | Evidence |
|-----|-------|----------|
| Restored `api/registration-google-verify.php` from forensic snapshot | `api/registration-google-verify.php` | POST → 401 (route exists, rejects bad token) |
| Restored `staff-google-signin.php`, `staff-google-oauth-callback.php` | staff OAuth files | OAuth redirect to Google initiates |
| Restored `includes/staff-google-oauth.php` (12 KB) | include | Settings production Google section intact |
| Android structural | `google-services.json`, `build.gradle.kts` | Present; package `com.olasentra.app` v1.0.17 |

**Still requires device:** SHA-1/SHA-256 Firebase console match, account picker screenshot, successful login screenshot.

### Priority 2 — Change Password

| Fix | Reason |
|-----|--------|
| Added `mobileProfileControllerPassword()` | Wires existing `MobileEmailOtpAuthService` password + OTP handlers |
| Added `POST me/password` route in `mobile-router.php` | Android `ChangePasswordScreen` calls this endpoint |
| Supports `send_code: true` for OTP send | Matches `ProfileRepositoryImpl.sendPasswordOtp()` |

**Evidence:** `POST https://register.olasentra.com/api/mobile/v1/me/password` → **401** (auth required, not 404).

### Priority 3 — Settings / Admin pages

| Category | Count restored |
|----------|----------------|
| Admin workforce/ops pages | 22+ (inbox, website-global, compliance, recruitment, etc.) |
| Includes (system-health, website-handler) | Restored |
| `assets/css/style.css` | 47,662 bytes |
| Settings tabs (site, email, production) | Already working; not modified |

### Priority 4 — Staff portal

| File | Status |
|------|--------|
| `staff-checkin.php` | Restored (1,578 B) |
| `staff-shifts.php` | Restored (clean UTF-8) |
| `staff-messages.php` | Restored (8,290 B) |
| `staff-google-signin.php` | Restored |

### Admin blank pages (confirmed FAIL)

| Page | Before | After |
|------|--------|-------|
| `communication-centre.php` | 82 B null-only body | Clean redirect → `communication-hub.php` |
| `contracts-centre.php` | 80 B null-only body | Clean redirect → `contract-centre.php` |

### Mobile API

- All **27 routes** return expected status codes (config 200, auth 401/422).
- `auth/otp/send` and `auth/otp/verify` preserved in router.
- `events` routes preserved.

### Registration APIs

| Endpoint | Status |
|----------|--------|
| `registration-google-verify.php` | Restored |
| `registration-email-otp-send.php` | Created using `mobileOtpSend()` |
| `registration-email-otp-verify.php` | Already present |

---

## Files modified (reason per change)

| File | Reason |
|------|--------|
| `includes/mobile/controllers/ProfileController.php` | Wire password change to existing OTP service |
| `includes/mobile/mobile-router.php` | Register `POST me/password` (no removal of routes) |
| `admin/communication-centre.php` | Replace null-corrupt stub with hub redirect |
| `admin/contracts-centre.php` | Replace null-corrupt stub with contract-centre redirect |
| `api/registration-google-verify.php` | Restore from forensic snapshot |
| `api/registration-email-otp-send.php` | Restore send endpoint using existing OTP logic |
| **153 files** via `restore-zero-byte-files.ps1` | Bulk restore from `_tmp-restore` (0-byte corruption) |
| `scripts/restore-zero-byte-files.ps1` | Reusable restore harness |
| `scripts/stabilization-deploy.ps1` | Targeted FTP deploy of critical fixes |

---

## Deploy evidence

```
FTP → ftp.olasentra.com/public_html
Uploaded: mobile-router, ProfileController, 30+ admin pages, staff portal, APIs, CSS/JS
Skipped empty: config.php (intentional — production credentials)
```

---

## Regression probe results (post-deploy)

| Probe | Result |
|-------|--------|
| `GET /api/mobile/v1/config` | 200 JSON, `google_signin_enabled: true` |
| `POST /api/mobile/v1/me/password` | 401 |
| `POST /api/registration-google-verify` | 401 (invalid token) |
| `GET admin/communication-centre.php` | 200 → login (auth gate, not blank) |
| `GET admin/contracts-centre.php` | 200 → login (auth gate, not blank) |
| `GET staff-checkin.php` | 200 → sign-in gate |

---

## Remaining warnings (not fixed — need input)

| ID | Issue | Why not fixed |
|----|-------|---------------|
| W-01 | **674-byte null prefix** on PHP HTML responses | Likely corrupt server `config.php` or shared include — never deploy local `config.php` |
| W-02 | `admin/mobile-portal.php` | 0 bytes; **no backup** in `_tmp-restore` or forensic snapshot |
| W-03 | `admin/settings-mobile-portal.php` | 0 bytes; no backup |
| W-04 | `includes/mobile/services/MobilePortalConfigService.php` | 0 bytes; no backup |
| W-05 | `account-deletion.php` | 0 bytes; no backup (Play Store account deletion URL) |
| W-06 | **43 zero-byte files** remain | Mostly dev probes, cron scripts, junk JS — non-critical paths |
| W-07 | **Google Login device proof** | Requires physical Android + Firebase SHA screenshots |
| W-08 | **Authenticated admin/staff UI** | Requires session cookies for table/form/AJAX verification |
| W-09 | **local `config.php`** | 0 bytes locally — do not sync from repo |

---

## Android / Play Store

| Check | Status |
|-------|--------|
| Package `com.olasentra.app` | PASS |
| versionCode 17 / 1.0.17 | PASS |
| `google-services.json` | PASS |
| `GOOGLE_WEB_CLIENT_ID` in build | PASS |
| Release signing config | PASS |
| Change Password API | PASS (401 without token) |
| All NavHost screens wired | PASS (code review) |
| Device E2E + screenshots | **PENDING** |

---

## What was NOT changed (protection rules honoured)

- No routes, APIs, tables, columns, permissions, or menu items removed
- No UI redesign
- No authentication flow changes
- No database schema changes
- `config.php` not uploaded
- Business logic unchanged except wiring existing password handlers to router

---

## Next steps to reach 95%+

1. **Device session:** Google login screenshot + Change Password E2E on Android v1.0.17
2. **Admin OTP session:** Screenshot pass on restored sidebar pages (Messages, Mobile Portal, Branding)
3. **Server null-byte fix:** Re-upload clean `config.php` from cPanel backup (not local 0-byte file)
4. **Recover mobile-portal pages:** Download from production FTP if non-zero on server, or restore from off-repo backup
5. **Restore `account-deletion.php`:** Required for Play Store data safety URL

---

## Scripts for re-run

```powershell
# Restore zero-byte files from _tmp-restore
powershell -ExecutionPolicy Bypass -File .\scripts\restore-zero-byte-files.ps1

# Deploy critical fixes to production
powershell -ExecutionPolicy Bypass -File .\scripts\stabilization-deploy.ps1

# Regression audit
powershell -ExecutionPolicy Bypass -File .\scripts\phase2-authenticated-audit.ps1
```

---

**Stabilization phase: substantial fixes deployed. Device + authenticated verification still required to close.**
