# Phase 11B — OTP Button Click Block Fix Deployment Report

**Verdict:** **DEPLOYMENT SUCCESSFUL**

**Deployed at:** 2026-06-21T08:01:12+01:00  
**Target:** `register.olasentra.com` (`public_html` via FTP)  
**Scope:** Layout-only fix — OTP send button clickability vs fixed PWA install banner

**Rollback status:** Not required — all 3 uploads verified by size + SHA-256; production HTTP probes pass.

---

## Root cause confirmation

Phase 11A identified a **layout interaction issue only** (not OTP logic, API, CSRF, or OAuth):

- Phase 11 compact login layout reduced bottom spacing on guest login screens.
- The fixed PWA install banner (`.es-v3__pwa-banner`, `z-index: 90`) sits above the scrollable main content.
- On small screens / when the banner is visible, the banner intercepts taps on `#staff-portal-email-send` even though the button appears reachable.
- OTP JavaScript handlers, send API, and verification flow were confirmed working in Phase 11A.

---

## Fix applied (layout / UX only)

| Change | Purpose |
|--------|---------|
| `--es-guest-pwa-clearance: 6.5rem` + guest compact main `padding-bottom` | Keeps OTP controls above the install banner safe area |
| `.es-v3--pwa-banner-open` body class + extra padding when banner shown | Dynamic clearance when install prompt is visible |
| `pointer-events: none` on guest compact PWA banner; `auto` on Install/Dismiss | Clicks pass through banner chrome to OTP controls below |
| `scroll-margin-bottom` on OTP send/verify/error | Scroll targets remain visible above banner |
| `showError()` → `scrollIntoView()` in OTP JS | Optional UX — error messages scroll into view (no verification logic change) |
| `es-v3--pwa-banner-open` toggle in `staff-app-v3.js` | Coordinates CSS spacing with banner visibility |

**Not changed:** OTP verification logic, OAuth, APIs, database, session, auth architecture.

---

## Pre-deploy

| Step | Result |
|------|--------|
| Safety gate | **PASS** — `deploy_allowed = true` |
| Phase 11B tests | **PASS** — 21/21 |
| Phase 5C login parity | **PASS** — 34/34 |
| Phase 11 auth/registration | **PASS** — 26/26 |
| Pre-deploy backup | **PASS** — 3/3 files backed up from production |

---

## Files modified (3 deployed)

| File | Change |
|------|--------|
| `assets/css/staff-app-v3.css` | Guest PWA clearance padding, banner click passthrough, OTP scroll margins, banner-open spacing |
| `assets/js/staff-app-v3.js` | Toggle `es-v3--pwa-banner-open` on guest compact login when PWA banner shown/hidden |
| `assets/js/staff-portal-email-otp.js` | Scroll OTP error area into view when displayed (UX only) |

**Support (not deployed):** `scripts/phase11b-otp-click-fix-test.php`, `scripts/deploy-phase11b-otp-click-fix.ps1`, `scripts/phase11b-post-deploy-verify.ps1`

---

## Backup location

```
storage/backups/phase11b-pre-deploy-20260621-080040/
├── manifest.json
├── deploy-result.json
├── post-deploy-verify/
├── assets/css/staff-app-v3.css          (pre-deploy production copy)
├── assets/js/staff-app-v3.js
└── assets/js/staff-portal-email-otp.js
```

---

## Hash verification

| File | Pre-deploy SHA-256 | Deployed SHA-256 | Verified |
|------|-------------------|------------------|----------|
| `assets/css/staff-app-v3.css` | `052df0440979471ef850606fc2af6423b8ab0b0c01c4895cc17bb74cf8ee050a` | `ed99cf062919204359982e1b76c46b8d852067f0a86bd0e3b9a1f253e8785b4b` | **MATCH** |
| `assets/js/staff-app-v3.js` | `40257f324b3d0d40ee3a58cec1fcacbf0fadb1c7eb7afece1f0a9e4f977d2da8` | `5752b53be176c0279add7c4d77deefa4a0638b43561b7ad9026a8a9d0490612f` | **MATCH** |
| `assets/js/staff-portal-email-otp.js` | `fdc2831a66246c5db49829e42a036dce8b1eafed1351660ea345ab719e92d8ba` | `257d89164fd445715e8885e393c2db83f524720c8d4376ccafaba2009013e30d` | **MATCH** |

Upload sizes: CSS 62236 B, v3 JS 10225 B, OTP JS 6818 B — all match local + re-download hash.

---

## Regression results

| Suite | Result |
|-------|--------|
| Phase 11B OTP click fix | **21/21 PASS** |
| Phase 5C login parity | **34/34 PASS** |
| Phase 11 auth/registration | **26/26 PASS** |

### Post-deploy HTTP probes (12/12 PASS)

- Welcome headline, Google Login, OTP send button markup, OTP JS loaded
- Compact login + install banner markup present
- Production CSS: `--es-guest-pwa-clearance`, `pointer-events: none`, `es-v3--pwa-banner-open`
- Production JS: `scrollIntoView`, banner open class toggle
- OTP send API: HTTP 404 for probe email (handler live, expected rejection)

---

## Device testing checklist (manual)

Verify on **Android Chrome** and **installed PWA** on a small-screen device:

- [ ] Google Login still works
- [ ] Email OTP Send responds to tap when install banner is visible
- [ ] OTP error message visible and scrolls into view on failure
- [ ] Install banner Install/Dismiss buttons still work
- [ ] Compact login layout unchanged

---

## Rollback plan

If OTP taps still fail or install banner breaks:

1. Restore pre-deploy files from backup via FTP:

```powershell
# From project root — upload backed-up copies
$backup = "storage\backups\phase11b-pre-deploy-20260621-080040"
# Upload:
#   $backup\assets\css\staff-app-v3.css  → assets/css/staff-app-v3.css
#   $backup\assets\js\staff-app-v3.js    → assets/js/staff-app-v3.js
#   $backup\assets\js\staff-portal-email-otp.js → assets/js/staff-portal-email-otp.js
```

2. Or run deploy script with backup files copied back to workspace root paths, then re-upload.

3. Hard-refresh / clear site data on test device to bypass cached CSS/JS.

4. Re-run `scripts/phase11b-post-deploy-verify.ps1` — pre-deploy hashes should match restored production files.

**Pre-deploy hashes for rollback verification:**

- `assets/css/staff-app-v3.css` → `052df0440979471ef850606fc2af6423b8ab0b0c01c4895cc17bb74cf8ee050a`
- `assets/js/staff-app-v3.js` → `40257f324b3d0d40ee3a58cec1fcacbf0fadb1c7eb7afece1f0a9e4f977d2da8`
- `assets/js/staff-portal-email-otp.js` → `fdc2831a66246c5db49829e42a036dce8b1eafed1351660ea345ab719e92d8ba`

---

## Related documents

- Phase 11A investigation: `docs/PHASE11A-OTP-REGRESSION-INVESTIGATION.md`
- Phase 11 deploy: `docs/PHASE11-DEPLOYMENT-REPORT.md`
