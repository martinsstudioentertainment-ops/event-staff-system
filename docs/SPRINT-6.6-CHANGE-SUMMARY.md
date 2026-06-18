# Sprint 6.6 — Data Integrity Change Summary

**Deployed:** pending — run `deploy.ps1` after review.

## Safety

- **No automatic deletions**
- **No automatic merges** (preview + ignore only)
- GPS, registration, approval, payroll logic **unchanged**

---

## New files

| File | Purpose |
|------|---------|
| `includes/platform/data-integrity.php` | Audit engine, scores, merge recommendations |
| `includes/platform/data-integrity-schema.php` | Dismissals table |
| `includes/platform/apply-vault-bridge.php` | Main → Apply vault PDO (read-only audits) |
| `apply/admin/includes/import-precheck.php` | Pre-import email/phone/PSA validation |
| `scripts/generate-sprint66-reports.php` | Generates 8 HTML reports in `docs/` |
| `admin/data-integrity.php` | Admin integrity center (superuser) |
| `admin/duplicate-merge.php` | Safe duplicate review UI |
| `admin/duplicate-merge-action.php` | Ignore dismissals only |
| `database/migrate-phase55-data-integrity.sql` | Dismissals table migration |

## Modified files

| File | Change |
|------|--------|
| `apply/admin/includes/staff-import.php` | Human-readable phone/PSA skip messages |
| `apply/admin/admin/import-applicants.php` | Pre-check + list errors |
| `apply/admin/admin/sync-sheets.php` | Import warnings as bullet list |
| `includes/admin-capabilities.php` | Data integrity sidebar link |

---

## Reports (run on server with DB connected)

```powershell
php scripts/generate-sprint66-reports.php
```

Or **Admin → System → Data integrity → Regenerate reports**.

| Report | Task |
|--------|------|
| `DATA-INTEGRITY-AUDIT-REPORT.html` | Task 1 |
| `TEST-DATA-INVENTORY-REPORT.html` | Task 2 |
| `PHONE-DUPLICATE-REPORT.html` | Task 3 |
| `PSA-INTEGRITY-REPORT.html` | Task 4 |
| `IMPORT-STABILIZATION-REPORT.html` | Task 6 |
| `VAULT-HEALTH-REPORT.html` | Task 7 |
| `TRUST-SCORE-DATA-QUALITY-REPORT.html` | Task 8 |
| `PRODUCTION-CLEANUP-PLAN.html` | Task 10 |

---

## Rollback plan

1. Revert FTP/git deploy of listed files
2. `platform_data_integrity_dismissals` table is optional — can remain
3. Import messages revert with `staff-import.php` rollback
4. No data migrations that alter staff/attendance rows

---

## Post-deploy steps

1. Run `php scripts/generate-sprint66-reports.php` on production (or via admin UI)
2. Review reports in `docs/` or admin Data integrity page
3. Use **Duplicate merge review** to ignore false positives
4. Apply **Production cleanup plan** phases only with explicit approval
