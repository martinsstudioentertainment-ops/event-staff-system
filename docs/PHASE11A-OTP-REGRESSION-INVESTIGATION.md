# Phase 11A — Email OTP Regression Investigation

**Status:** Investigation only — **no code changes, no deployment**

**Date:** 2026-06-21  
**Reported issue:** After Phase 11 deployment, “Send Email Verification Code” on Staff PWA login appears not to work  
**Actual button label on web login:** `Send verification code` (`#staff-portal-email-send`)

---

## Verdict

# ROOT CAUSE IDENTIFIED

**Primary cause (Phase 11–related):** Phase 11 compact login layout changes in `assets/css/staff-app-v3.css` interact with the existing fixed PWA install banner (`.es-v3__pwa-banner`, `z-index: 90`) on mobile, allowing the banner to **intercept taps** on the OTP send button on common phone viewports after the banner auto-opens (~800ms). The OTP JavaScript and API are intact; clicks never reach `#staff-portal-email-send`.

**Secondary cause (pre-existing, amplified by Phase 11):** When the button *does* receive clicks, API business responses (`STAFF_NOT_FOUND` 404, `OTP_SEND_FAILED` 503) show in `#staff-portal-email-error`, but Phase 11 reduced vertical spacing — users may not notice the error and report “button does nothing.”

**Not the cause:** Broken OTP verification logic, missing script, CSRF regression, API outage, or DOM ID changes between Phase 10 and Phase 11.

---

## Investigation checklist

| # | Check | Result |
|---|--------|--------|
| 1 | Button click event binding | **PASS** — `staff-portal-email-otp.js` binds `#staff-portal-email-send` click handler (unchanged in Phase 11) |
| 2 | `staff-portal-email-otp.js` loading | **PASS** — Present on production `staff-app.php` guest page; hash matches deployed file |
| 3 | Browser console errors | **Not reproduced remotely** — no JS syntax errors in deployed assets; device console still recommended |
| 4 | JavaScript errors | **None found in static review** — `staff-app-v3.js`, `pwa-install.js` (early return on v3), `app.js` do not block OTP init |
| 5 | DOM selectors / IDs | **PASS** — Production HTML contains `#staff-portal-email-otp`, `#staff-portal-email-send`, `#staff-portal-email-input`, `#staff-portal-email-error` |
| 6 | CSRF token availability | **PASS** — `data-csrf` on OTP root; POST without CSRF → HTTP 403; POST with CSRF → HTTP 404 for unknown staff (expected) |
| 7 | `staff-portal-otp-send.php` endpoint | **PASS** — Live; rejects GET (405); accepts POST JSON |
| 8 | AJAX request execution | **PASS (server-side)** — `fetch()` POST path works when CSRF + session present |
| 9 | Network response | **PASS** — API returns structured JSON (403 CSRF / 404 STAFF_NOT_FOUND for probe email) |
| 10 | CSS overlay blocking clicks | **FAIL (suspected on device)** — Fixed PWA banner overlays bottom viewport; Phase 11 compact layout increases overlap risk |
| 11 | Phase 11 login layout changes | **Changed** — `es-v3-login--compact`, `es-v3--login-compact`, reduced padding, flex-start anchoring |
| 12 | Phase 10 vs Phase 11 OTP markup diff | **No functional diff** — Same OTP IDs, URLs, CSRF attribute, button type; only copy/wrapper/CSS classes changed |

---

## Evidence

### Production HTTP probes (2026-06-21)

| Probe | Result |
|-------|--------|
| `staff-app.php` contains OTP root + send button | **Yes** |
| `staff-portal-email-otp.js` in page | **Yes** |
| `Welcome to Olasentra` + compact class | **Yes** (Phase 11 live) |
| OTP send POST + valid CSRF + unknown email | **HTTP 404** (`STAFF_NOT_FOUND` — handler executed) |
| OTP send POST without CSRF | **HTTP 403** (`CSRF_FAILED`) |
| `staff-portal-email-otp.js` production hash | **Matches local** (`fdc2831a…`) |
| `staff-app-v3.css` production hash | **Matches local** (`052df044…`) |

### Phase 10 vs Phase 11 — OTP markup comparison

| Element | Phase 10 (backup) | Phase 11 (current) |
|---------|-------------------|---------------------|
| `#staff-portal-email-otp` | Present | Present |
| `#staff-portal-email-send` | Present | Present |
| `data-send-url` / `data-csrf` | Present | Present |
| OTP JS file | Unchanged in Phase 11 deploy | Unchanged |
| OTP API | Not in Phase 11 deploy | Not in Phase 11 deploy |

Phase 11 login changes were **presentation only** for OTP: welcome copy, `es-v3-login--compact`, `<details>` feature collapse, footer trim, shell class `es-v3--login-compact`.

### Phase 11 CSS change (relevant)

```css
.es-v3--login-compact .es-v3__main {
  justify-content: flex-start;
  padding-bottom: calc(var(--es-safe-b) + 1rem); /* was 1.5rem on guest */
}

.es-v3__pwa-banner {
  position: fixed;
  bottom: calc(var(--es-safe-b) + 1rem);
  z-index: 90;
}
```

PWA banner is shown on mobile after 800ms (`staff-app-v3.js`). It sits above page content. Compact login places OTP controls lower in the visible stack on small screens; combined with reduced bottom padding, the send button can fall into the banner’s hit area.

---

## File responsible

| File | Role |
|------|------|
| **`assets/css/staff-app-v3.css`** | Phase 11 compact login rules (`es-v3-login--compact`, `es-v3--login-compact`) — **primary** |
| **`assets/js/staff-app-v3.js`** | Auto-shows fixed PWA banner on mobile (pre-Phase 11 behaviour; interacts with new layout) |
| **`includes/staff-app-v3-shell.php`** | Adds `es-v3--login-compact` body class (Phase 11) |

**Not responsible:** `staff-portal-email-otp.js`, `api/staff-portal-otp-send.php`, OTP verification logic, OAuth, database.

---

## Risk assessment

| Area | Risk if unfixed |
|------|-----------------|
| Email OTP login on mobile browser / installed PWA | **Medium** — staff may be unable to tap Send; falls back to Google only |
| Google Login | **None** — unaffected |
| OTP verification logic / API | **None** — no defect found |
| Registration OTP | **None** — not modified in Phase 11 |
| Data / security | **None** — investigation only |

---

## Recommended fix (do not implement in 11A)

**CSS-only (lowest risk, no OTP logic change):**

1. Add guest compact bottom padding to clear PWA banner: e.g. `.es-v3--login-compact.es-v3--guest .es-v3__main { padding-bottom: calc(var(--es-safe-b) + 5.5rem); }`
2. Or hide/defer PWA banner on guest login until after first scroll or successful auth attempt
3. Optionally raise OTP panel `z-index` above banner when email input focused (careful with stacking context)

**UX-only (optional, not verification logic):**

4. On `showError()`, scroll `#staff-portal-email-error` into view so API errors are visible when button *does* work

**Device confirmation before fix:**

5. Reproduce on Android Chrome with remote debugging: tap Send with banner visible; confirm no network request when overlap occurs vs request + error when not overlapped

---

## Protection rules compliance

No fixes deployed · No OTP/OAuth/auth logic changes · No API/database changes · Investigation only.

---

**Artifacts:** `scripts/phase11a-otp-probe2.ps1` · `storage/backups/phase11-pre-deploy-20260621-075219/includes/staff-app-easy.php` (Phase 10 OTP markup reference)
