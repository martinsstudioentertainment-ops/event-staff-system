# Olasentra Event Staff System — Production Audit Report

**Date:** 2026-06-06  
**Scope:** olasentra.com ecosystem (main, register, admin, apply subdomains)  
**Stack:** Custom PHP 8.x + MySQL (not Laravel)  
**Backup before changes:** `storage/backups/pre-deploy-20260606-132503.zip`

---

## Executive summary

Live production is **operational**: health API reports DB/storage OK; Nick Cave event shows 36 registrations (35 approved). A **critical staff-list bug** (empty “All roles” filtering to DSP only) was fixed earlier today.

This audit applied **safe hardening** without removing business functionality. Several **high-priority items remain** for a follow-up sprint (apply admin CSRF, registrant API rate limits, migration pipeline).

---

## A. Critical issues

| # | Issue | Status |
|---|--------|--------|
| 1 | Staff list “All roles” silently filtered `staff_role=dsp` | **Fixed** (`includes/staff-repository.php`) |
| 2 | Unauthenticated DB diagnostic `database/tmp-venue-check.php` | **Quarantined + blocked** |
| 3 | Dev scripts under `/scripts/` web-accessible | **Blocked + quarantined** |
| 4 | `api/registrant-lookup.php` returns PII (PPS, IBAN) by email only | **Open** — needed for returning registrant UX; add rate limit |
| 5 | `apply/admin/update-details.php` weak upload validation | **Open** — review MIME/filename/CSRF |
| 6 | Apply admin has **no CSRF** on POST forms | **Open** |

---

## B. Bugs fixed (this audit + earlier session)

1. **Role filter bug** — `normalizeStaffRole('')` returned `dsp`; now only filters when role explicitly selected.
2. **Staff pagination** — 50/page default, per-page selector, event view shows all registrations.
3. **Diagnostic exposure** — moved scripts to `storage/quarantine/2026-06-06-audit/`; stubs return 403 in production.
4. **Apache hardening** — deny `database/`, `scripts/`, `vendor/`; HTTPS redirect on production hosts.
5. **Upload directory** — `uploads/.htaccess` blocks script execution.
6. **roster-check API** — detailed diagnostics hidden on production (`APP_ENV=production`).

---

## C. Security issues

### Fixed / mitigated
- Root `.htaccess` blocks `includes/`, `storage/`, `database/`, `scripts/`, `vendor/`
- `config.php` denied via `<FilesMatch>`
- `database/.htaccess` and `scripts/.htaccess` — Require all denied
- Main admin: CSRF on most POST actions, login lockout, capability-based auth
- PDO prepared statements; `EMULATE_PREPARES => false` in production config template

### Remaining (prioritized)
| Priority | Item |
|----------|------|
| HIGH | Add CSRF tokens to apply admin (`apply/admin/admin/*`) |
| HIGH | Harden `apply/admin/update-details.php` uploads (before ownership check) |
| HIGH | Set explicit `APPLY_SSO_SECRET` / cron keys in `sso.local.php` (remove dev fallback) |
| HIGH | Rate-limit `api/registrant-lookup.php` (email enumeration / PII harvest) |
| MEDIUM | Apply admin: use `initSecureSession()` (httponly, secure, SameSite) |
| MEDIUM | Convert GET state changes (`import-roster.php?run`, sync-sheets) to POST+CSRF |
| MEDIUM | `admin/backups-export.php` — POST instead of GET |
| LOW | Remove `import-summer-roster.php` from production after roster imported |
| LOW | Rotate `ROSTER_IMPORT_TOKEN` if still in `config.php` |

---

## D. Performance issues

| Item | Recommendation |
|------|----------------|
| Staff list grouped queries | Fixed JOIN removal in count query; event filter uses direct count |
| Missing composite indexes | Add `(event_id, status)` on `staff_registrations`, `(is_active, event_date)` on `events` |
| `LOWER(email)` in WHERE | Normalize emails at insert; avoids index skip |
| Large `vendor/` tree | Not web-accessible after `.htaccess` fix |
| Runtime `ensure*Schema()` on page load | Consolidate migrations 35–47 into runner |
| pre-deploy zip backups | Rotate old zips in `storage/backups/` periodically |

---

## E. Database issues

### Healthy
- FK `staff_registrations.event_id → events`
- UNIQUE `(email, event_id)` prevents duplicate event signups
- UNIQUE `attendance.registration_id`
- Production: 36 registrations event #1 Nick Cave (35 approved, 1 rejected)

### Recommendations
1. Extend `getDatabaseMigrationFiles()` to include phases **35–47**
2. Add FKs: `staff_messages.staff_id`, `personal_invoice_lines.invoice_id`, `saved_job_records.event_id`
3. Expand `deleteStaffProfileCompletely()` to clean messages, push subscriptions, notifications
4. Backfill NULL `staff_registrations.staff_id` then enforce NOT NULL
5. Periodic reconciliation: `staff` vs latest registration per email

### Diagnostic SQL (read-only)
```sql
SELECT COUNT(*) FROM staff_registrations WHERE staff_id IS NULL;
SELECT event_id, status, COUNT(*) FROM staff_registrations GROUP BY event_id, status;
```

---

## F. Files safe to remove (after review)

**Do not delete from production without backup.** Candidates for quarantine:

| Path | Notes |
|------|-------|
| `storage/backups/pre-deploy-*.zip` (keep latest 3) | Local/deploy artifacts |
| `storage/backups/diag-output.txt` | Empty diagnostic |
| `import-summer-roster.php` | After roster import complete |
| `database/seed-*.php`, `database/tmp-*` | Dev/seed only |

---

## G. Files requiring review

| Path | Reason |
|------|--------|
| `api/registrant-lookup.php` | PII in JSON response |
| `api/roster-check.php` | Public counts (reduced on production) |
| `apply/admin/update-details.php` | Public profile + uploads |
| `apply/admin/config/sheets.local.php` | Tracked in git (tab names only) |
| `cron/daily-cleanup.php` | Web-accessible with key — ensure key set |
| `admin/import-roster.php` | GET-triggered import |

---

## H. Changes made (2026-06-06 audit)

| File | Change |
|------|--------|
| `.htaccess` | Block database/scripts/vendor; enable HTTPS redirect |
| `database/.htaccess` | Deny all web access |
| `scripts/.htaccess` | Deny all web access |
| `uploads/.htaccess` | Block script execution |
| `database/tmp-venue-check.php` | Production 403 stub |
| `scripts/test-pending.php` | Production 403 stub |
| `scripts/diag-event-staff-count.php` | Production 403 stub |
| `storage/quarantine/2026-06-06-audit/*` | Moved diagnostic originals |
| `api/roster-check.php` | Hide verbose diagnostics in production |
| `includes/staff-repository.php` | Role filter fix (earlier) |
| `admin/staff.php` | Pagination + event flat list (earlier) |
| `includes/admin-pagination.php` | 50/page + UI (earlier) |

---

## I. Remaining recommendations

### Immediate (next deploy)
1. Run **Admin → Backups → Full backup** on production (DB + files)
2. Confirm `APP_ENV=production` in server `config.php`
3. Set apply SSO/cron secrets in `apply/admin/config/sso.local.php`
4. Add composite index migration for `(event_id, status)`

### Short term (1–2 weeks)
1. CSRF middleware pattern for apply admin
2. Rate limit registrant lookup (10 req/min/IP)
3. Complete migration runner phases 35–47
4. `apply/admin/uploads/.htaccess` on server

### Hosting health (verified remotely)
| Check | Result |
|-------|--------|
| `admin.olasentra.com/api/health.php` | app/database/storage: ok |
| SSL | HTTPS redirect enabled in `.htaccess` |
| Cron | `cron/daily-cleanup.php?key=` documented in ops checklist |
| Email | Configure via Admin → Settings → Email (SMTP) |
| Backups | Admin → Backups (weekly manual + DB dump) |

### Not applicable
- Laravel routes/controllers/queues — this is custom PHP
- Payment workflows — no payment gateway in this codebase
- `.env` — project uses `config.php` (gitignored)

---

## J. Final verification checklist

| Test | Expected |
|------|----------|
| Admin login | `admin.olasentra.com/login.php` |
| Staff list event filter | 36 rows for Nick Cave, all roles |
| Registration form | `register.olasentra.com` |
| Returning registrant lookup | Form prefill still works |
| Apply admin SSO | Cookie/SSO from main admin |
| Health API | JSON ok |
| Blocked paths | `/database/setup.php` → 403/404 |

---

*Report generated during live production audit. Quarantine restore: copy from `storage/quarantine/2026-06-06-audit/`.*
