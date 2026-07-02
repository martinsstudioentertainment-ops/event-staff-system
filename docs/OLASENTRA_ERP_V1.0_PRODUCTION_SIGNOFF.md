# Olasentra ERP Version 1.0 — Production Sign-Off

**Product:** Olasentra Event Staff ERP  
**Version:** **1.0** (`1.0.0`)  
**Build:** `2026062800`  
**Release label:** `v1.0-certified`  
**Release date:** **28 June 2026**  
**Production status:** **CERTIFIED FOR LIVE OPERATIONS ✅**

---

## Executive summary

The Olasentra production ERP has completed internal verification, operational stabilization (Priorities 1, 3, 4, 5), and final internal production verification. The system is **operationally stable** and designated as the **official Version 1.0 production baseline**.

All future development is **Version 1.1 or later** — incremental enhancements on this foundation.

---

## Version tag record

| Field | Value |
|-------|-------|
| **Version** | 1.0 (`1.0.0`) |
| **Release date** | 2026-06-28 |
| **Certified build** | `2026062800` |
| **Database schema version** | Canonical identity `1.0.0`; runtime `ensure*Schema()` (no migration queue) |
| **Mobile API version** | `1` (router `/api/mobile/v1/*`) |
| **Mobile API contract** | v1.5 client integration |
| **Min app version** | `1.0.0` |
| **Android app version** | `1.0.15` (portal label; versionCode 1) |
| **Server deploy build** | `2026062600` (`v1.0-stable`) |
| **Migration level** | PHP schema helpers at deploy; see `includes/*-schema.php` |

Machine-readable manifest: `storage/baseline/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json`

---

## Production freeze (protected modules)

The following modules **must not be redesigned** without prior approval and documented rollback:

| Module | Primary paths |
|--------|----------------|
| Master Staff Identity | `includes/platform/canonical-identity.php`, `admin/staff-identity-manager.php` |
| Attendance Engine | `includes/attendance-repository.php`, GPS/mobile attendance |
| Payroll Engine | Work-hours, Sheets payroll sync, `apply/admin` payroll |
| Commission Engine | `includes/commission-invoice-repository.php` |
| Recruitment Core | `includes/automation/recruitment-repository.php`, recruitment centre |
| Google Sheets Synchronization | `includes/google-sheets-sync.php`, apply vault bridge |
| Mobile Authentication | `includes/mobile/`, `api/mobile/` |
| Database Relationships | FK integrity; dual-write staff ↔ registrations |

See also: `docs/PRODUCTION-FREEZE-V1.0.md`, `docs/PROTECTED-MODULES.md`

---

## Certification evidence

| Check | Status | Date |
|-------|--------|------|
| Database Integrity | ✅ PASS | 2026-06-28 |
| Master Staff Identity | ✅ PASS | 2026-06-28 |
| Recruitment | ✅ PASS | 2026-06-28 |
| Attendance | ✅ PASS | 2026-06-28 |
| Payroll | ✅ PASS | 2026-06-28 |
| Commission | ✅ PASS | 2026-06-28 |
| Google Sheets | ✅ PASS | 2026-06-28 |
| Mobile Authentication | ✅ PASS | 2026-06-28 |
| E2E Production Verification | ✅ PASS | 2026-06-27 |
| Internal Production Verification | ✅ PASS | 2026-06-28 |

Probes: `cron/final-production-integrity-audit.php`, `cron/final-internal-verification-report.php`, `cron/canonical-identity-e2e-verify.php`

---

## Remaining items (operational — not software defects)

These are **normal operations**, not v1.1 feature work:

1. Staff completing missing PSA documents  
2. Historical zero-hour attendance review (Kodaleone / past events)  
3. `profile_completed` flag synchronization where onboarding data is complete  
4. Historical data housekeeping  

---

## Baseline backup

**Label:** `OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE`

| Component | Location |
|-----------|----------|
| Database | `storage/backups/baseline/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE/database.sql` |
| Settings + CMS + Sheets keys | `settings-and-cms.json` in same folder |
| Uploaded site files | `site-files.zip` in same folder |
| Codebase snapshot (local) | `storage/backups/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE-*.zip` |
| Cron configuration | cPanel job list + `reminder_cron_key` (documented in settings export) |

**Create / refresh:**

```powershell
# Local codebase baseline
powershell -ExecutionPolicy Bypass -File .\scripts\create-v1-production-baseline-backup.ps1

# Production server baseline (cron key required)
# https://admin.olasentra.com/cron/record-production-v1-baseline.php?key=CRON_KEY
```

---

## Future development rule (v1.1+)

Every change must:

1. Build on the Version 1.0 foundation  
2. Avoid modifying protected modules unless a **confirmed defect** exists  
3. Include regression testing (identity, affected workflows)  
4. Include rollback procedures  
5. Be classified via `docs/templates/CHANGE_REQUEST.md`  

| Type | Version |
|------|---------|
| Bug fix on certified core | v1.0.x |
| Backward-compatible feature | v1.1.x |
| Breaking core change | v2.0 (explicit approval) |

See: `docs/V1.1-DEVELOPMENT-BASELINE.md`

---

## Official certification statement

```
OLASENTRA ERP VERSION 1.0

Production Status: CERTIFIED FOR LIVE OPERATIONS ✅

Certified: 2026-06-28
Build: 2026062800
Baseline: OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE
```

Future work is incremental enhancement to this certified production baseline.
