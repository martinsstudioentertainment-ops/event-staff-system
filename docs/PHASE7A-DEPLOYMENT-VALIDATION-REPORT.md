# Phase 7A — Final Deployment Validation & Release Approval

**Status:** Validation complete — **NO FTP DEPLOYMENT PERFORMED**

**Date:** 2026-06-21

**Scope validated:** Phase 7 design system (8-file manifest) + dependency analysis against production snapshot (`_recovery-staging/production-snapshot-20260621-055543`)

---

## Final recommendation

# APPROVED WITH CONDITIONS

Phase 7 UI/CSS changes pass static validation and do not modify protected business logic in the manifest files. **Do not FTP until conditions below are met.**

---

## 1. Deployment file manifest

Exact 8-file list (SHA-256 local, 2026-06-21):

| # | Path | Size | SHA-256 |
|---|------|------|---------|
| 1 | `assets/css/staff-app-v3.css` | 53.3 KB | `9E6576850C29C1A0C8F6A49ACB46D6DA739A9CF89C3AF45F7A7A77B702D2E095` |
| 2 | `assets/css/notifications.css` | 8.7 KB | `D940B2213BF362027790CA3AC988B0BC7FF4FDAB93E4BF9028786C206CCB2FE0` |
| 3 | `assets/js/staff-app-v3.js` | 9.7 KB | `0EE324F7C4C09461A7CBD0452C9B8658C53EA7503D351B6A50D09EC856FEE2AC` |
| 4 | `includes/staff-app-v3-pages.php` | 32.4 KB | `2C5EE4B85C72C8CD8F01D1E0D3A651E0D880D1F30E4D971BA3671333DE4D8F13` |
| 5 | `includes/staff-portal-shift.php` | 8.0 KB | `2301FB975557353ED895557E17612446D0F2144ADA36066FF260304566D290D7` |
| 6 | `includes/components/notification-list.php` | 6.1 KB | `33A7D718A83535E3C3DB85F073A2582AC56D42570C36587721C6EBB7BC0CB999` |
| 7 | `includes/staff-app-easy.php` | 8.5 KB | `E2EACDBA4464BAC629FD894AE64C87DCA3A48C0F23EBAC0EEABE226AFB8D49A9` |
| 8 | `offline.php` | 2.0 KB | `DDDF45DB7197EC8926A585A3C2936F2D6C4B2530EDC03F477163802725B871F5` |

**Additional files in manifest:** None for Phase 7 core.

**Files NOT in manifest but required if deploying `staff-app-easy.php` (Phase 5C login parity):**

| Path | Why |
|------|-----|
| `includes/staff-app-v3-shell.php` | Loads `staff-portal-email-otp.js` on guest pages — **not on production snapshot** |
| `assets/js/staff-portal-email-otp.js` | OTP send/verify client flow |
| `includes/staff-portal-email-otp.php` | OTP handlers (exists on production snapshot) |
| `api/staff-portal-otp-send.php` | Send endpoint (exists on production snapshot) |
| `api/staff-portal-otp-verify.php` | Verify endpoint (exists on production snapshot) |
| `includes/mobile/services/MobileOtpService.php` | `staff_portal` purpose (local only; verify prod parity before OTP deploy) |

**Recommendation:** Either deploy **7 files only** (exclude `staff-app-easy.php` for a pure Phase 7 UI release), **or** deploy the Phase 5C bundle together with the 8-file manifest so Email OTP login works end-to-end.

**Explicitly excluded (correct):** `config.php`, DB configs, `storage/`, service account JSON, `sw.js` (optional follow-up), `manifest.php`.

---

## 2. Validation checklist results

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | No PHP fatal errors | **PASS** | `php -l` clean on all PHP manifest files |
| 2 | No PHP warnings | **PASS (static)** | Lint only; runtime warnings need device smoke test |
| 3 | No JavaScript console errors | **PASS (static)** | `node --check assets/js/staff-app-v3.js` OK; null-guards on DOM refs |
| 4 | No database migrations | **PASS** | No migration files in manifest |
| 5 | No schema changes | **PASS** | No DDL in manifest files |
| 6 | No API contract changes | **PASS** | No `api/mobile/` or manifest API edits |
| 7 | No OAuth changes | **PASS** | No edits to `staff-google-oauth.php` / callback |
| 8 | No OTP verification changes | **PASS*** | *`staff-app-easy.php` is UI only; OTP logic files not in manifest |
| 9 | No attendance logic changes | **PASS** | `staff-portal-shift.php`: banner/note markup + copy only; query functions unchanged |
| 10 | No clock-in logic changes | **PASS** | `staff-app-v3-pages.php` check-in block unchanged; `staff-app-v3.js` GPS block unchanged |
| 11 | No GPS logic changes | **PASS** | `haversineMeters`, `venueCheckinAllowed` unchanged in JS |
| 12 | No BIB logic changes | **PASS** | BIB display/hidden fields unchanged (render-only) |
| 13 | No shift-completion logic changes | **PASS** | No edits to completion repositories |
| 14 | No mobile API changes | **PASS** | No `api/mobile/` files in manifest |
| 15 | No production config changes | **PASS** | `config.php` not included |

**Automated regression:**

| Script | Result |
|--------|--------|
| `scripts/phase7-design-system-test.php` | **22/22 PASS** |
| `scripts/phase5c-login-parity-test.php` | **28/28 PASS** (local tree; relevant if deploying login file) |

---

## 3. Per-file change classification (protected areas)

| File | Change type | Protected logic touched? |
|------|-------------|------------------------|
| `staff-app-v3.css` | Design tokens, components, empty states, PWA CSS | **No** |
| `notifications.css` | Badge colour, WhatsApp dark theme | **No** |
| `staff-app-v3.js` | PWA install visibility only (lines ~162–230) | **No** — GPS/check-in block preserved |
| `staff-app-v3-pages.php` | Profile/documents/settings/messages markup, empty states | **No** — check-in PHP block not modified |
| `staff-portal-shift.php` | Banner HTML class + hours note copy (“shift hours”) | **No** — SQL/monitoring functions unchanged |
| `notification-list.php` | Empty state markup | **No** |
| `staff-app-easy.php` | Phase 5C login UI + profile banner copy | **No auth logic** — uses existing endpoints |
| `offline.php` | v3 HTML/CSS shell | **No** |

---

## 4. Risk assessment

| Risk | Level | Detail |
|------|-------|--------|
| CSS/cache stale on devices | Medium | Hard refresh or cache-bust `?v=filemtime` on CSS/JS after deploy |
| `staff-app-easy.php` without OTP JS shell | **High** | OTP UI visible but non-functional if shell/JS not deployed |
| PWA install hidden until prompt | Low | Intended; iOS shows install row when not standalone |
| Shift banner copy change | Low | Display only; monitoring unchanged |
| Offline page rebrand | Low | Same actions (reload, home link) |
| Partial deploy vs full parity | Medium | Production snapshot lacks Phase 5C login UI and shell OTP script |

---

## 5. Rollback plan

1. **Before deploy:** FTP download copies of all 8 production files to `storage/backups/phase7a-pre-deploy-YYYYMMDD-HHMMSS/`.
2. **If UI regression:** Re-upload backed-up files only (same paths under `public_html` on register site).
3. **If login broken:** Roll back `staff-app-easy.php` first; restore production Google-only login.
4. **If check-in affected:** Roll back `staff-app-v3.js` and `staff-app-v3-pages.php` (unlikely — logic unchanged).
5. **Verify rollback:** Guest login, signed-in home, check-in POST, notifications list load.
6. **No DB rollback required** — no schema changes.

---

## 6. Production backup plan

| Step | Action |
|------|--------|
| 1 | Record pre-deploy SHA-256 of each production file (FTP download or cPanel file manager) |
| 2 | Copy to `storage/backups/phase7a-pre-deploy-<timestamp>/` preserving paths |
| 3 | Document current `staff-app.php` guest login behaviour (screenshot) |
| 4 | Confirm `config.php` and DB configs **not** overwritten |
| 5 | Run deploy to **register.olasentra.com** `public_html` only (same target as existing `deploy.ps1`) |
| 6 | Post-deploy: verify file sizes/hashes on server match manifest |

---

## 7. Device validation status

| Area | Static validation | Device test required post-deploy |
|------|-------------------|----------------------------------|
| Login page | PASS (lint + Phase 5C tests) | **Yes** |
| Google Login | PASS (unchanged OAuth paths) | **Yes** |
| Email OTP Login | **Conditional** (needs shell+JS if deploying easy.php) | **Yes** |
| Dashboard | PASS (Phase 7 markup/CSS) | **Yes** |
| Check In | PASS (logic unchanged) | **Yes** |
| Active Shift | PASS (banner markup only) | **Yes** |
| Notifications | PASS | **Yes** |
| Messages | PASS | **Yes** |
| Profile | PASS | **Yes** |
| Documents | PASS | **Yes** |
| Settings | PASS | **Yes** |
| Install Prompt | PASS (JS logic reviewed) | **Yes** — Android Chrome + iOS Safari |
| Installed PWA | PASS (standalone hide CSS/JS) | **Yes** |
| Offline Page | PASS (v3 offline.php) | **Yes** — airplane mode or `offline.php` direct |

**Note:** Phase 7A did not run live device tests (validation-only per request). Device matrix is **mandatory gate before production approval sign-off**.

---

## 8. Conditions for full approval

Deploy may proceed when **all** are true:

1. **Choose deploy strategy:**
   - **Option A (recommended for Phase 7 only):** Deploy 7 files — **exclude** `staff-app-easy.php` until Phase 5C bundle is ready.
   - **Option B (combined release):** Deploy 8-file manifest **plus** Phase 5C dependencies (`staff-app-v3-shell.php`, `staff-portal-email-otp.js`, verify `MobileOtpService.php` on prod).

2. Pre-deploy backup completed (Section 6).

3. Post-deploy device smoke test passes all rows in Section 7.

4. Google sign-in and check-in verified on a real device (protection rules).

5. If Option B: send OTP test email on production and complete verify flow.

---

## 9. Summary

| Deliverable | Location |
|-------------|----------|
| Deployment file manifest | Section 1 |
| Risk assessment | Section 4 |
| Rollback plan | Section 5 |
| Production backup plan | Section 6 |
| Validation results | Sections 2–3, 7 |
| Final recommendation | **APPROVED WITH CONDITIONS** (top of document) |

**No FTP deployment was executed in Phase 7A.**
