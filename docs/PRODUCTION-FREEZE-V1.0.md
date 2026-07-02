# Production Freeze — Core Infrastructure (v1.0)

**Effective:** 2026-06-27  
**Certified:** 2026-06-28 — **Olasentra ERP Version 1.0**  
**Status:** FROZEN & CERTIFIED — stable production foundation  
**Version label:** `v1.0-certified` · Build `2026062800`  
**Sign-off:** `docs/OLASENTRA_ERP_V1.0_PRODUCTION_SIGNOFF.md`

---

## Purpose

Core infrastructure has reached a stable production state. From this point forward it must **not be redesigned or extended** except to fix **confirmed bugs** with a documented rollback plan.

New development should **use** this foundation, not change it.

---

## Frozen systems

| System | Administrator name | Primary paths |
|--------|-------------------|---------------|
| Master Staff Identity | Staff Identity Manager | `includes/platform/canonical-identity.php`, `includes/platform/master-staff-identity-ui.php`, `admin/staff-identity-manager.php` |
| Duplicate prevention | Staff Identity Protection | Same as above; `findOrCreateStaff()`, `saveRegistration()` hooks |
| Recruitment | Recruitment pipeline | `includes/automation/recruitment-repository.php`, `admin/recruitment-centre.php` |
| Staff management | Staff / registrations | `includes/staff-repository.php`, `admin/staff.php`, `admin/view-staff.php` |
| Google Sheets sync | Sheets control | `includes/google-sheets-sync.php`, `apply/admin/includes/google-sheets-sync.php` |
| Payroll | Payroll / hours | `apply/admin/includes/google-sheets-sync.php`, `admin/invoices.php`, work-hours includes |
| Commission | Commission invoices | `includes/commission-invoice-repository.php`, related admin |
| Attendance | Attendance / GPS | `includes/attendance-repository.php`, `admin/attendance.php`, GPS APIs |
| Mobile identity | Mobile auth / profile | `includes/mobile/`, `api/mobile/`, `MobileEmailOtpAuthService.php` |

---

## What is NOT allowed without prior discussion

- Redesigning any frozen system
- New database tables that alter identity, staff, registration, payroll, or sync relationships
- Changing foreign keys, dual-write patterns, or approval workflows
- Modifying duplicate-prevention or Master Staff Identity matching rules
- Replacing Google Sheets grouping (staff_id-based payroll rows)
- Breaking changes to Mobile API v1 auth or config contracts
- “Improvements” that refactor working production logic

---

## What IS allowed

| Type | Example |
|------|---------|
| **Confirmed bug fix** | Attendance roster fatal error; payroll duplicate row |
| **Presentation / copy** | Steward form labels; admin dashboard wording |
| **Business features on top** | Steward/PSA form fields in registration UI; event allocation UI; reports |
| **Isolated extensions** | New code under `modules/`, `features/`, `integrations/` that **calls** existing APIs |
| **Mobile app enhancements** | New screens consuming existing `/api/mobile/v1/*` endpoints |

---

## Development priority (post-freeze)

Work should focus on business features that use the existing foundation:

1. Steward Application improvements  
2. PSA Application improvements  
3. Event allocation  
4. GPS check-in / check-out  
5. Payroll workflow  
6. Commission workflow  
7. Reporting  
8. Mobile app enhancements  

---

## Change process when core might be affected

If a feature **cannot** be built without touching frozen infrastructure:

1. **Stop** — do not implement silently  
2. **Document** — what needs to change, why, and impact on production  
3. **Discuss** — get explicit approval before coding  
4. **Classify** — use `docs/templates/CHANGE_REQUEST.md`  
5. **Regression** — run Master Staff Identity regression + affected workflow tests  
6. **Rollback plan** — required before deploy  

---

## Verification references

| Check | Command / URL |
|-------|----------------|
| Master Staff Identity regression | `php scripts/canonical-identity-regression-test.php` |
| Staff Identity Manager | `/admin/staff-identity-manager.php` |
| Deploy safety gate | `scripts/deploy-safety-gate.ps1` |
| Nightly identity audit | `/cron/canonical-identity-nightly.php?key=…` |
| Baseline doc | `docs/CANONICAL-IDENTITY-BASELINE.md` |
| Protected file list | `docs/PROTECTED-MODULES.md` |
| v1.1 extension guide | `docs/V1.1-EXTENSION-GUIDE.md` |

---

## Versioning

| Label | Meaning |
|-------|---------|
| **v1.0.x** | Bug fixes on frozen core |
| **v1.1.x** | Backward-compatible business features (forms, allocation, reporting, mobile UI) |
| **v2.0** | Core infrastructure changes — requires explicit programme approval |

**The current production foundation is Version 1.0 and must remain stable.**
