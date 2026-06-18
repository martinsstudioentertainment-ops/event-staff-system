# Phase 1 — Staff Preferences Foundation

**Status:** Complete (awaiting approval before Phase 2)  
**Date:** 2026-06-18  
**Project:** Olasentra (`com.olasentra.app`)

## Scope delivered

### Database (additive)

| Table / column | Purpose |
|----------------|---------|
| `staff_preferences` | Per-staff JSON preferences (shift types, locations, roles, days, hours) |
| `staff_certifications` | Certification records (schema ready; upload UI in Phase 4) |
| `preference_locations` | Admin-managed location list |
| `event_interest` | Staff ↔ event interest (schema ready; mobile UI in Phase 3) |
| `events.allocation_mode` | `first_come`, `manager_approval`, `auto_availability` |

**Migration files:**

- `database/migrate-phase71-staff-preferences-foundation.sql`
- `database/migrate-phase71-events-allocation-mode.sql` (applied via PHP if column missing)

**Schema bootstrap:** `includes/workforce/staff-preferences-schema.php` → `ensureStaffPreferencesFoundationSchema()`

### Admin

| Page | URL |
|------|-----|
| Preference locations | `admin/settings-preference-locations.php` |
| Staff preferences | `admin/staff-preferences.php` |
| Export CSV | `admin/staff-preferences-export.php` |

**ERP Settings → Preference locations** — add, edit, disable, sort order.

**Workforce → Staff preferences** — view, filter (shift, location, role, day, certification), export.

### Mobile API v1

| Method | Route | Auth |
|--------|-------|------|
| GET | `/api/mobile/v1/me/preferences` | JWT required |
| PUT | `/api/mobile/v1/me/preferences` | JWT required |
| GET | `/api/mobile/v1/config` | Public — extended with `preference_options` |

**`preference_options` includes:** `shift_types`, `roles`, `availability_hours`, `availability_days`, `locations`, `certification_types`

### Registration (backward compatible)

Optional POST fields on `submit.php` (and waitlist path):

- `preferred_shift_types` (JSON array or JSON string)
- `preferred_locations`
- `preferred_roles`
- `availability_days`
- `availability_hours`
- `staff_preferences_json` (combined object)

If omitted, registration behaves exactly as before.

## Files added

```
database/migrate-phase71-staff-preferences-foundation.sql
database/migrate-phase71-events-allocation-mode.sql
includes/workforce/staff-preferences-schema.php
includes/workforce/preference-catalog.php
includes/workforce/preference-locations.php
includes/workforce/staff-preferences.php
includes/mobile/services/MobilePreferencesService.php
includes/mobile/controllers/PreferencesController.php
admin/settings-preference-locations.php
admin/preference-location-action.php
admin/staff-preferences.php
admin/staff-preferences-export.php
scripts/phase1-preferences-regression.ps1
docs/PHASE-1-STAFF-PREFERENCES-FOUNDATION.md
```

## Files modified

```
includes/mobile/mobile-router.php
includes/mobile/services/MobileConfigService.php
includes/registration-post-save.php
includes/staff-allocation.php
includes/admin/admin-nav.php
includes/admin-capabilities.php
admin/settings-mobile-portal.php
```

## Not changed (per protection rules)

- Payroll logic
- Attendance logic
- Authentication (Google Sign-In, Email OTP)
- Event allocation centre behaviour
- Existing registration validation (preferences optional only)

## Deploy

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy.ps1
```

Tables are created automatically on first request via `ensureStaffPreferencesFoundationSchema()`.

## Regression test

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\phase1-preferences-regression.ps1
```

## Phase 2+ (not started)

- Android registration Shift Preferences UI
- Event interest / waitlist mobile endpoints
- Certification upload
- Auto-allocation engine

**Stop here until Phase 1 is approved on device and admin.**
