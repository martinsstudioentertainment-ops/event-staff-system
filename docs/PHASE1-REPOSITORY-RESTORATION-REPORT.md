# Phase 1 — Repository Restoration & Deployment Protection

**Integrity ID:** `PHASE1-20260621-OLASENTRA`  
**Date:** 2026-06-21  
**Status:** Complete (local only — **nothing deployed**)

---

## Executive summary

Production (`admin.olasentra.com`, `register.olasentra.com`) remains operational. The local repository was severely corrupted with **216 zero-byte PHP/JS/CSS files** in the deploy tree. Phase 1 restored **189 files** from `_tmp-restore/event-staff-system`, bringing all **critical runtime paths** back to non-zero size. **27 zero-byte files remain** with no content in any local reference copy; deploy is now **blocked** until those are resolved or explicitly excluded.

Deployment protection is implemented: `deploy.ps1` runs a safety gate before git push/FTP, and `Send-FtpFile` **throws** on 0-byte uploads instead of silently skipping them.

---

## 1. Restoration report

### Initial scan (before restoration)

| Metric | Count |
|--------|------:|
| Zero-byte PHP/JS/CSS (deploy tree) | 216 |
| Source | `docs/phase1-zero-byte-scan.json` |

### Restoration run

| Metric | Count |
|--------|------:|
| Files restored | **189** |
| Source priority | `_tmp-restore` → forensic snapshot → ftp-download |
| Tool | `scripts/restore-zero-byte-from-reference.ps1` |
| Details | `docs/phase1-restoration-report.json` |

### Critical files — status after restoration

All paths required for staff portal, auth, attendance entry, mobile API router, admin login, and PWA shell are **non-zero**:

| File | Bytes (after restore) |
|------|----------------------:|
| `config.php` | 1,790 |
| `sw.js` | 4,378 |
| `status.php` | 12,106 |
| `check-in.php` | 13,598 |
| `staff-portal.php` | 170 |
| `staff-messages.php` | 8,299 |
| `staff-notifications.php` | 1,742 |
| `includes/staff-portal-session.php` | 5,619 |
| `includes/staff-google-oauth.php` | 12,659 |
| `includes/admin-login-otp.php` | 8,490 |
| `includes/mobile/mobile-router.php` | 6,289 |
| `api/mobile/index.php` | 907 |

---

## 2. Files still missing content (27 zero-byte)

These exist locally as **empty placeholders** but have **no non-zero copy** in `_tmp-restore`, forensic snapshot, or ftp-download:

| Category | Files |
|----------|-------|
| Dev / forensic JS (root) | `_extract_transcripts.js`, `_fix_strings_report.js`, `_reconstruct_manifest.js` |
| Admin (mobile portal) | `admin/mobile-portal.php`, `admin/settings-mobile-portal.php` |
| API probes (dev/diag) | `api/mobile-config-probe.php`, `api/mobile-dashboard-probe.php`, `api/probe-*.php`, `api/registration-email-otp-send.php` |
| Legacy includes | `includes/footer.php`, `includes/header.php`, `includes/sidebar.php`, `includes/settings.php` |
| Mobile service | `includes/mobile/services/MobilePortalConfigService.php` |
| Cron | `cron/reset-staff-checkins.php` |
| Scripts (ops/dev) | `scripts/backup-events-global.php`, `scripts/diag-otp-send.php`, branding/play-store generators, `scripts/test-otp-send-production.php` |
| Other | `account-deletion.php` |

**Risk:** Full deploy from local tree would still fail the safety gate. Targeted upload scripts that reference any of these paths would also fail under the new strict FTP rule.

**Recommended resolution (Phase 2):** Pull these files from live production via FTP read-only snapshot; do not guess content.

---

## 3. Production parity report

### Reference comparison (`_tmp-restore/event-staff-system`)

| Metric | Count |
|--------|------:|
| Local PHP/JS/CSS scanned | 790 |
| Missing in local (in reference only) | 12 |
| Missing in reference (local-only) | 76 |
| Local zero-byte (overlapping paths) | 4 |
| Size differences (same path, different bytes) | 71 |
| Hash differences (same size, different content) | 1 |

Full data: `docs/phase1-parity-report.json`

**Interpretation:**

- Local repo has **76 files not present** in the June reference restore (newer crons, Kodaleone fixes, invoice fix, BIB tooling, etc.) — expected drift, not data loss.
- **71 size differences** mean local and reference diverged on many shared paths; production likely matches a mix of both. **Do not assume reference = production.**
- Partial production snapshot exists at `_recovery-staging/ftp-download/` (**50 files only**) — insufficient for full parity.

### Production vs local (observed, not FTP-diffed)

| Area | Production | Local after Phase 1 |
|------|------------|---------------------|
| Admin login, dashboard, attendance | Operational (HTTP 200) | Core paths restored |
| Clock-in / GPS / BIB | Operational (per audit) | Not modified in Phase 1 |
| Mobile API `/api/mobile/v1/config` | Operational | Router + index non-zero |
| Local 0-byte corruption | N/A (production unaffected) | 189 fixed, 27 remain |
| Deploy safety | Previously skipped 0-byte silently | Gate + FTP throw |

---

## 4. Deployment protection implemented

### New / updated scripts

| Script | Purpose |
|--------|---------|
| `scripts/deploy-critical-files.json` | Mandatory non-zero paths before deploy |
| `scripts/deploy-safety-gate.ps1` | Blocks deploy on missing critical files or any 0-byte PHP/JS/CSS in deploy tree |
| `scripts/restore-zero-byte-from-reference.ps1` | Repeatable restoration from reference trees |
| `scripts/repository-parity-audit.ps1` | Local vs `_tmp-restore` diff report |

### Changes

| File | Change |
|------|--------|
| `deploy.ps1` | Step `[0/6] Deploy safety gate` before backup, git push, or FTP |
| `scripts/ftp-common.ps1` | `Send-FtpFile` **throws** on 0-byte files (no silent skip) |

### Current gate result

```
Critical missing : 0
Critical 0-byte  : 0
All 0-byte scan  : 27
Deploy allowed   : NO
```

Report: `docs/phase1-deploy-safety-gate.json`

Run manually:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1 -ReportOnly
```

---

## 5. Deployment safety report

| Check | Result |
|-------|--------|
| Critical files present | PASS |
| Critical files non-zero | PASS |
| Full tree zero-byte scan | **FAIL (27 files)** |
| FTP 0-byte upload | **BLOCKED (throws)** |
| `deploy.ps1` without gate fix | **BLOCKED at step 0** |
| Production data modified | **NO** |
| Production deployed | **NO** |
| Attendance / clock-in / GPS / BIB logic modified | **NO** |
| Settings / login / profile / mobile API contracts modified | **NO** |

---

## 6. Repository integrity report

| Item | Value |
|------|-------|
| Phase ID | `PHASE1-20260621-OLASENTRA` |
| Zero-byte before | 216 |
| Restored from reference | 189 |
| Zero-byte after | 27 |
| Critical path integrity | Restored |
| Parity audit artifact | `docs/phase1-parity-report.json` |
| Restoration artifact | `docs/phase1-restoration-report.json` |
| Safety gate artifact | `docs/phase1-deploy-safety-gate.json` |

---

## 7. Risks

1. **27 files still empty locally** — full deploy blocked; targeted scripts may fail if they reference empty paths.
2. **Reference ≠ production** — `_tmp-restore` is a point-in-time copy; 71 files differ in size from local; production may differ further.
3. **Partial FTP snapshot** — `_recovery-staging/ftp-download` covers only 50 files; not usable for full restore.
4. **Silent skip removed** — old behaviour hid corruption; teams must fix local files before deploy (intentional).
5. **config.php** — local copy restored from reference (1,790 B); production credentials must never be overwritten on deploy (existing rule preserved).

---

## 8. Recommended next phase (Phase 2)

1. **Production FTP read-only snapshot** — download full `public_html` to `_recovery-staging/production-snapshot-YYYYMMDD/` (exclude secrets handling per existing deploy rules).
2. **Restore remaining 27 files** from production snapshot.
3. **Re-run** `restore-zero-byte-from-reference.ps1`, `repository-parity-audit.ps1`, and `deploy-safety-gate.ps1` until gate passes.
4. **Classify dev-only paths** — move probe scripts / forensic JS to `scripts/dev/` or `.gitignore` so they do not block deploy (requires approval).
5. **Live Hours Counter investigation** — reproduce on device before any change (per audit findings).
6. **Phase 0 signed report** — complete remaining smoke-test documentation if still required before feature work.

---

## 9. What was not done (per instructions)

- No production deploy
- No changes to Settings, Login, Profile, Attendance, Clock-In, GPS, BIB, shift completion, DB schema, or Mobile API contracts
- No production data changes
- No destructive operations

---

## 10. Changed files in this phase

| Path | Action |
|------|--------|
| 189 PHP/JS/CSS paths | Restored from reference (see JSON report) |
| `scripts/deploy-critical-files.json` | Added |
| `scripts/deploy-safety-gate.ps1` | Added |
| `scripts/restore-zero-byte-from-reference.ps1` | Added |
| `scripts/repository-parity-audit.ps1` | Added |
| `scripts/ftp-common.ps1` | Strict 0-byte FTP block |
| `deploy.ps1` | Safety gate step |
| `docs/phase1-*.json` | Audit artifacts |
| `docs/PHASE1-REPOSITORY-RESTORATION-REPORT.md` | This report |

---

*End of Phase 1 report.*
