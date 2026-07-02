# Phase 4 — Live Hours Counter Implementation (Option B)

**Date:** 2026-06-21  
**Status:** Complete — **not deployed** (awaiting review and approval)  
**Scope:** Display layer only — no attendance persistence, schema, or API contract changes

---

## Objective

Correct live-hours display and dashboard statistics without changing attendance calculations or persistence. Active shifts show elapsed wall-clock time; completed shifts show stored hours; monthly totals exclude in-progress projected hours.

---

## Files modified

| File | Changes |
|------|---------|
| `includes/staff-app-v3-data.php` | Display helpers, `getStaffV3TodayShift()` overnight handling, `getStaffV3MonthlyStats()` completed-only sums, rewritten `getStaffV3ShiftTimeProgress()` |
| `includes/staff-app-v3-pages.php` | Pass `$pdo` to progress helper; monthly stat labels; history uses `formatStaffV3HistoryHoursLabel()` |
| `includes/staff-app-v3-shell.php` | Pass `$pdo` to progress helper; show progress % when `state === 'live'` |
| `scripts/phase4-live-hours-display-test.php` | Local regression script (new, not deployed) |

### Files explicitly not modified

- `includes/attendance-repository.php`
- `includes/work-hours-repository.php`
- `includes/staff-venue-checkin.php`
- `includes/attendance-gps-signout.php`
- Database schema / migrations
- Mobile API route handlers (contracts unchanged; `MobileDashboardService` inherits updated monthly stat query via shared helper)

---

## Before / after behavior

### 1. Active shift display

| | Before | After |
|---|--------|-------|
| Progress bar | Often **100%** immediately after check-in because `hours_worked > 0` branch ran first (projected full-shift value from `initializeWorkHoursForRegistration()`) | **`Active · X / Y hrs`** with wall-clock elapsed vs scheduled duration |
| Label | e.g. `6.0 hrs worked` while still checked in | e.g. `Active · 2.0 / 6.0 hrs` |
| Percent | 100% | `(now − shift_start) / (shift_end − shift_start)` capped at 100 |

### 2. Progress bar logic

| State | Before | After |
|-------|--------|-------|
| Active | Used `hours_worked` / `hours_paid` when > 0 | Uses **scheduled window only** via `staffV3ComputeLiveShiftProgress()` |
| Completed | Used `hours_worked` | Unchanged — still uses stored `hours_worked` after checkout |
| Upcoming | Wall-clock within window | Unchanged pattern; uses operational today + overnight window |

### 3. Monthly dashboard statistics

| Metric | Before | After |
|--------|--------|-------|
| Worked / Paid hrs | `SUM(hours_worked)` / `SUM(hours_paid)` for all check-ins this month | Same sums **only where `checked_out_at` is set** |
| Check-ins count | All attendance rows | Unchanged |
| UI labels | "Worked" / "Paid hrs" | **"Worked (completed)"** / **"Paid hrs (completed)"** |

**Side effect (intended):** Mobile dashboard `monthly` payload from `/api/mobile/...` uses the same helper — totals now exclude active projected hours without API field changes.

### 4. Overnight shifts

| | Before | After |
|---|--------|-------|
| Today's shift card | Only matched `event_date === today` | Also surfaces **yesterday's event** if shift window still running and staff is actively checked in |
| Window math | Partial overnight handling in progress | Centralized in `staffV3ResolveShiftScheduledWindow()` (+1 day when end ≤ start) |

### 5. Check-in history

| | Before | After |
|---|--------|-------|
| In-progress row | Showed projected `hours_worked` (e.g. `6.0 hrs`) | Shows **"In progress"** |
| Completed row | Showed `hours_worked` | Unchanged |

---

## Implementation details

### New display helpers (`staff-app-v3-data.php`)

- `staffV3AttendanceHasCompletedCheckout()` — checkout timestamp present and valid
- `staffV3ShiftIsActiveForDisplay()` — approved, venue check-in, no checkout, in allowed attendance status
- `staffV3ResolveShiftScheduledWindow()` — `event_date` + start/end with overnight end adjustment
- `staffV3ComputeLiveShiftProgress()` — wall-clock percent and `Active · elapsed / scheduled` label
- `formatStaffV3HistoryHoursLabel()` — "In progress" vs completed hours

### `getStaffV3ShiftTimeProgress()` decision order

1. **Active shift** → live wall-clock progress (ignores `hours_worked`)
2. **Completed** (checkout or window after + real check-in) → stored `hours_worked`
3. **Today / overnight window without check-in** → upcoming/live wall-clock within schedule
4. **Otherwise** → scheduled hours label or empty

---

## Regression test results

### Automated (local)

```text
php scripts/phase4-live-hours-display-test.php
```

| Case | Result |
|------|--------|
| Active shift with projected `hours_worked = 6.0` | `state=live`, pct < 100, label contains `Active` |
| Completed shift | `state=done`, label `5.5 hrs worked` |
| History active | `In progress` |
| History completed | `5.5 hrs` |
| Overnight window 22:00–06:00 | End datetime on next calendar day |

**Result:** ALL ASSERTIONS PASSED

### PHP syntax

- `staff-app-v3-data.php` — OK
- `staff-app-v3-pages.php` — OK
- `staff-app-v3-shell.php` — OK

### Manual verification required on device (post-deploy)

- [ ] Staff PWA home — active shift shows elapsed time, not full projected hours
- [ ] Staff PWA — completed shift card shows stored hours
- [ ] Overnight shift still visible on home after midnight while active
- [ ] Monthly "Worked (completed)" excludes current active shift projected hours
- [ ] Admin Work Hours — unchanged (no files touched)
- [ ] Commission invoice rebuild — unchanged (separate Phase invoice fix)
- [ ] Historical attendance records in admin — unchanged

---

## Risk assessment

| Risk | Level | Mitigation |
|------|-------|------------|
| Active shift misclassified as completed | Low | `staffV3ShiftIsActiveForDisplay()` requires no checkout + venue check-in + status allow-list |
| Monthly totals drop for staff mid-shift | **Expected** | By design — only completed shifts count; label clarifies "(completed)" |
| Overnight "today" card shows yesterday event | Low | Only when window still running and check-in active |
| Mobile API consumers confused by lower monthly totals | Low | Same JSON keys; values reflect completed work only (more accurate operationally) |
| Attendance / payroll persistence altered | **None** | No repository or sign-out code changed |
| Invoice / admin calculations altered | **None** | Out of scope; separate code paths |

---

## Deployment recommendation

**Do not deploy until this report is reviewed and approved.**

When approved:

1. Run deploy safety gate: `powershell -ExecutionPolicy Bypass -File .\scripts\deploy-safety-gate.ps1`
2. Deploy: `powershell -ExecutionPolicy Bypass -File .\deploy.ps1`
3. Device-test on register.olasentra.com (Staff App v3):
   - Check in to a shift → confirm progress bar shows `Active · X / Y hrs`, not 100%
   - Sign out → confirm completed hours and monthly stat increment
   - If overnight event available, confirm cross-midnight home card

**Rollback:** Revert the three `includes/staff-app-v3-*.php` files from git; no database rollback needed.

---

## Success criteria checklist

- [x] Active shifts show elapsed time (wall-clock vs schedule)
- [x] Completed shifts show completed hours from DB
- [x] Monthly statistics exclude projected active hours (SQL filter on checkout)
- [x] Overnight shift display aligned (today shift + window resolution)
- [x] No attendance calculation changes
- [x] No attendance record changes
- [x] No database changes
- [x] No mobile API contract changes (field names unchanged)
- [ ] Production device verification (pending deploy approval)
