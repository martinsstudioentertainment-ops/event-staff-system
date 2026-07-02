# Protected Modules — v1.0 Core

Extra review required before modifying any file listed here.  
For v1.1 features, **extend** via `modules/`, `features/`, or `integrations/` instead.

---

## Authentication

| File / symbol | Role |
|---------------|------|
| `includes/staff-google-oauth.php` | `getStaffAuthPolicy()`, `getRegistrationVerifiedEmail()`, OAuth |
| `includes/staff-portal-session.php` | Portal session lookup |
| `includes/staff-portal-email-otp.php` | Staff OTP |
| `includes/mobile/mobile-auth.php` | JWT validation |
| `includes/mobile/services/MobileAuthService.php` | Mobile Google / PPS / OTP |
| `includes/mobile/services/MobileOtpService.php` | Shared OTP logic |
| `api/registration-email-otp-*.php` | Registration OTP |
| `api/staff-portal-otp-*.php` | Portal OTP |
| `api/registration-google-verify.php` | Registration Google |

---

## Registration

| File | Role |
|------|------|
| `index.php` | Wizard entry |
| `submit.php` | Form submission |
| `includes/registration-google-gate.php` | Verification gate |
| `includes/registration-forms.php` | Form definitions |
| `includes/staff-psa.php` | PSA requirements |
| `includes/registration-short-links.php` | Short URL helpers |
| `.htaccess` | `/steward`, `/dsp` rewrites |

---

## Attendance

| File | Role |
|------|------|
| `includes/attendance-repository.php` | `recordCheckin()`, venue history |
| `includes/staff-blacklist.php` | Blacklist rules |
| `includes/checkin-bib.php` | BIB validation |
| `api/attendance-gps-ping.php` | GPS ping |
| `api/staff-shift-gps.php` | Shift GPS |
| `staff-checkin.php` | Staff check-in UI |

---

## Mobile API

| File | Role |
|------|------|
| `api/mobile/index.php` | Router entry |
| `includes/mobile/mobile-router.php` | Route table |
| `includes/mobile/services/MobileConfigService.php` | `/config` payload |
| `includes/app-build-version.php` | Build metadata |

---

## Deployment

| File | Role |
|------|------|
| `deploy.ps1` | Git + FTP deploy |
| `scripts/upload-safe-fix-bundle.ps1` | v1.0 stable bundle |
| `scripts/upload-to-server.ps1` | FTP allowlist |
| `scripts/deploy-ui-api-manifest.json` | UI/API pairing |
| `storage/version.json` | Production build stamp |

---

## Admin settings & configuration

| File | Role |
|------|------|
| `includes/settings-repository.php` | `getSetting()` |
| `includes/feature-flags.php` | Feature toggles |
| `includes/admin/settings-handler.php` | Settings save |
| `config.php` | Bootstrap (never overwrite on server) |

---

## Health dashboard

| File | Role |
|------|------|
| `admin/system-health.php` | Admin UI |
| `includes/admin/system-health.php` | Check implementations |

---

## Permissions

| File | Role |
|------|------|
| `includes/admin/auth.php` | Admin session |
| Admin role checks across `admin/*.php` | Access control |

---

## Payments (if touched)

Review payroll and rental payment flows in `admin/` and related includes before any change.

---

## Master Staff Identity (FROZEN v1.0)

| File | Role |
|------|------|
| `includes/platform/canonical-identity.php` | Identity engine (internal); duplicate prevention |
| `includes/platform/master-staff-identity-ui.php` | Admin presentation layer |
| `admin/staff-identity-manager.php` | Staff Identity Manager dashboard |
| `includes/validation.php` | `saveRegistration()` identity hooks |
| `includes/staff-repository.php` | `findOrCreateStaff()`, `updateStaffStatus()` |
| `cron/canonical-identity-nightly.php` | Nightly Staff Identity Audit |
| `scripts/canonical-identity-regression-test.php` | Pre-deploy regression gate |

See `docs/PRODUCTION-FREEZE-V1.0.md`.

---

## Google Sheets, payroll & commission (FROZEN v1.0)

| File | Role |
|------|------|
| `includes/google-sheets-sync.php` | Main ERP → Sheets |
| `apply/admin/includes/google-sheets-sync.php` | Apply vault payroll + PSA tabs (staff_id grouping) |
| `includes/commission-invoice-repository.php` | Commission invoices |
| `includes/work-hours-repository.php` | Hours / payroll inputs |

---

## Recruitment (FROZEN v1.0)

| File | Role |
|------|------|
| `includes/automation/recruitment-repository.php` | Pipeline sync from registrations |
| `admin/recruitment-centre.php` | Admin recruitment UI |

---

## Modification checklist

Before editing a protected file:

1. Classify change (`docs/templates/CHANGE_REQUEST.md`)
2. Confirm defect or approved feature scope
3. Run regression checklist from `docs/V1.1-DEVELOPMENT-BASELINE.md`
4. Document rollback (restore file list + prior `version.json`)
5. Deploy via bundle script; verify production endpoints
