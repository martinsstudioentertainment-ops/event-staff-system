# Phase 6 — Complete Staff App UI/UX Modernization Audit

**Status:** Audit complete — **no code changes, no deployment**

**Date:** 2026-06-21

**Scope:** Staff PWA (`staff-app.php` ecosystem) + registration site auth/onboarding + PWA shell

**Method:** Read-only codebase review of routes, PHP renderers, CSS/JS, manifest, and service worker. Screenshots are **audit mockups** derived from implemented markup and styles (not live production captures).

---

## Executive summary

The Staff PWA v3 design system (`staff-app-v3.css`) **already defines the approved GTBank palette** and applies it well on login, home, shifts, check-in, profile hub, and bottom navigation. Modernization debt is concentrated in **legacy bleed-through** (registration public shell, offline page, WhatsApp card, shift banner, messages markup), **inconsistent button/form primitives**, **dual PWA install UX**, **missing dedicated screens** (team roster, settings, password change), and **thin accessibility** (focus rings, tab semantics).

**Recommended approach:** Phase implementation in layers — design tokens cleanup → shared components → screen-by-screen polish — without touching attendance, GPS, BIB, auth, or API layers.

---

## Screen inventory & screenshots

### Authentication

| Screen | Route / file | Screenshot | Audit status |
|--------|--------------|------------|--------------|
| Staff login / landing | `staff-app.php` (guest) → `staff-app-easy.php` | [phase6-staff-login-approved.png](phase6/phase6-staff-login-approved.png) | **Modern** — Phase 5C approved (Google + OTP, no PPS) |
| Login (before Phase 5C) | Same | [phase6-login-before.png](phase6/phase6-login-before.png) | Legacy Google-only |
| Email OTP request | Inline on login; `POST api/staff-portal-otp-send.php` | [phase6-otp-request.png](phase6/phase6-otp-request.png) | **Modern** — v3 OTP panel |
| OTP verification | Inline step 2; `POST api/staff-portal-otp-verify.php` | [phase6-otp-verify.png](phase6/phase6-otp-verify.png) | **Modern** |
| Registration | `index.php` + wizard shell | [phase6-registration.png](phase6/phase6-registration.png) | **Legacy** — public/light shell, not v3 dark |
| Account recovery | No dedicated PWA route | [phase6-account-recovery.png](phase6/phase6-account-recovery.png) | **N/A screen** — re-auth via Google/OTP only |
| Google OAuth | `staff-google-signin.php` → Google (external) | — | External; returns to app |

**Password reset capability:** Preserved on **Android** (post-auth OTP change-password). **No forgot-password route** on Staff PWA. Recovery = sign in again (documented, not a gap in capability per product decision).

### Staff app (signed-in)

| Screen | Route / file | Screenshot | Audit status |
|--------|--------------|------------|--------------|
| Home dashboard | `staff-app.php` | [phase6-home-dashboard.png](phase6/phase6-home-dashboard.png) | **Mostly modern** — hero, stats, today card |
| Clock In | `staff-checkin.php` (pre-check-in) | [phase6-clock-in.png](phase6/phase6-clock-in.png) | **Modern** |
| Clock Out / shift active | GPS auto sign-out; checked-in success state | [phase6-clock-out.png](phase6/phase6-clock-out.png) | **No manual Clock Out button** — UX relies on GPS + banner |
| Shifts | `staff-shifts.php` | [phase6-shifts.png](phase6/phase6-shifts.png) | **Modern** |
| Roster | Home links to `status.php?token=…` | [phase6-roster-status.png](phase6/phase6-roster-status.png) | **UX gap** — not a team roster; light legacy page |
| Messages | `staff-messages.php` | [phase6-messages.png](phase6/phase6-messages.png) | **Hybrid** — legacy bubble markup, v3 overrides |
| Notifications | `staff-notifications.php` | [phase6-notifications.png](phase6/phase6-notifications.png) | **Hybrid** — v3 cards + green WhatsApp block |
| Documents | `staff-profile-hub.php#documents` | [phase6-profile-settings.png](phase6/phase6-profile-settings.png) | **Partial** — list only, edit via profile form |
| Profile | `staff-profile-hub.php` | Same screenshot | **Modern** menu-card pattern |
| Settings | Section in profile hub | Same screenshot | **Partial** — no dedicated settings page |
| Profile edit | `staff-profile.php?edit=1` | — | **Legacy form** — not fully v3 styled (review in Phase 6B) |

### PWA

| Screen | File / trigger | Screenshot | Audit status |
|--------|----------------|------------|--------------|
| Install prompt | `#es-v3-pwa-banner`, `#staff-app-install-btn`, `pwa-install.js` | [phase6-pwa-install.png](phase6/phase6-pwa-install.png) | **Dual systems** — v3 orange + legacy blue banner |
| Installed / standalone | `display: standalone` | [phase6-pwa-standalone.png](phase6/phase6-pwa-standalone.png) | **Rule met** in v3 CSS/JS |
| Offline (full page) | `offline.php` via `sw.js` | [phase6-pwa-offline.png](phase6/phase6-pwa-offline.png) | **Legacy** — light `staff-app.css`, not v3 |
| Offline (in-app bar) | `#es-v3-offline` in `staff-app-v3.js` | — | **Modern** red bar on v3 pages |
| Splash / launch | `manifest.php` `background_color` + icon | [phase6-pwa-splash.png](phase6/phase6-pwa-splash.png) | **No dedicated splash asset** — OS/manifest only |

---

## 1. Complete UI audit

### Branding consistency

| Area | Target palette | Current | Grade |
|------|----------------|---------|-------|
| v3 CSS tokens | `#F58220`, `#FFA64D`, `#0B1020`, `#162238` | Defined in `:root` — match | **A** |
| `theme-color` meta | `#F58220` | v3 shell uses `#F58220`; manifest uses `getThemeColor()`; public errors use `#F48221` | **B** |
| Orange RGB drift | Consistent | Many `rgba(244,130,33)` vs `#F58220` (245,130,32) | **C** |
| Registration site | Should feel related | Light public shell, separate from v3 | **D** |
| Offline page | v3 dark | Poppins pastel `staff-app.css` | **F** |
| WhatsApp card | v3 dark | Light green `notifications.css` | **F** |
| PWA legacy banner | v3 orange | Blue `#2563eb` in `pwa-install.css` | **D** |
| Shift monitoring banner | v3 styled | `staff-v2__alert` — **CSS not loaded on v3** | **F** |

### Typography

- **Font:** Inter 400–800 via Google Fonts on v3 — good.
- **Scale:** Clear hierarchy (page title 1.5rem/800, section labels uppercase 0.8125rem).
- **Issues:** Nav labels 10px / FAB label 9px — readable targets but poor label legibility; registration uses different stack via public CSS.

### Buttons

- **Strengths:** Distinct primary gradients on clock-in hero and check-in CTA.
- **Issues:** No shared `.es-v3__btn` primitive; heights vary (40–72px); OTP primary uses softer gradient; three coexisting install triggers.

### Cards

- **Modern:** `.es-v3__stat-card`, `.es-v3__today-card`, `.es-v3__shift-card`, `.es-v3__menu-card`, `.es-v3__notif-card`.
- **Issues:** `.es-v3__shift-card--compact` in PHP with no CSS; empty states consistent; shift banner unstyled.

### Forms

- **Modern:** OTP inputs, search bar, BIB field, compose textarea.
- **Issues:** No shared input component; profile edit uses legacy `.form-input`; registration wizard separate system.

### Navigation

- **Bottom nav:** 5-tab with center FAB — strong mobile pattern; active state clear.
- **Gap:** Notifications only in top bar (discoverability OK but not equal weight).
- **Gap:** “View Roster” mislabels `status.php` destination.

---

## 2. Complete UX audit

### Mobile UX

| Flow | Rating | Notes |
|------|--------|-------|
| Login (Google + OTP) | **Good** | Approved dual path; clear copy |
| Register → sign in | **Fair** | Visual jump from light wizard to dark app |
| Home → Clock In | **Good** | Prominent hero + FAB |
| GPS check-in | **Good** | Progressive enablement, clear hints |
| Shift monitoring | **Fair** | Banner may render unstyled; auto sign-out not explained on check-in success |
| Shifts browse/filter | **Good** | Tabs, search, chips, calendar strip |
| Messages | **Fair** | Functional; bubble layout feels dated |
| Notifications | **Good** | Mark all read; unread styling |
| Profile / documents | **Fair** | Documents read-only in hub; edit redirects to long form |
| Sign out | **Good** | Clear logout button |

### Tablet UX

- v3 uses single-column mobile-first layout (`max-width` constraints in CSS).
- No tablet-specific breakpoints or two-column layouts — acceptable but not optimized for iPad landscape.

### Empty states

- **Strong:** `.es-v3__empty-card` on shifts, check-in, notifications.
- **Weak:** Documents “No documents on file yet” is plain text in menu card.

### Loading states

- GPS checking pulse — good.
- **Gap:** `.es-v3__skeleton` defined in CSS but **never used** in PHP/JS.
- Check-in submit uses disabled + wait cursor only.

### Error states

- v3 alerts on check-in and login — good.
- **Gap:** `staff-app.php` fatal error renders unstyled inline HTML.
- OTP errors via JS — good.

### Staff onboarding flow

```
Register (index.php, 8 steps)
    → status.php (optional tracking)
    → staff-app.php login (Google or OTP)
    → staff-profile.php (if profile incomplete banner)
    → Home / shifts / check-in
```

**Friction points:**

1. Registration visual language ≠ staff app.
2. “Payroll” step label in wizard conflicts with platform positioning (staff operations, not payroll platform).
3. Profile completion banner uses `staff-easy__banner` — styled but mentions “Payroll profile”.
4. No guided first-run tour after first login.

---

## 3. Authentication audit

| Requirement | Status | Evidence |
|-------------|--------|----------|
| Google Sign In visible | ✅ | `staff-app-easy.php` |
| Email OTP visible | ✅ | `#staff-portal-email-otp` + JS |
| Sign Up visible | ✅ | “Create Account / Register” link |
| Email + PPS not on PWA | ✅ | Removed in Phase 5C revised |
| Password reset capability preserved | ✅ (Android) | No PWA forgot-password by design |
| Same staff profile | ✅ | `establishStaffPortalSessionWithRemember()` |

**Auth UX gaps (UI only, no logic changes needed):**

- Registration site should visually bridge to v3 login.
- Account recovery messaging could be explicit on login footer (“Forgot access? Use Google or request a new code”).
- No loading state on OTP send/verify buttons beyond `aria-busy`.

---

## 4. PWA audit

| Rule | Implementation | Status |
|------|----------------|--------|
| Show install when not installed | `beforeinstallprompt` → v3 banner + home row + profile button | ✅ (redundant) |
| Hide when installed | Prompt dismissed / after install | ✅ |
| Hide in standalone | CSS `@media (display-mode: standalone)` + JS | ✅ |

**PWA technical gaps:**

| Issue | File | Impact |
|-------|------|--------|
| `sw.js` precaches v1/v2 CSS, not v3 | `sw.js` | Offline v3 pages may lack styles |
| Dual install UX (orange + blue) | `pwa-install.js`, `staff-app-v3.js` | Confusing brand |
| Offline page legacy styling | `offline.php` | Broken experience when offline |
| Manifest `background_color` `#0f172a` vs token `#0B1020` | `manifest.php` | Minor splash mismatch |
| No splash image | — | OS default only |

---

## 5. Design consistency report

### Token alignment scorecard

| Token | CSS variable | Usage coverage |
|-------|--------------|----------------|
| `#0B1020` | `--es-primary` | ~85% of v3 screens |
| `#162238` | `--es-secondary`, `--es-card` | ~80% |
| `#F58220` | `--es-accent` | ~75% (drift in rgba literals) |
| `#FFA64D` | `--es-accent-secondary` | ~60% |

### Cross-stylesheet conflicts

1. `notifications.css` overrides `.es-v3__notif-badge` to red (loaded after v3).
2. `pwa-install.css` blue primary conflicts with orange v3 banner.
3. `staff-v2__alert` used but v2 CSS not enqueued on v3 routes.

### Accessibility summary

| Check | Status |
|-------|--------|
| `lang`, viewport, safe-area | ✅ |
| Decorative SVG `aria-hidden` | ✅ |
| Alerts `role="alert"` | ✅ |
| OTP labels / autocomplete | ✅ |
| Nav `aria-current` | ✅ |
| Focus visible rings | ❌ (mostly missing) |
| Tab semantics | ⚠️ (tablist without tabpanels) |
| Messages nav unread in aria-label | ⚠️ (unlike top-bar bell) |
| Small nav text | ⚠️ |
| Emoji empty states | ⚠️ |

---

## 6. Screens requiring redesign

| Priority | Screen | Why |
|----------|--------|-----|
| P0 | `offline.php` | Completely off-brand; first impression when network fails |
| P0 | Shift monitoring banner | Unstyled `staff-v2__alert` on every signed-in page with active shift |
| P1 | Registration (`index.php`) | Visual disconnect; “Payroll” step naming |
| P1 | `staff-profile.php` edit form | Long legacy form outside v3 system |
| P1 | Notifications WhatsApp card | Light green on dark app |
| P1 | Messages thread | Legacy bubble structure |
| P2 | `status.php` (roster link) | Mislabeled; light theme |
| P2 | PWA install consolidation | Single orange install pattern |
| P2 | Fatal error page (`staff-app.php`) | Unstyled fallback |
| P3 | Dedicated settings page | Currently nested in profile hub |
| P3 | Team roster (if product wants it) | **Missing** — home CTA misleading |

---

## 7. Screens already modern

| Screen | Confidence |
|--------|------------|
| Staff login (Phase 5C) | High |
| Home dashboard + clock-in hero | High |
| Shifts list (filters, cards, calendar) | High |
| Check-in page (GPS, scanner CTA, success) | High |
| Profile hub (menu cards, logout) | High |
| Bottom navigation + FAB | High |
| BIB banner/chip components | High |
| Public error screen (`staff-app-v3-public.php`) | High |
| In-app offline bar | Medium |

---

## 8. Priority ranking

| Rank | Initiative | Effort | Impact | Risk |
|------|------------|--------|--------|------|
| **1** | Fix unstyled shift banner (CSS-only) | S | High | None |
| **2** | Rebrand `offline.php` to v3 tokens | S | High | None |
| **3** | Consolidate PWA install to single v3 pattern | M | Medium | Low |
| **4** | Design tokens cleanup (rgba drift, badge conflict) | M | Medium | Low |
| **5** | Shared button/input primitives (CSS) | M | High | Low |
| **6** | Notifications page — WhatsApp card + badge fix | S | Medium | None |
| **7** | Messages UI refresh (keep markup/API) | L | Medium | Low |
| **8** | Registration visual bridge (CSS/shell only) | L | High | Medium |
| **9** | Profile edit form v3 styling | L | High | Medium |
| **10** | Accessibility pass (focus, tabs, aria) | M | Medium | None |
| **11** | Skeleton loaders for shifts/home | S | Low | None |
| **12** | Roster/status link UX decision + redesign | M | Medium | Product |
| **13** | `sw.js` v3 asset precache | S | Medium | Low |
| **14** | Tablet layout polish | M | Low | None |

**Explicitly out of scope (per protection rules):** attendance, GPS, BIB validation, clock-in/out logic, auth logic, OAuth, OTP verify, schema, APIs.

---

## 9. Full modernization roadmap

### Phase 6A — Quick wins (CSS/shell only, ~1 week)

- Style `.staff-v2__alert` in v3 CSS (or rename to `es-v3__shift-banner`).
- Rebuild `offline.php` with v3 shell partial + tokens.
- Resolve `.es-v3__notif-badge` conflict (single source in v3).
- Hide duplicate install paths; align `pwa-install.css` primary to orange or disable legacy banner on v3.
- Add `:focus-visible` rings to nav, buttons, inputs.
- Wire `.es-v3__skeleton` on home stats / shifts load (display-only shimmer).

### Phase 6B — Component system (~2 weeks)

- Introduce `.es-v3__btn`, `.es-v3__btn--primary`, `.es-v3__btn--ghost`, `.es-v3__input`, `.es-v3__label`.
- Refactor OTP, compose, search, BIB to use primitives (CSS-only refactor).
- Standardize primary gradient (one recipe).
- Document tokens in CSS header comment block.

### Phase 6C — Screen polish (~3 weeks)

- Notifications: restyle WhatsApp block to dark glass card.
- Messages: v3 bubble layout (CSS grid/flex, same PHP components).
- Profile edit: wrap `staff-profile.php` in v3 page shell + form classes.
- Registration: dark-header bridge + rename “Payroll” step to “Profile details” (copy only).
- Home “View Roster” → rename or route fix (product decision).

### Phase 6D — PWA & edge cases (~1 week)

- Update `sw.js` CORE_ASSETS for v3 CSS/JS.
- Align manifest colors to `#0B1020` / `#F58220`.
- Style fatal error fallback via `renderStaffV3ErrorScreen()`.
- Optional: splash screen via manifest `screenshots` / iOS startup images.

### Phase 6E — Validation (~1 week)

- Device matrix: iPhone Safari, Android Chrome, installed standalone.
- Accessibility spot-check (VoiceOver/TalkBack).
- Regression: auth, check-in, messages, notifications unchanged functionally.

---

## 10. Before/after mockup plan

| # | Screen | Before reference | After target (Phase 6) |
|---|--------|------------------|------------------------|
| 1 | Login | [phase6-login-before.png](phase6/phase6-login-before.png) | ✅ Done — [phase6-staff-login-approved.png](phase6/phase6-staff-login-approved.png) |
| 2 | Offline | [phase6-pwa-offline.png](phase6/phase6-pwa-offline.png) | v3 dark card, orange CTA, match login |
| 3 | Shift banner | Unstyled text block | Orange/green glass banner with icon |
| 4 | Notifications | [phase6-notifications.png](phase6/phase6-notifications.png) | Dark WhatsApp card; orange unread badge |
| 5 | Messages | [phase6-messages.png](phase6/phase6-messages.png) | Full-width v3 bubbles, sticky compose |
| 6 | Registration | [phase6-registration.png](phase6/phase6-registration.png) | Dark header/footer band matching v3 |
| 7 | Profile edit | Legacy form (capture in 6B) | v3 menu sections + stepped form |
| 8 | Install UX | [phase6-pwa-install.png](phase6/phase6-pwa-install.png) | Single orange banner; no blue duplicate |
| 9 | Standalone | [phase6-pwa-standalone.png](phase6/phase6-pwa-standalone.png) | No change needed (already correct) |
| 10 | Home | [phase6-home-dashboard.png](phase6/phase6-home-dashboard.png) | Skeleton load + styled shift banner |

**Mockup production process (when implementation approved):**

1. Capture production before screenshots per screen.
2. Apply Phase 6A–D changes locally.
3. Capture after on same devices (iPhone 14 Safari, Pixel Chrome).
4. Store in `docs/phase6/mockups/` with paired filenames.

---

## Risk assessment (audit-only phase)

| Risk | Level | Notes |
|------|-------|-------|
| Code changes during audit | **None** | This phase made no code changes |
| Roadmap scope creep | Medium | Strict phase gates; no attendance/auth touches |
| Registration redesign breaking wizard | Medium | CSS/copy only in 6C |
| Roster naming confusion | Low | Product decision needed before UI work |

---

## Regression testing (audit phase)

| Test | Result |
|------|--------|
| Code modifications | **None** |
| Deployments | **None** |
| Static route inventory | **Complete** (see screen table) |
| Design token verification | **Complete** (v3 `:root` matches target) |
| Approved auth decision compliance | **Verified** in `staff-app-easy.php` |

---

## Appendix — Key file reference

| Concern | Path |
|---------|------|
| Staff entry | `staff-app.php` |
| Guest login UI | `includes/staff-app-easy.php` |
| v3 shell / nav | `includes/staff-app-v3-shell.php` |
| v3 pages | `includes/staff-app-v3-pages.php` |
| v3 design system | `assets/css/staff-app-v3.css` |
| OTP client | `assets/js/staff-portal-email-otp.js` |
| Registration | `index.php`, `includes/public/registration-wizard-shell.php` |
| Manifest | `manifest.php` |
| Service worker | `sw.js` |
| Offline | `offline.php` |
| Shift banner | `includes/staff-portal-shift.php` |

---

**Next step:** Review and approve roadmap priority order before any Phase 6A implementation.
