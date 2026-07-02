# Phase 12C — Full V3 UI Rendering Audit Report

**Date:** 2026-06-21  
**Environment:** Production (`https://register.olasentra.com`) + local codebase  
**Scope:** All Staff PWA pages (Login through Installed PWA mode)  
**Deploy status:** **No fixes deployed** (identification and documentation only, per Phase 12C instructions)

---

## Final Verdict

### **ISSUES FOUND**

Production HTTP probes pass (31/31 routes, 0 broken assets). The reported **black placeholder blocks** are consistent with a **confirmed rendering defect class**: inline Feather-style SVGs without `fill="none"` that default to **solid black** when scoped CSS is missing, stale, or fails to apply — amplified on **installed PWA** clients by **service worker cache-first** delivery of `staff-app-v3.css`.

---

## Executive Summary

| Area | Result |
|------|--------|
| Route / HTTP availability | PASS (31/31) |
| Referenced static assets (CSS/JS/images) | PASS (0 × 404 on probe) |
| Production `staff-app-v3.css` | 67,132 bytes, Phase 12A present, 18 `fill:none` rules |
| PWA manifest / icons | PASS |
| Google Fonts (Inter) | PASS (CDN) |
| **Rendering defect class (SVG + SW cache)** | **FAIL — root cause identified** |
| Device screenshots | Not captured in this automated audit |

---

## 1. Full Page Audit

Automated production probe: `scripts/phase12-full-audit-probe.ps1` → `docs/phase12-audit-probe-20260621-221212.json` (31 routes, 0 broken).

| Page | Route | HTTP | v3 CSS | Black-box / rendering risk | Notes |
|------|-------|------|--------|---------------------------|-------|
| **Login** | `staff-app.php` (guest) | 200 | Yes (`?v=filemtime`) | **MEDIUM** | Feature-row + secure shield SVGs; logo wrap has dark `#162238` background |
| **OTP** | Same shell, OTP panel | 200 | Yes | **LOW** | No extra SVGs in OTP steps; inherits login icon risks |
| **Dashboard** | `staff-app.php` (signed-in) | 200 | Yes | **HIGH** | 4× action-card icons, clock-in hero, bottom nav (5 icons) |
| **Clock In** | `staff-checkin.php` | 200 | Yes | **HIGH** | 64×64 scanner shield — largest visible black-block candidate |
| **Active Shift** | All signed-in pages (banner) | 200 | Yes | **HIGH** | Shift banner shield SVG on every page when on shift |
| **Shifts** | `staff-shifts.php` | 200 | Yes | **MEDIUM** | Date/time icons in shift cards; empty-state card |
| **Application Status** | `status.php` | 200 | Yes | **LOW** | Phase 12A grid/badges; no large inline SVGs in status dash |
| **Messages** | `staff-messages.php` | 200 | Yes | **MEDIUM** | Search bar SVG; empty states |
| **Notifications** | `staff-notifications.php` | 200 | Yes | **MEDIUM** | Empty-state bell SVG (`.es-ds__empty-icon`) |
| **Profile Hub** | `staff-profile-hub.php` | 200 | Yes | **MEDIUM** | Doc/menu/settings icons (6+ SVGs) |
| **Profile Edit** | `staff-profile.php?edit=1` | 200 | Yes | **LOW** | Mostly form fields; no large icon blocks |
| **Documents** | Profile hub `#documents` | 200 | Yes | **MEDIUM** | Document row icons + empty state |
| **Settings** | Profile hub settings section | 200 | Yes | **MEDIUM** | Bell + calendar settings icons |
| **Offline Page** | `offline.php` | 200 | **No `?v=` bust** | **MEDIUM–HIGH** | WiFi empty-state SVG; stale CSS via SW precache |
| **Installed PWA** | `display-mode: standalone` | — | SW-controlled | **HIGH** | Cache-first CSS amplifies all SVG/SW issues |

### Theme / layout (non-defect)

Dark navy cards (`--es-card: #162238`, `--es-primary: #0B1020`) and dashed empty-state panels may be **reported as “black boxes”** on bright outdoor screens. These are intentional v3 tokens, not missing assets.

---

## 2. Asset Audit

Production verification (2026-06-21):

| Asset | Status | Size |
|-------|--------|------|
| `assets/css/staff-app-v3.css` | 200 | 67,132 B |
| `assets/css/staff-app-v3.js` | 200 | OK |
| `assets/css/notifications.css` | 200 | 8,882 B |
| `assets/css/pwa-install.css` | 200 | 5,600 B |
| `assets/css/registration-v3.css` | 200 | 7,733 B |
| `assets/icons/pwa/icon-192.png` | 200 | 29,908 B |
| `assets/icons/pwa/icon-512.png` | 200 | 142,331 B |
| `assets/icons/pwa/icon-maskable-512.png` | 200 | 131,570 B |
| `assets/icons/icon.svg` | 200 | 300 B |
| `https://olasentra.com/new-logo.png` (login logo) | 200 | 187,170 B |
| `manifest.php` | 200 | theme `#F58220`, bg `#0B1020` |
| `sw.js` | 200 | `event-staff-v10-v3-staff-pwa` |

**404 assets:** None detected on probed routes.  
**Missing SVG files:** None — icons are inline in PHP templates, not external files.  
**Broken images:** Logo URL resolves; cross-origin (`olasentra.com`) — fails only when offline (expected).

---

## 3. CSS Audit

**Primary stylesheet:** `assets/css/staff-app-v3.css` (67,132 bytes)

| Check | Result |
|-------|--------|
| Phase 12A status block present | Yes |
| Design tokens (`--es-primary`, `--es-accent`) | Correct Olasentra v3 |
| Scoped SVG `fill:none` rules | 18 selectors |
| Global `.es-v3 svg { fill: none; }` | **Missing** |
| `-webkit-mask-image` on `.es-v3__empty-card::before` | **Missing** (has `mask-image` only) |
| `registration-v3.css` in PWA shell | Not loaded on main staff pages (only `index.php`, token messages view) |

**Cache busting:**

| Entry point | CSS URL pattern |
|-------------|-----------------|
| `staff-app-v3-shell.php` | `staff-app-v3.css?v={filemtime}` ✓ |
| `staff-app-v3-public.php` | `staff-app-v3.css?v={filemtime}` ✓ |
| `offline.php` | `staff-app-v3.css` **no version** ✗ |

**CSS parsing:** No syntax errors detected; production file serves complete content.

---

## 4. SVG / Icon Audit

Static scan (`scripts/phase12c-ui-render-audit.php`):

| Metric | Value |
|--------|-------|
| Inline SVGs in v3 staff templates | **38** |
| Without `fill` attribute on paths | **37** |
| With explicit `fill` (Google button only) | 1 |
| CSS `fill:none` rules | 18 |
| Global fallback rule | **No** |

**SVG sources by file:**

| File | SVG count | CSS coverage |
|------|-----------|--------------|
| `staff-app-v3-pages.php` | 18 | Scoped per component |
| `staff-app-v3-shell.php` | 9 | Nav, topbar, clock hero |
| `staff-app-easy.php` | 5 | Login features + secure |
| `staff-portal-shift.php` | 2 | Shift banner |
| `notification-list.php` | 1 | Empty state |
| `staff-app-v3-public.php` | 1 | Error icon |
| `offline.php` | 1 | WiFi empty state |

**Mechanism:** SVG 1.1 default fill is `#000`. Templates use stroke-only paths (`<path d="…"/>` with no fill). CSS must set `fill: none; stroke: …` on the parent selector. If CSS is stale or absent, browsers render **solid black shapes** — visually identical to “black placeholder blocks.”

**Correct pattern (already used in one file):** `includes/staff-app-header.php` sets `fill="none" stroke="currentColor"` on the SVG element directly.

**Theme fallback icons** (`includes/theme-icons.php`) use `fill="currentColor"` — safe when logo missing.

---

## 5. Service Worker Audit

**File:** `sw.js`  
**Cache name:** `event-staff-v10-v3-staff-pwa`

| Check | Result | Risk |
|-------|--------|------|
| Precaches `staff-app-v3.css` | Yes | Stale copy at install time |
| Static fetch strategy | **`return cached \|\| network`** | **HIGH** — old CSS served before network update |
| Old cache purge on activate | Deletes keys ≠ current `CACHE_NAME` | OK only after bump |
| `registration-v3.css` in `CORE_ASSETS` | **No** | LOW — offline registration/messages token |
| Navigate fallback | `offline.php` | OK |

**Stale CSS scenario (installed PWA):**

1. User installed PWA when CSS lacked SVG `fill:none` rules (pre–Phase 10/12).
2. SW precached that CSS under `event-staff-v10-v3-staff-pwa`.
3. Server now has correct CSS with `?v=filemtime`, but SW returns **cached old CSS first**.
4. All 37 stroke SVGs render as black blocks until cache cleared or `CACHE_NAME` bumped + reinstall.

**No stale JS detected on server** — same cache-first risk applies to `staff-app-v3.js` if ever cached pre-fix.

---

## 6. Root Cause Analysis

### Primary root cause (HIGH)

**Inline SVGs depend on scoped CSS `fill:none`; default SVG fill is black.**

- **Evidence:** 37/38 inline SVGs lack `fill="none"` in markup; no global `.es-v3 svg` fallback.
- **Trigger:** Stale/missing `staff-app-v3.css` (especially via SW cache-first on installed PWA).
- **User-visible symptom:** Solid black rectangles/circles where icons should be (nav, dashboard tiles, check-in scanner, shift banner, login feature chips).

### Amplifier (HIGH)

**Service worker cache-first for CSS** (`sw.js` lines 121–134).

- Serves precached stylesheet before network, even when URL includes `?v=` (cache key is full request URL, but unversioned precache entry `./assets/css/staff-app-v3.css` matches first).

### Contributing factors

| ID | Factor | Severity |
|----|--------|----------|
| CSS-01 | `offline.php` without CSS cache buster | MEDIUM |
| CSS-02 | Missing `-webkit-mask-image` on empty-card pseudo | LOW (orange circle, not black) |
| UI-01 | Logo wrap dark background when image slow/offline | LOW–MEDIUM |
| UI-02 | Dark card tokens mistaken for broken UI | INFO |

### Ruled out

- Missing route files (all 200)
- Missing PWA PNG icons (all 200)
- Phase 12A status grid CSS absent on production (present)
- Missing Inter font (Google Fonts CDN OK)
- 404 on referenced CSS/JS from guest probe

---

## 7. Issues Register (Per-Issue Detail)

> **Screenshots:** Automated audit cannot capture device screenshots. Reproduce on **Android Chrome** and **installed PWA** by: (1) opening Dashboard + Check-In with DevTools → Application → Cache Storage, (2) comparing with “Clear site data”, (3) noting black filled shapes vs stroked icons.

---

### Issue SVG-01 — Black filled inline icons (all SVG-heavy pages)

| Field | Detail |
|-------|--------|
| **Pages** | Dashboard, Clock In, Active Shift banner, Login features, Bottom nav, Profile hub, Notifications empty, Offline, Shifts, Messages search |
| **Screenshot** | *Not captured — expect solid black 24–64px shapes in icon areas* |
| **Root cause** | SVG paths default to `fill:#000`; CSS `fill:none` not applied when stylesheet stale/missing |
| **File responsible** | `includes/staff-app-v3-pages.php`, `includes/staff-app-v3-shell.php`, `includes/staff-app-easy.php`, `includes/staff-portal-shift.php`, `includes/components/notification-list.php`, `offline.php` + `assets/css/staff-app-v3.css` |
| **Severity** | **HIGH** |
| **Recommended fix** | (1) Add global `.es-v3 svg, .es-v3 svg path { fill: none; }` with stroke inheritance; (2) Add `fill="none" stroke="currentColor"` on critical inline SVGs in PHP; (3) Bump SW cache + stale-while-revalidate for CSS |

---

### Issue SW-01 — Stale CSS on installed PWA

| Field | Detail |
|-------|--------|
| **Pages** | All (installed PWA mode) |
| **Screenshot** | *Icons black on standalone app; OK in fresh incognito* |
| **Root cause** | `sw.js` cache-first: `return cached \|\| network` for CSS |
| **File responsible** | `sw.js` |
| **Severity** | **HIGH** |
| **Recommended fix** | Bump `CACHE_NAME`; use network-first or stale-while-revalidate for CSS/JS; call `skipWaiting` + reload prompt on update |

---

### Issue CSS-01 — Offline page stale stylesheet

| Field | Detail |
|-------|--------|
| **Pages** | Offline |
| **Screenshot** | *Offline empty state with black WiFi icon* |
| **Root cause** | `offline.php` links CSS without `?v=filemtime`; SW precaches unversioned URL |
| **File responsible** | `offline.php` |
| **Severity** | **MEDIUM** |
| **Recommended fix** | Add `staffV3CssVersion()` cache buster to offline CSS link |

---

### Issue CSS-02 — WebKit mask prefix missing

| Field | Detail |
|-------|--------|
| **Pages** | Shifts (empty shift card) |
| **Screenshot** | *Solid orange circle instead of calendar icon on iOS Safari* |
| **Root cause** | `.es-v3__empty-card::before` uses `mask-image` without `-webkit-mask-image` |
| **File responsible** | `assets/css/staff-app-v3.css` (~line 1099) |
| **Severity** | **LOW** |
| **Recommended fix** | Duplicate mask properties with `-webkit-` prefix |

---

### Issue SW-02 — registration-v3.css not precached

| Field | Detail |
|-------|--------|
| **Pages** | Registration (`index.php`), token messages view |
| **Screenshot** | *Unstyled token page when offline* |
| **Root cause** | Not in `CORE_ASSETS` |
| **File responsible** | `sw.js` |
| **Severity** | **LOW** (secondary registration flow) |
| **Recommended fix** | Add to `CORE_ASSETS` if offline registration required |

---

### Issue UI-01 — Login logo wrap dark square

| Field | Detail |
|-------|--------|
| **Pages** | Login |
| **Screenshot** | *40×40 dark navy square before logo loads* |
| **Root cause** | `.es-v3-login__logo-wrap { background: var(--es-secondary) }`; logo from external `olasentra.com` |
| **File responsible** | `assets/css/staff-app-v3.css`, `includes/brand-logo.php` |
| **Severity** | **LOW** |
| **Recommended fix** | Optional: transparent wrap, local logo mirror, or skeleton shimmer |

---

## 8. Production Fix Plan (Do Not Deploy Until Approved)

**Phase 12D — Rendering fix bundle (recommended order):**

1. **Defence in depth — markup**  
   Add `fill="none" stroke="currentColor" stroke-width="2"` to all inline staff PWA SVGs (or generate via helper).

2. **Defence in depth — CSS**  
   Add at top of v3 icon section:
   ```css
   .es-v3 svg { fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
   .es-v3 svg [fill]:not([fill="none"]) { fill: currentColor; stroke: none; } /* Google btn */
   ```

3. **Service worker**  
   - Bump `CACHE_NAME` to `event-staff-v11-v3-staff-pwa` (or date-stamped).  
   - Change CSS/JS strategy to network-first with cache fallback, or stale-while-revalidate.  
   - Precache versioned CSS URL or rely on runtime cache only.

4. **Offline shell**  
   - Add `?v=<?= staffV3CssVersion() ?>` to `offline.php` CSS link.

5. **WebKit masks**  
   - Add `-webkit-mask-*` duplicates on `.es-v3__empty-card::before`.

6. **Verification**  
   - Run `scripts/phase12c-ui-render-audit.php` (expect 0 HIGH issues).  
   - Device test: installed PWA before/after cache clear.  
   - Regression: Phase 12, 12A, 12B suites.

7. **Deploy**  
   - `powershell -ExecutionPolicy Bypass -File .\deploy.ps1`  
   - Ask affected users to close/reopen PWA or clear site data once.

---

## 9. Artifacts

| Artifact | Path |
|----------|------|
| Route probe JSON | `docs/phase12-audit-probe-20260621-221212.json` |
| Static UI audit JSON | `docs/phase12c-ui-render-audit-20260621-211317.json` |
| Static audit script | `scripts/phase12c-ui-render-audit.php` |
| This report | `docs/PHASE12C-UI-RENDERING-AUDIT-REPORT.md` |

---

## 10. Sign-Off

| Item | Status |
|------|--------|
| Full page audit | Complete |
| Asset audit | Complete — 0 missing assets on production |
| CSS audit | Complete — defects documented |
| SVG/icon audit | Complete — 37 at-risk inline SVGs |
| Service worker audit | Complete — cache-first stale CSS confirmed |
| Root cause analysis | **SVG default fill + SW stale CSS** |
| Fixes deployed | **No** (awaiting approval) |
| **Final verdict** | **ISSUES FOUND** |
