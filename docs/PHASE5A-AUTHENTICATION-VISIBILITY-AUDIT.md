# Phase 5A — Authentication Visibility Audit

**Date:** 2026-06-21  
**Type:** Read-only audit — no code changes, no deployment  
**Primary surface:** Staff portal (`register.olasentra.com` / `staff-app.php`)  
**Also reviewed:** Android native app, Mobile API, registration sign-up, PWA install UX

---

## Executive summary

On the **staff PWA login screen** (`staff-app.php` guest view), only **Google Sign-In** and a **registration link** are visible. **Email OTP** and **email + PPS** login UIs are **not rendered** on that page in either production snapshot or current local code — though backend/API code for staff-portal email OTP **exists on production** (orphaned: no matching HTML). **Password reset** is not offered on staff PWA login (Android exposes change-password in profile only). **Phase 5 UI work did not modify authentication files.**

---

## AUTHENTICATION STATUS MATRIX

Legend: **Present** = user-visible when settings allow · **Hidden** = logic/API exists but UI not shown · **Disabled** = blocked by setting/config · **Removed** = no longer in codebase entry path

### Staff PWA (`staff-app.php` — production snapshot & local, identical guest UI)

| Method | Present | Hidden | Disabled | Removed | Feature flag | Settings |
|--------|---------|--------|----------|---------|--------------|----------|
| **Email Login** (email + PPS form) | — | **Yes** | When `staff_google_signin_required=1` | From main entry UI | — | `staff_google_signin_required`, `signin_require_pps_last4` |
| **OTP Login** (email code) | — | **Yes** | When `staff_portal_email_otp_enabled=0` | UI never wired | — | `staff_portal_email_otp_enabled` |
| **Google Login** | **Yes** | — | When not configured or `staff_google_signin_enabled=0` | — | — | `staff_google_signin_enabled`, `google_oauth_client_id`, secret |
| **Sign Up** | **Yes** (link) | — | — | — | — | Registration site URL |
| **Password Reset** | — | **Yes** (not on login) | — | Not implemented on PWA login | — | — |

### Android native app (`LoginScreen` / Mobile API)

| Method | Present | Hidden | Disabled | Removed | Feature flag | Settings |
|--------|---------|--------|----------|---------|--------------|----------|
| **Email Login** (OTP flow) | **Yes** | Hidden when `email_otp_enabled=false` | Maintenance / force-update | — | Mobile config | `mobile_email_otp_enabled` |
| **OTP Login** | **Yes** (same flow) | Same as above | Same | — | `email_otp_enabled` in `/api/mobile/v1/config` | `mobile_email_otp_enabled` |
| **Google Login** | **Yes** | — | When `google_signin_enabled=false` | — | Mobile config | `staff_google_signin_enabled` |
| **Sign Up** | **Yes** (“Apply to Join”) | — | — | — | — | `registration_site_url` |
| **Password Reset** | Profile only | Not on login | — | No “Forgot password” on login | — | OTP via profile API |

### Admin (`admin/login.php`) — reference only

Separate system: username/password + optional admin OTP (`admin_login_otp_enabled`). Out of staff PWA scope.

---

## UI VISIBILITY AUDIT

### Staff PWA login (`staff-app.php` guest)

| Context | Google | Email + PPS | Email OTP | Sign Up link | Password reset |
|---------|--------|-------------|-----------|--------------|----------------|
| Desktop browser | Visible if enabled | Not shown | Not shown | Visible | Not shown |
| Mobile browser | Same | Not shown | Not shown | Visible | Not shown |
| PWA / standalone (logged out) | Same | Not shown | Not shown | Visible | Not shown |
| After logout (`staff-signout.php` → `staff-app.php`) | Same guest screen | Not shown | Not shown | Visible | Not shown |

**Notes:**

- Guest page uses `renderStaffAppGuestEasyPage()` — Google button + “New? Register with Gmail”.
- `renderStaffProfileVerifyForm()` (email + PPS + optional Google) exists in `staff-profile-gate.php` but is **not called** from current `staff-app.php` (only referenced in archived `storage/reports/prod-staff-app.php`).
- Production ships `staff-portal-email-otp.js` + API endpoints, but **no** `#staff-portal-email-otp` markup in `staff-app-easy.php` — JS is inert.

### Android app

| Context | Google | Email OTP | Sign up |
|---------|--------|-----------|---------|
| Phone | Visible | Visible if `emailOtpEnabled` | “Apply to Join” |
| Tablet | Same responsive layout | Same | Same |

Controlled by `LoginScreen.kt` + `MobileConfigService.php` / portal branding config.

---

## SOURCE AUDIT

| Method | Visibility control | Logic control | Setting(s) |
|--------|-------------------|---------------|------------|
| **Google Login (PWA)** | `includes/staff-app-easy.php` (`$googleReady`) | `staff-google-signin.php`, `staff-google-oauth-callback.php`, `includes/staff-google-oauth.php` | `staff_google_signin_enabled`, OAuth client id/secret |
| **Email + PPS (PWA)** | `includes/staff-profile-gate.php` → `renderStaffProfileVerifyForm()` (**not used on staff-app.php**) | `handleStaffPortalVerifyPost()`, `authenticateStaffPortal()` in `staff-portal-session.php` | `staff_google_signin_required`, `signin_require_pps_last4` |
| **Email OTP (PWA)** | **No PHP template** (production JS expects `#staff-portal-email-otp`) | Production: `includes/staff-portal-email-otp.php`, `api/staff-portal-otp-send.php`, `api/staff-portal-otp-verify.php` | `staff_portal_email_otp_enabled` |
| **Google Login (Android)** | `LoginScreen.kt` | `MobileGoogleAuthService.php`, `AuthRepositoryImpl` | `staff_google_signin_enabled` via mobile config |
| **Email OTP (Android)** | `LoginScreen.kt` (`emailOtpEnabled`), `EmailSignInScreen.kt`, `OtpVerificationScreen.kt` | `MobileEmailOtpAuthService.php`, `MobileOtpService.php`, `auth/otp/send`, `auth/otp/verify` | `mobile_email_otp_enabled` |
| **Sign Up (PWA)** | Link in `staff-app-easy.php` | `index.php` registration | Registration site settings |
| **Sign Up (Android)** | `LoginScreen.kt` → `ApplyRegistrationScreen` | Mobile registration routes | `registration_site_url` |
| **Password change (Android)** | Profile → `ChangePasswordScreen.kt` | `MobileEmailOtpAuthService.php` (purpose `password`) | Authenticated only |
| **Session / logout** | — | `staff-portal-session.php`, `staff-signout.php` | Idle TTL via `APP_SESSION_IDLE_TTL` |

---

## HISTORICAL COMPARISON (production snapshot vs local)

**Compared:** `_recovery-staging/production-snapshot-20260621-055543/` vs workspace root (2026-06-21)

| Item | Production snapshot | Local | Verdict |
|------|---------------------|-------|---------|
| `staff-app.php` guest renderer | `renderStaffAppGuestEasyPage` | Same | **No change** |
| `staff-app-easy.php` | Google-only UI | Identical | **No change** |
| `staff-profile-gate.php` email+PPS form | Present in file, unused on staff-app | Same | **Still orphaned from main login** |
| `staff-portal-email-otp.php` | **Present** | **Missing** | Local parity gap (backend only) |
| `api/staff-portal-otp-*.php` | **Present** | **Missing** | Local parity gap |
| `assets/js/staff-portal-email-otp.js` | **Present** | **Missing** | Orphaned on prod too (no HTML) |
| `settings-repository.php` defaults | Includes `staff_portal_email_otp_enabled` | Missing that default key | Local defaults slimmer |
| Phase 5 files (`staff-app-v3-*`, CSS) | N/A pre-Phase-5 deploy | UI only | **Did not touch auth** |

### Removed / hidden / renamed controls (historical)

| Control | Was | Now (staff-app entry) |
|---------|-----|------------------------|
| Email + PPS sign-in form | Used in older `prod-staff-app.php` / `renderStaffProfileVerifyForm` | **Hidden** — replaced by v3 Google-only guest page |
| Email OTP toggle UI | JS + API added on production; markup never added to `staff-app-easy.php` | **Hidden** (never visible on main login) |
| “Check In” quick action label | Unchanged on login | N/A for auth |

**No authentication methods were removed from backend/Mobile API in this comparison.** Visibility reduction is on **staff PWA login markup** only.

---

## PWA INSTALL STATUS

### Does “Install Olasentra” appear when not installed?

| UI element | When shown | When hidden |
|------------|------------|-------------|
| **`#es-v3-pwa-banner`** (shell) | `beforeinstallprompt` fires; not dismissed (`es_v3_pwa_dismiss`); not standalone | Dismissed; standalone; no prompt |
| **`#pwa-install-banner`** (global) | Mobile UA or deferred prompt; not dismissed (`pwa_install_dismissed`); not standalone | `pwa-install.js` exits early in standalone; dismissed |
| **Home `#staff-app-install-btn`** | Signed-in home + profile hub | Always in DOM on signed-in pages (**no v3 standalone CSS hide**) |
| **Guest login page** | No install row; banner may still appear via `pwa-install.js` / `beforeinstallprompt` | Standalone mode |

### After installation / standalone mode

| Behavior | Result |
|----------|--------|
| `pwa-install.js` line 4–6 | **Exits entirely** in standalone — no global banner |
| `staff-app-v3.js` `showPwaBanner()` | Checks `display-mode: standalone` — **does not show** v3 banner |
| **`es-v3__install-row`** | **Still visible** in v3 CSS (no `@media (display-mode: standalone)` rule unlike v1/v2) |

### Files controlling PWA install

| File | Role |
|------|------|
| `includes/staff-app-v3-shell.php` | `data-pwa-install="1"`, `#es-v3-pwa-banner`, loads `pwa-scripts.php` |
| `includes/staff-app-v3-pages.php` | `#staff-app-install-btn` on home + profile |
| `assets/js/staff-app-v3.js` | v3 banner + `beforeinstallprompt` |
| `assets/js/pwa-install.js` | Global banner, modal, button bind, standalone guard |
| `assets/js/pwa-install-analytics.js` | Standalone / install tracking |
| `includes/pwa-scripts.php` | Conditional script load |
| `manifest.php` | PWA manifest (`display: standalone`) |

---

## FILES INVOLVED

### Staff PWA authentication (visibility)

- `staff-app.php`
- `includes/staff-app-easy.php`
- `includes/staff-profile-gate.php`
- `includes/staff-google-oauth.php`
- `staff-google-signin.php`
- `staff-google-oauth-callback.php`
- `includes/staff-portal-session.php`
- `staff-signout.php`

### Staff PWA email OTP (production backend; UI missing)

- `includes/staff-portal-email-otp.php` (production only)
- `api/staff-portal-otp-send.php` (production only)
- `api/staff-portal-otp-verify.php` (production only)
- `assets/js/staff-portal-email-otp.js` (production only)

### Mobile / Android authentication

- `includes/mobile/services/MobileAuthService.php`
- `includes/mobile/services/MobileEmailOtpAuthService.php`
- `includes/mobile/services/MobileGoogleAuthService.php`
- `includes/mobile/services/MobileOtpService.php`
- `includes/mobile/services/MobileConfigService.php`
- `includes/mobile/controllers/AuthController.php`
- `android/olasentra-staff/feature/auth/ui/LoginScreen.kt`
- `android/olasentra-staff/feature/auth/ui/EmailSignInScreen.kt`
- `android/olasentra-staff/feature/auth/ui/OtpVerificationScreen.kt`

### Settings

- `includes/settings-repository.php`
- Admin: Settings → Security / Google / Mobile Portal (production DB values override defaults)

### PWA install (not auth; audited per scope)

- `assets/js/pwa-install.js`
- `assets/js/staff-app-v3.js`
- `includes/staff-app-v3-shell.php`
- `includes/staff-app-v3-pages.php`

---

## RISK ASSESSMENT

| Risk | Level | Detail |
|------|-------|--------|
| Non-Gmail staff blocked on PWA | **Medium** | Email OTP UI not on `staff-app.php`; must use Android app or venue flows |
| Orphaned OTP API on production | **Low** | Endpoints exist but no public UI; still callable if discovered |
| Local missing OTP portal files | **Medium** | Deploying local tree without restore would drop API (not done in this audit) |
| Google-only perception | **Medium** | Copy says “same Gmail” — accurate for PWA, not for all staff |
| PWA install row in standalone | **Low** | v3 home may still show “Add to home screen” when already installed |
| Phase 5 UI mistaken for auth change | **None** | Phase 5 touched CSS/pages only; auth files unchanged |

---

## RECOMMENDATIONS

1. **Do not deploy auth changes without explicit approval** — this audit is informational only.
2. **If email OTP should appear on staff PWA:** add `#staff-portal-email-otp` markup to guest login (wire existing production API) and restore local parity files before deploy.
3. **If email + PPS fallback is required on PWA:** either call `renderStaffProfileVerifyForm()` when `!isStaffGoogleSigninRequired()` or add equivalent controls to `staff-app-easy.php`.
4. **Document staff-facing guidance:** non-Gmail staff → Android “Sign in with Email” or contact ops; PWA → Google with registered Gmail.
5. **PWA polish (separate from auth):** add `@media (display-mode: standalone) { .es-v3__install-row { display: none; } }` to match v1/v2 behavior.
6. **Parity:** restore `staff-portal-email-otp.php` + API files to local repo from production snapshot if future deploys need them (Phase 1 parity gap).

---

## Audit constraints met

- No code changes
- No deployment
- No authentication architecture modifications
