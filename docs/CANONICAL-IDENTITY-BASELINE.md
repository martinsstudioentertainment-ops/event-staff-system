# Master Staff Identity (Production Baseline)

**Former internal name:** Canonical Identity  
**Status:** Production baseline  
**Deployment date:** 2026-06-27  
**Master Staff Identity version:** 1.0.0  
**Google Sheets sync version:** 1.0.0  

## Administrator dashboard

**Staff Identity Manager:** `/admin/staff-identity-manager.php`

Tracks identity summary, staff profiles, alias emails, duplicate prevention, nightly audits, and Staff Identity Audit history.

## Mandatory write paths

All staff and registration identity changes must use one of:

| Path | Module |
|------|--------|
| `saveRegistration()` | Public registration, apply site, API |
| `findOrCreateStaff()` | Staff onboarding (via saveRegistration) |
| `canonicalIdentityEnforceOnRegistration()` | Admin approval, staff allocation |
| `changeStaffEmail()` | Admin staff email change |

Direct `INSERT`/`UPDATE` on identity fields outside these paths is logged in the Staff Identity Audit bypass log.

## Terminology

| Technical (internal) | Administrator label |
|----------------------|----------------------|
| Canonical Identity | Master Staff Identity |
| Identity resolution | Staff Identity Protection |
| Canonical email | Primary email |
| Canonical Identity Audit | Staff Identity Audit |

## Regression protection

```powershell
php scripts/canonical-identity-regression-test.php
```

Runs automatically via `scripts/deploy-safety-gate.ps1`.

## Scheduled jobs

**Nightly audit:**
```
/cron/canonical-identity-nightly.php?key=CRON_KEY&apply=1
```

**Verification (after deploy):**
```
/cron/canonical-identity-e2e-verify.php?key=CRON_KEY&record_baseline=1
```

Success message: `MASTER STAFF IDENTITY PROTECTION ACTIVE ✅`

## Reference (internal code paths)

- Core module: `includes/platform/canonical-identity.php`
- Admin UI: `includes/platform/master-staff-identity-ui.php`
- Nightly cron: `cron/canonical-identity-nightly.php`
