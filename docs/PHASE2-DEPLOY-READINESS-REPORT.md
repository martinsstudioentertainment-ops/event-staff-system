# Phase 2 — Production Snapshot, Final Restoration & Deploy Readiness

**Integrity ID:** `PHASE2-20260621-OLASENTRA`  
**Date:** 2026-06-21  
**Status:** Complete — **deploy readiness PASS** (local only, **nothing deployed**)

---

## Executive summary

Phase 2 created a **read-only production FTP snapshot** (728+ PHP/JS/CSS files), restored **14 of 27** previously empty paths from production, classified all remaining gaps, and updated the deploy safety gate. The gate now reports **`deploy_allowed: true`**.

Production was not modified. Attendance, clock-in, GPS, BIB, auth, and mobile API were not changed.

---

## 1. Production snapshot

| Item | Value |
|------|-------|
| Location | `_recovery-staging/production-snapshot-20260621-055543/` |
| Files downloaded | **732** (PHP, JS, CSS) |
| Method | Read-only recursive FTP (`scripts/phase2-production-snapshot.ps1`) |
| Skipped remote dirs | `storage/backups`, `storage/logs`, `node_modules`, `vendor`, `.git` |
| Manifest | `snapshot.json`, `manifest.csv` (in snapshot folder) |

**Note:** First snapshot run downloaded all files then hit a JSON export error; metadata was finalized manually. Re-run script is fixed for future snapshots.

---

## 2. Final restoration report

### Phase 1 → Phase 2 progression

| Stage | Zero-byte / blocking files |
|-------|---------------------------|
| Phase 1 initial | 216 zero-byte in deploy tree |
| After Phase 1 reference restore | 27 zero-byte |
| After Phase 2 production restore | **14 restored**, 4 legacy empty (safe excluded), 9 local-only (not on production) |

### Restored from production (14 files)

| File | Bytes |
|------|------:|
| `account-deletion.php` | 2,665 |
| `admin/mobile-portal.php` | 74 |
| `admin/settings-mobile-portal.php` | 12,864 |
| `includes/mobile/services/MobilePortalConfigService.php` | 5,376 |
| `cron/reset-staff-checkins.php` | 3,630 |
| `api/mobile-config-probe.php` | 488 |
| `api/mobile-dashboard-probe.php` | 973 |
| `api/probe-dash2.php` | 732 |
| `api/probe-ping.php` | 58 |
| `api/probe-reg-save.php` | 3,073 |
| `api/probe-reg-submit.php` | 3,570 |
| `api/probe-staff-full-verify.php` | 8,854 |
| `api/registration-email-otp-send.php` | 1,882 |
| `scripts/diag-otp-send.php` | 1,622 |

Tool: `scripts/phase2-restore-from-production.ps1`  
Summary: `docs/phase2-restoration-summary.json`

### Still empty — safe to exclude (legacy, not on production)

| File | Classification |
|------|----------------|
| `includes/footer.php` | Legacy |
| `includes/header.php` | Legacy |
| `includes/settings.php` | Legacy |
| `includes/sidebar.php` | Legacy |

These are **0 bytes locally** but **absent from production** — old layout stubs, not used by current admin/staff shells.

### Local-only — not on production (no restore possible)

| File | Classification |
|------|----------------|
| `_extract_transcripts.js` | Safe to exclude |
| `_fix_strings_report.js` | Safe to exclude |
| `_reconstruct_manifest.js` | Safe to exclude |
| `scripts/backup-events-global.php` | Admin only (dev script) |
| `scripts/generate-branding-assets.php` | Safe to exclude |
| `scripts/generate-play-feature-graphic.php` | Safe to exclude |
| `scripts/generate-play-screenshots.php` | Safe to exclude |
| `scripts/play-store-graphics-lib.php` | Safe to exclude |
| `scripts/test-otp-send-production.php` | Diagnostic |

Classification source: `scripts/phase2-file-classification.json`

---

## 3. File classification summary

| Category | Count | Deploy gate treatment |
|----------|------:|----------------------|
| Production critical | 1 | Must be non-zero — **restored** |
| Admin only | 4 | Restored where on production |
| Legacy | 5 | Safe excluded (empty OK) |
| Diagnostic | 10 | Restored from prod or safe excluded |
| Safe to exclude | 7 | Excluded from zero-byte scan |

---

## 4. Production parity report

Compared **local deploy tree** vs **production snapshot** vs **`_tmp-restore`**:

| Metric | Count |
|--------|------:|
| Unique paths compared | 842 |
| Local hash matches production | **555** |
| Local differs from production (overlapping paths) | 128 |
| Local differs from `_tmp-restore` | 73 |
| Production-only paths (not in local) | See JSON |
| Local-only paths (not in production) | See JSON |

Full report: `docs/phase2-production-parity-report.json`

**Interpretation:** Local repo is **deploy-ready for critical paths** but **not byte-identical** to production on 128 overlapping files (expected — local includes Kodaleone fixes, invoice fix, BIB tooling, etc.). Production remains authoritative for live behaviour.

---

## 5. Deploy readiness report

### Safety gate result

```
Critical missing : 0
Critical 0-byte  : 0
Blocking 0-byte  : 0  (22 paths safe-excluded)
deploy_allowed   : true
```

Report: `docs/phase2-deploy-safety-gate.json`

Run before any future deploy:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1
```

### Success criteria

| Criterion | Status |
|-----------|--------|
| No critical runtime files missing | **PASS** |
| No critical runtime files empty | **PASS** |
| Deploy safety gate passes | **PASS** |
| `deploy_allowed = true` | **PASS** |

---

## 6. Remaining risk report

| Risk | Severity | Notes |
|------|----------|-------|
| 128 files differ local vs production | Medium | Review before full-tree deploy; use targeted upload scripts for known changes |
| 4 legacy includes still 0-byte | Low | Safe excluded; not on production |
| 9 local-only dev scripts missing | Low | Not on production; safe excluded or absent |
| Snapshot incomplete dirs | Low | `storage/backups` skipped intentionally (size) |
| `config.php` | Medium | Local restored from reference; never overwrite production credentials on deploy |
| Full FTP tree deploy | High | Only run after gate pass + explicit approval |

---

## 7. New / updated tooling

| Script | Purpose |
|--------|---------|
| `scripts/phase2-production-snapshot.ps1` | Read-only recursive FTP snapshot |
| `scripts/phase2-restore-from-production.ps1` | Restore zero-byte paths from snapshot or live FTP |
| `scripts/phase2-production-parity-audit.ps1` | Local vs production vs reference comparison |
| `scripts/phase2-file-classification.json` | Classifications + safe deploy excludes |
| `scripts/ftp-common.ps1` | Added `Download-FtpFile`, `Get-FtpDirectoryNames` |
| `scripts/deploy-safety-gate.ps1` | Phase 2 safe-exclude support |

---

## 8. What was not done (per instructions)

- No production deploy
- No production data changes
- No changes to Settings, Login, Profile, Attendance, Clock-In, GPS, BIB, shift completion, DB schema, or Mobile API contracts
- No Live Hours Counter, Dashboard, or feature development started

---

## 9. Recommended next phase (after approval)

1. **Approve Phase 2** — confirm `deploy_allowed: true` is acceptable with documented 128 prod diffs
2. **Targeted deploy only** — continue using path-specific upload scripts for known fixes
3. **Optional:** Full-tree deploy audit — diff the 128 mismatched files before any bulk FTP upload
4. **Live Hours Counter** — device investigation (unchanged from audit plan)
5. **Settings / Login / Profile / Dashboard development** — begin only after Phase 2 sign-off

---

## 10. Artifacts

| File | Description |
|------|-------------|
| `docs/PHASE2-DEPLOY-READINESS-REPORT.md` | This report |
| `docs/phase2-restoration-summary.json` | Restoration summary |
| `docs/phase2-restoration-report.json` | Restore script output |
| `docs/phase2-deploy-safety-gate.json` | Gate result (`deploy_allowed: true`) |
| `docs/phase2-production-parity-report.json` | Three-way parity |
| `_recovery-staging/production-snapshot-20260621-055543/` | Production snapshot |

---

*End of Phase 2 report.*
