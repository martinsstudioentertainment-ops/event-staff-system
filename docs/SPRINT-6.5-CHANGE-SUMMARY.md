# Sprint 6.5 — Platform Upgrade Change Summary

**Status:** Local/staging ready — **not deployed to production** (awaiting your approval).  
**Verified:** 2026-06-07 — all four verification scripts passed locally.

| Script | Result |
|--------|--------|
| `php scripts/admin-page-audit.php` | 123 admin PHP files, 0 errors |
| `php scripts/admin-production-smoke.php` | 105 URLs OK |
| `php scripts/apply-production-smoke.php` | 19 URLs OK |
| `php scripts/platform-health-report.php` | Score 100% (21 pass) |

---

## PART 1 — Staff PWA (mobile)

| Change | Files |
|--------|-------|
| Header shows **staff name** (`Hi, First Last`), email only in tooltip | `includes/public/staff-public-shell.php`, `includes/staff-portal-dashboard.php` |
| Name fallback from latest registration when staff record has no name | `getStaffPortalDisplayName()` |
| Check-in page: no Google Maps embed/link at bottom | `check-in.php` (removed `renderVenueMapBlock`) |
| Check-in header shows registrant name when signed in via token | `check-in.php` |
| Bottom nav decluttered: removed duplicate **Status** tab (same URL as Check-in) | `staff-app.php` |
| Profile moved to top header icon (bell + profile) | `staff-app.php` |
| Friendlier signed-in pill on non-V2 pages (visible on mobile) | `assets/css/public-front.css` |
| Offline PWA sync disabled/hidden (M8) | `staff-app.php`, `sw.js` |

---

## PART 2 — Sprint 6 modules

### M1 — Auto approval engine
- Sidebar link gated by `feature_auto_approval`
- **Evaluate pending queue** batch action (shadow-safe)
- Registrant success message shows **Approved** when live auto-approve runs
- Files: `admin/auto-approval.php`, `includes/platform/auto-approval.php`, `includes/platform/sidebar-ops.php`

### M2 — Command center
- Upcoming events, coverage gaps, understaffed events rendered
- **Smart suggestions** section (from former AI Ops)
- **Last updated** timestamp + 60s auto-refresh on event days only
- Leaner toolbar (no duplicate dashboard links)
- Files: `admin/command-center.php`, `includes/platform/command-center.php`

### M3 — Unified inbox
- Pagination beyond 100 items
- GPS alerts: Archive / Restore (`gps_attendance` type)
- **All sheets sync paths** log to `platform_sheets_sync_log` via `logSheetsSyncEvent()`
- Files: `admin/unified-inbox.php`, `includes/platform/unified-inbox.php`, `includes/platform/sheets-sync-log.php`, `includes/google-sheets-sync.php`

### M4 — Trust scores (display only)
- Small tier badge on `admin/view-staff.php` when flag enabled
- Recompute hooks: check-in, approval, blacklist — **no scoring logic changes**
- Files: `admin/view-staff.php`, `includes/platform/trust-scores.php`, attendance/approval/blacklist hooks

### M5 — Event hub
- Coverage gap, hours alerts, sheet sync status columns
- Staff list: check-in status + GPS OK/Issue column
- Files: `admin/event-hub.php`, `includes/platform/event-hub.php`

### M6 — Hours & attendance reconciliation
- Renamed from “Payroll Intelligence” everywhere in UI
- Misleading alert labels removed (`duplicate_payments`, `unpaid_staff`)
- Nightly scan cron: `cron/hours-reconciliation-scan.php`
- Deep links to registration + work hours editor
- Sidebar label: **Hours reconciliation** (lower in Operations section)
- Files: `admin/payroll-intelligence.php`, `includes/platform/payroll-intelligence.php`, sidebar

### M7 — Google Sheets control
- `googleSheetsLogRebuildTabOutcome()` on all rebuild paths
- **Re-sync this event** button in linked events table
- Retry queue metric fix
- Files: `admin/google-sheets-control.php`, `includes/google-sheets-sync.php`

### M8 — Staff PWA v2
- Offline features marked disabled; dead offline JS not loaded on staff app
- Focus: check-in + push notifications

### M9 — Smart suggestions (was AI Ops)
- `admin/ai-ops.php` redirects to command center
- Sidebar AI Ops item removed; content in Command Center **Smart suggestions**
- Files: `admin/ai-ops.php`, `includes/platform/ai-ops.php`

### M10 — Backup center
- `admin/backups.php` → redirect to `backup-center.php`
- Download buttons: database, settings, full site zip
- Admin-only access enforced
- Files: `admin/backup-center.php`, `admin/backups.php`

---

## PART 3 — Admin navigation

- Operations modules behind feature flags (6 OFF by default)
- **Always visible:** Hours reconciliation, Sheets control, Backup center
- Messages / notifications moved to top header bar
- Files: `includes/admin-capabilities.php`, `includes/admin/header-bar.php`, `includes/admin/sidebar.php`, `includes/platform/sidebar-ops.php`

---

## New files

- `includes/platform/sheets-sync-log.php`
- `cron/hours-reconciliation-scan.php`
- `docs/SPRINT-6.5-CHANGE-SUMMARY.md` (this file)

---

## Deploy checklist (after your approval)

1. Review on staging / local Laragon
2. Run verification scripts again post-deploy
3. `powershell -ExecutionPolicy Bypass -File .\deploy.ps1`
4. Schedule cron: `cron/hours-reconciliation-scan.php` (nightly)
5. Enable feature flags individually in **Feature flags** admin when ready

---

## Not changed (by design)

- GPS attendance core logic
- Registration wizard flow
- Apply portal import/sync core
- Authentication & permission model
- Trust score calculation formulas
- Auto-approval rules (shadow mode preserved)
