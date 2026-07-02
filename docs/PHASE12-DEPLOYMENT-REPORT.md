# Phase 12 — Registration HTTP 500 Fix Deployment Report

**Verdict:** **DEPLOYMENT SUCCESSFUL**

**Deployed at:** 2026-06-21T08:10:13+01:00  
**Target:** `register.olasentra.com` (`public_html` via FTP)  
**Scope:** Restore missing include on `status.php` after successful registration redirect

**Rollback status:** Not required — upload verified by size + SHA-256; production HTTP probes pass.

---

## Root cause

**Phase 10** refactored `status.php` to v3 rendering and removed several legacy includes, including `includes/staff-portal-dashboard.php`.

**Registration save in `submit.php` succeeds**, then redirects (or AJAX `status_url`) to `status.php?token=...` via `getRegistrationStatusUrlAfterSave()`.

When `status.php` loads with registration rows, it calls:

- `computeStaffStatusMetricsFromRows($rows)` — line 122  
- `filterStaffStatusRows($rows, $statusFilter)` — line 123  

Both functions live in `includes/staff-portal-dashboard.php`, which was **no longer included** → **PHP Fatal error: Call to undefined function computeStaffStatusMetricsFromRows()** → **HTTP 500**.

**Production error log evidence** (`error_log`, 2026-06-21 08:05–08:07 Europe/Dublin):

```
PHP Fatal error: Uncaught Error: Call to undefined function computeStaffStatusMetricsFromRows()
in /home/olastofx/public_html/status.php:122
```

**Not the cause:** `submit.php` handler, CSRF, database insert, email send, Phase 11 CSS, or registration validation logic. `submit.php` and `index.php` were unchanged in this fix.

---

## Fix applied

Added one line to `status.php`:

```php
require_once __DIR__ . '/includes/staff-portal-dashboard.php';
```

Restores status metrics helpers used after registration without changing registration workflow, attendance, GPS, OAuth, or OTP logic.

---

## Files modified (1 deployed)

| File | Change |
|------|--------|
| `status.php` | Restore `staff-portal-dashboard.php` include for status metrics functions |

**Support (not deployed):** `scripts/phase12-registration-500-fix-test.php`, `scripts/deploy-phase12-registration-500-fix.ps1`, `scripts/phase12-post-deploy-verify.ps1`, `scripts/phase12-download-prod.ps1`

---

## Regression results

| Suite | Result |
|-------|--------|
| Deploy safety gate | **PASS** |
| Phase 12 registration 500 fix | **11/11 PASS** |
| Phase 11 auth/registration | **26/26 PASS** |
| Phase 5C login parity | **34/34 PASS** |

### Post-deploy HTTP probes (7/7 PASS)

- Registration page loads (v3)
- No PHP fatal on `index.php`
- Status page loads (lookup view)
- No PHP fatal on `status.php`
- Google Login intact
- Email OTP intact

---

## Backup location

```
storage/backups/phase12-pre-deploy-20260621-081013/
├── manifest.json
├── deploy-result.json
├── post-deploy-verify/
│   └── status.php
└── status.php                    (pre-deploy production copy)
```

---

## Hash verification

| File | Pre-deploy SHA-256 | Deployed SHA-256 | Verified |
|------|-------------------|------------------|----------|
| `status.php` | `3b8a315770259ac39cec46bd28fbee16a41b2c7575639d0d107a1e69efcb6f07` | `3113352334fd268457cd5d26f8af01aeb3700de002f8e61c030ef8cdfa284dae` | **MATCH** |

Upload size: 6157 bytes — local, FTP upload, and re-download hash all match.

---

## Rollback plan

1. Restore pre-deploy `status.php` from backup via FTP:

```
storage/backups/phase12-pre-deploy-20260621-081013/status.php
→ status.php
```

2. Pre-deploy hash for verification: `3b8a315770259ac39cec46bd28fbee16a41b2c7575639d0d107a1e69efcb6f07`

3. Re-run `scripts/phase12-post-deploy-verify.ps1`

**Note:** Rollback restores the HTTP 500 on post-registration status page.

---

## Manual verification recommended

Complete one full registration on production (Google gate → form → submit) and confirm redirect to Application Status page without HTTP 500.

---

**Related:** `docs/PHASE10-DEPLOYMENT-REPORT.md` (introduced regression) · `docs/PHASE11-DEPLOYMENT-REPORT.md`
