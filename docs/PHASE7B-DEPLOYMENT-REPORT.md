# Phase 7B — Combined Deployment Report (Option B)

**Verdict:** **DEPLOYMENT SUCCESSFUL**

**Deployed at:** 2026-06-21T06:55:45+01:00  
**Target:** `register.olasentra.com` (`public_html` via FTP)  
**Strategy:** Phase 7 Design System + Phase 5C Login Parity (shell + OTP JS + login UI)

**Rollback status:** Not required — all uploads verified by size + SHA-256.

---

## Pre-deploy requirements

| Requirement | Result |
|-------------|--------|
| Deploy safety gate run | PASS |
| `deploy_allowed = true` | PASS (`docs/phase2-deploy-safety-gate.json`) |
| Full FTP backup | PASS — 10/10 files backed up |
| SHA-256 recorded | PASS — see tables below |
| Production copies exist | PASS — all paths downloaded before upload |

**Static tests before upload:**

- `phase5c-login-parity-test.php` — **28/28 PASS**
- `phase7-design-system-test.php` — **22/22 PASS**

---

## 1. Deployment file manifest (10 files)

| # | File | Local SHA-256 (deployed) | Verified on production |
|---|------|--------------------------|----------------------|
| 1 | `assets/css/staff-app-v3.css` | `9e6576850c29c1a0c8f6a49acb46d6da739a9cf89c3af45f7a7a77b702d2e095` | MATCH |
| 2 | `assets/css/notifications.css` | `d940b2213bf362027790ca3ac988b0bc7ff4fdab93e4bf9028786c206ccb2fe0` | MATCH |
| 3 | `assets/js/staff-app-v3.js` | `0ee324f7c4c09461a7cbd0452c9b8658c53ea7503d351b6a50d09ec856fee2ac` | MATCH |
| 4 | `assets/js/staff-portal-email-otp.js` | `fdc2831a66246c5db49829e42a036dce8b1eafed1351660ea345ab719e92d8ba` | MATCH |
| 5 | `includes/staff-app-v3-pages.php` | `2c5ee4b85c72c8cd8f01d1e0d3a651e0d880d1f30e4d971ba3671333de4d8f13` | MATCH |
| 6 | `includes/staff-app-v3-shell.php` | `1812996bee04fecdc222364964b5c8b0170812b4382aa8fddd97298311e48c68` | MATCH |
| 7 | `includes/staff-portal-shift.php` | `2301fb975557353ed895557e17612446d0f2144ada36066ff260304566d290d7` | MATCH |
| 8 | `includes/components/notification-list.php` | `33a7d718a83535e3c3db85f073a2582ac56d42570c36587721c6ebb7bc0cb999` | MATCH |
| 9 | `includes/staff-app-easy.php` | `e2eacdba4464bac629fd894ae64c87dca3a48c0f23ebac0eeabe226afb8d49a9` | MATCH |
| 10 | `offline.php` | `dddf45db7197ec8926a585a3c2936f2d6c4b2530edc03f477163802725b871f5` | MATCH |

**Not deployed (unchanged on production):** `config.php`, DB configs, OTP API PHP (`staff-portal-email-otp.php`, `api/staff-portal-otp-*`) — already present on server from prior release.

---

## 2. Backup location

```
storage/backups/phase7b-pre-deploy-20260621-065446/
├── manifest.json
├── deploy-result.json
├── post-deploy-verify/     (FTP re-download after upload)
└── [mirrored production paths before deploy]
```

**Rollback:** Re-upload files from backup root (not `post-deploy-verify/`) via FTP to same remote paths.

---

## 3. Production hash verification

Post-deploy FTP download confirmed **byte-identical** uploads (size + SHA-256 match local).

**Notable pre-deploy → post-deploy changes:**

| File | Production hash before | Deployed hash after |
|------|------------------------|---------------------|
| `includes/staff-app-easy.php` | `8953a19a…` (Google-only UI) | `e2eacdba…` (Google + OTP + Register) |
| `includes/staff-app-v3-shell.php` | `ce7c622e…` (no OTP JS on guest) | `1812996b…` (loads `staff-portal-email-otp.js`) |
| `assets/css/staff-app-v3.css` | `b40d11f9…` | `9e657685…` (Phase 7 design system) |
| `offline.php` | `9a9ba7d1…` (legacy light) | `dddf45db…` (v3 dark) |

Full before/after hashes: `storage/backups/phase7b-pre-deploy-20260621-065446/deploy-result.json`

---

## 4. Login parity verification (production HTML)

HTTP GET `https://register.olasentra.com/staff-app.php` (2026-06-21 post-deploy):

| Check | Result |
|-------|--------|
| Google Login visible | PASS — `Sign in with Google` |
| Email OTP visible | PASS — `Sign in with Email Code (OTP)` + `#staff-portal-email-otp` |
| OTP JS loaded | PASS — `staff-portal-email-otp.js` in page |
| Register visible | PASS — `Create Account / Register` |
| Approved hero copy | PASS |
| No PPS login | PASS — no `staff_portal_verify`, no `pps_last4` |
| HTTP 200 | PASS |

**Offline page:** `es-ds__empty` present; legacy `staff-app.css` absent — PASS.

---

## 5. Device test results

### Automated (server-side / HTTP) — PASS

| Area | Method | Result |
|------|--------|--------|
| Login page loads | HTTP 200 | PASS |
| Google + OTP + Register markup | HTML grep | PASS |
| No PPS on login | HTML grep | PASS |
| OTP client script enqueued | HTML grep | PASS |
| Offline page v3 | HTML grep | PASS |
| CSS deployed | HTTP 200, 54585 bytes | PASS |

### Manual device matrix — **PENDING USER SIGN-OFF**

Required post-deploy on real devices (iPhone Safari + Android Chrome):

**Authentication:** Google Login · Email OTP Send · Email OTP Verify · Register  

**Staff app:** Dashboard · Check In · Active Shift · Notifications · Messages · Profile · Documents · Settings  

**PWA:** Install Prompt · Installed Mode · Offline Page  

**Rollback triggers if any fail:** login unavailable, Google fails, OTP send/verify fails, dashboard or check-in inaccessible.

---

## 6. Risk assessment (post-deploy)

| Risk | Status |
|------|--------|
| Auth regression | Mitigated — login HTML verified; device OTP flow pending |
| Check-in / GPS | Low — `staff-app-v3.js` GPS block unchanged; hash diff is PWA install only |
| Cache stale | Medium — advise hard refresh or private window on first test |
| Rollback available | Yes — full pre-deploy backup on disk |

---

## 7. Rollback plan (if needed)

1. Stop further changes.
2. From `storage/backups/phase7b-pre-deploy-20260621-065446/`, FTP upload pre-deploy copies for affected files.
3. Verify production hashes match `production_hash` in `deploy-result.json`.
4. Re-test guest login (Google-only restored if full rollback) and signed-in home.

**Current rollback status:** **Not required.**

---

## 8. Final verdict

# DEPLOYMENT SUCCESSFUL

Phase 7B Option B deployed to production with safety gate PASS, full backup, and FTP hash verification for all 10 files. Production login page confirms Google + Email OTP + Register with no PPS.

**Next step:** Complete manual device test matrix above. If any rollback trigger fires, restore from backup and report **ROLLBACK REQUIRED**.

---

**Deploy script:** `scripts/deploy-phase7b-combined.ps1`  
**Machine-readable result:** `storage/backups/phase7b-pre-deploy-20260621-065446/deploy-result.json`
