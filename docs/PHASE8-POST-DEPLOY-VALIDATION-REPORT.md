# Phase 8 — Post-Deploy Device Validation & Production Stabilization

**Status:** Validation complete (automated production probes + code-path review)  
**No code changes · No deployment**

**Production target:** `https://register.olasentra.com`  
**Deployment validated:** Phase 7B (2026-06-21)

**Final verdict:** **STABLE WITH MINOR ISSUES**

---

## Executive summary

Live production passes all **authentication visibility** and **core asset** checks after Phase 7B. Google OAuth redirect, login markup (Google + OTP + Register, no PPS), design-system CSS, and offline page v3 are confirmed via HTTP.

**Physical device testing** (Android Chrome/PWA, iPhone Safari/PWA) could not be executed from this environment. Items marked **DEVICE REQUIRED** need sign-off on real hardware.

No **major** blockers were found (login unavailable, OAuth broken, OTP endpoints missing). Minor production-only inconsistencies remain: PWA manifest colours vs in-app orange branding, service worker not precaching v3 CSS, registration visual disconnect, and legacy guest messages shell.

---

## 1. Device test results

Legend: **PASS (HTTP)** = verified remotely · **DEVICE REQUIRED** = needs phone/tablet · **N/A** = not testable without credentials

### Authentication

| Test | Android Chrome | Android PWA | iPhone Safari | iPhone PWA | Evidence |
|------|----------------|-------------|---------------|------------|----------|
| Google Login | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | OAuth redirect to `accounts.google.com` with correct `client_id` + callback URL — **PASS (HTTP)** |
| Email OTP Send | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | UI + JS + CSRF present on guest page — **PASS (HTTP)**; POST flow needs device + inbox |
| Email OTP Verify | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | Verify button + API exists (405 on GET = expected) — **PASS (HTTP)** |
| Register Link | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | DEVICE REQUIRED | `Create Account / Register` → `index.php` — **PASS (HTTP)** |

**Guest login HTML (production `staff-app.php`):**

| Check | Result |
|-------|--------|
| Sign in with Google | PASS |
| Sign in with Email Code (OTP) | PASS |
| `#staff-portal-email-otp` + `data-csrf` | PASS |
| `staff-portal-email-otp.js` loaded | PASS |
| Create Account / Register | PASS |
| No PPS / no `staff_portal_verify` | PASS |
| `theme-color` meta `#F58220` | PASS |
| `staff-app-v3.css` / `staff-app-v3.js` | PASS |

### Staff operations (signed-in)

| Test | Status | Notes |
|------|--------|-------|
| Dashboard | DEVICE REQUIRED | Protected route; redirects when guest (302) — expected |
| Check In | DEVICE REQUIRED | `staff-checkin.php` → 302 when guest |
| Active Shift | DEVICE REQUIRED | Shift banner markup deployed (`es-v3__shift-banner`) |
| Notifications | DEVICE REQUIRED | `staff-notifications.php` → 302 when guest |
| Messages | DEVICE REQUIRED | v3 shell when portal session exists; legacy lookup UI when guest |
| Documents | DEVICE REQUIRED | Profile hub `#documents` section (Phase 7) |
| Profile | DEVICE REQUIRED | `staff-profile-hub.php` → 302 when guest |
| Settings | DEVICE REQUIRED | Settings rows in profile hub (Phase 7) |

### PWA

| Test | Status | Notes |
|------|--------|-------|
| Install Prompt | DEVICE REQUIRED | `#es-v3-pwa-banner` in HTML; JS shows on `beforeinstallprompt` (Android) or iOS fallback |
| Installed Mode | DEVICE REQUIRED | Install targets hidden via `es-v3__install-target` + standalone CSS |
| Standalone Mode | DEVICE REQUIRED | `@media (display-mode: standalone)` rules in deployed CSS — **PASS (HTTP)** |
| Offline Page | **PASS (HTTP)** | `offline.php` 200, uses `es-ds__empty`, no legacy `staff-app.css` |

### Visual review (automated partial)

| Area | HTTP / code review | Device |
|------|-------------------|--------|
| Button alignment (`es-ds__btn`) | CSS deployed — PASS | DEVICE REQUIRED |
| Font consistency (Inter) | Linked on v3 pages — PASS | DEVICE REQUIRED |
| Dark mode (`#0B1020`) | Tokens in production CSS — PASS | DEVICE REQUIRED |
| Orange branding (`#F58220`) | In-app CSS + meta — PASS | Manifest mismatch — see Issue #2 |
| Navigation (bottom nav FAB) | Unchanged logic — PASS | DEVICE REQUIRED |
| Empty states (`es-ds__empty`) | In deployed PHP/CSS — PASS | DEVICE REQUIRED |
| Error states | OTP error panel + alerts — PASS | DEVICE REQUIRED |

---

## 2. Production issues found

| ID | Issue | Severity | Category |
|----|-------|----------|----------|
| **P8-01** | Service worker `sw.js` precaches `staff-app.css` / v2, **not** `staff-app-v3.css` | **Medium** | PWA / Offline |
| **P8-02** | `manifest.php` `theme_color` = `#350f7b`, `background_color` = `#0f172a` — differs from in-app `#F58220` / `#0B1020` | **Low** | PWA chrome / splash |
| **P8-03** | Registration (`index.php`) uses light public shell; staff app is dark v3 — visual jump after Register | **Low** | Visual / UX |
| **P8-04** | Home “View Roster” links to `status.php` (personal status), not team roster | **Low** | UX copy |
| **P8-05** | Guest `staff-messages.php` (no session) uses legacy light `login-card` shell, not v3 | **Low** | Visual (edge case) |
| **P8-06** | Physical OTP send/verify, Google callback, check-in GPS — not validated in this pass | **Info** | Device gap |

No issues found affecting: auth logic, attendance, GPS validation, BIB, OAuth configuration, or OTP API availability.

---

## 3. Severity ranking

| Priority | ID | Impact |
|----------|-----|--------|
| **P2 — Medium** | P8-01 | Installed PWA offline or stale cache may load without v3 styles until network returns |
| **P3 — Low** | P8-02 | Status bar / splash purple (`#350f7b`) vs orange in-app chrome |
| **P3 — Low** | P8-03 | Onboarding feels like two different products |
| **P3 — Low** | P8-04 | Misleading “View Roster” label |
| **P3 — Low** | P8-05 | Rare path: messages without portal login |
| **Info** | P8-06 | Complete device matrix still required for sign-off |

---

## 4. Screenshots of issues

| Issue | Reference |
|-------|-----------|
| P8-03 Registration visual disconnect | [phase8-issue-registration-visual.png](phase8/phase8-issue-registration-visual.png) |
| P8-01 Offline / SW cache gap (conceptual) | [phase8-issue-offline-sw-cache.png](phase8/phase8-issue-offline-sw-cache.png) |

**P8-02 manifest colour mismatch (text evidence):**

- Page meta: `theme-color: #F58220` (staff-app.php)
- Manifest: `theme_color: #350f7b`, `background_color: #0f172a`

Capture on device: compare Safari/Chrome status bar colour in standalone vs in-browser.

---

## 5. Recommended fixes

*Recommendations only — **no implementation in Phase 8** per scope.*

| ID | Recommended fix | Scope | Risk if fixed |
|----|-----------------|-------|---------------|
| P8-01 | Add `staff-app-v3.css`, `staff-app-v3.js`, `notifications.css` to `sw.js` `CORE_ASSETS`; bump cache version | `sw.js` only | Low — test offline after |
| P8-02 | Align `manifest.php` / admin theme setting to `#F58220` and `#0B1020` | Settings / manifest (not auth) | Low — visual only |
| P8-03 | Phase 6C: registration header/footer dark band matching v3 | Registration CSS | Medium — separate phase |
| P8-04 | Rename to “Application status” or route to intended screen | Copy / link in `staff-app-v3-pages.php` | None |
| P8-05 | Redirect guest messages to `staff-app.php` login | `staff-messages.php` routing | Low |
| P8-06 | Owner completes device checklist below; rollback only if triggers fire | Manual QA | — |

---

## 6. Device owner checklist (complete for full STABLE sign-off)

Use a **private window** or hard refresh after Phase 7B deploy.

### Android Chrome

- [ ] Google login → lands on dashboard  
- [ ] OTP send → email received  
- [ ] OTP verify → dashboard  
- [ ] Register link opens registration  
- [ ] Dashboard, Check In, Notifications, Messages, Profile  
- [ ] Install prompt appears (if supported)  
- [ ] Add to home screen → standalone hides install UI  

### Android installed PWA

- [ ] Same auth flows in standalone  
- [ ] Offline page (`offline.php` or airplane mode navigation)  
- [ ] Active shift banner if on shift  

### iPhone Safari

- [ ] Google login (not in-app browser)  
- [ ] OTP send / verify  
- [ ] Share → Add to Home Screen instructions  
- [ ] Settings install row visible when not installed  

### iPhone installed PWA (if available)

- [ ] Standalone: no install banner  
- [ ] Orange/dark UI consistent on Profile + Notifications  

**Rollback triggers (from Phase 7B):** login down, Google fails, OTP send/verify fails, dashboard or check-in inaccessible → restore from `storage/backups/phase7b-pre-deploy-20260621-065446/`.

---

## 7. Production HTTP probe log

| URL | Status | Notes |
|-----|--------|-------|
| `/staff-app.php` | 200 | Login parity confirmed |
| `/offline.php` | 200 | v3 dark empty state |
| `/manifest.php` | 200 | Standalone; theme `#350f7b` |
| `/staff-google-signin.php?return=staff-app.php` | 302→Google | OAuth OK |
| `/assets/css/staff-app-v3.css` | 200 | 54,585 bytes; tokens present |
| `/assets/js/staff-app-v3.js` | 200 | 9,939 bytes |
| `/assets/js/staff-portal-email-otp.js` | 200 | 6,667 bytes |
| `/api/staff-portal-otp-send.php` | 405 GET | Expected |
| `/api/staff-portal-otp-verify.php` | 405 GET | Expected |
| `/staff-shifts.php` (guest) | 302 | Auth gate OK |
| `/staff-messages.php` (guest) | 200 | Legacy lookup shell |

---

## 8. Final verdict

# STABLE WITH MINOR ISSUES

Production is **operationally stable** for Phase 7B scope: login parity live, assets verified, OAuth path healthy, no critical regressions detected remotely.

**Minor issues** (P8-01–P8-05) are visual/PWA polish — not rollback triggers.

**Action required:** Complete **Section 6** device checklist. If all pass with no rollback triggers → upgrade verdict to **STABLE**.

---

**Related docs:**  
- [`PHASE7B-DEPLOYMENT-REPORT.md`](PHASE7B-DEPLOYMENT-REPORT.md)  
- [`PHASE7A-DEPLOYMENT-VALIDATION-REPORT.md`](PHASE7A-DEPLOYMENT-VALIDATION-REPORT.md)  
- Rollback backup: `storage/backups/phase7b-pre-deploy-20260621-065446/`
