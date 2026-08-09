# Live E2E Test Results — Account-Only Registration

**Date:** 30 June 2026  
**Environment:** Production (`register.olasentra.com`)  
**Method:** Executed HTTP workflows (curl + PowerShell), not code inspection alone.

---

## Urgent fix: Complete registration button

### Root cause (confirmed)

Two issues blocked successful registration:

| # | Layer | Root cause | Symptom |
|---|-------|------------|---------|
| 1 | **Frontend** | `app.js` `validateForm()` ran on every wizard submit and required `#email` via `REGISTRATION_FIELDS`, while Google/OTP verification stores email in hidden fields. Wizard step 8 passed; `app.js` failed silently (errors on hidden steps). | Click does nothing / no POST |
| 2 | **Frontend** | Wizard submit listener could `preventDefault()`; `app.js` still set `submitting=1` and disabled the button without checking `e.defaultPrevented`. | Button stuck / no POST |
| 3 | **Backend** | `validateFinancialStaffFields()` always required PSA even for **steward** (`staff_role=steward`). | POST sent → redirect `?error=1` with `psa_licence` error |

### Fixes deployed

- `assets/js/app.js` — skip `validateForm()` in wizard mode; sync effective email; respect `defaultPrevented`
- `assets/js/registration-wizard.js` — show alert when wizard validation blocks submit
- `assets/js/registration-wizard-validation.js` — fast-track uses effective email
- `includes/financial-field-validation.php` — steward PSA exemption in financial validation
- `includes/validation.php` — pass `$requiresPsa` into financial validation

### Submit flow (after fix)

1. Click `#reg-wizard-submit` (`type=submit`, `form=registration-form`)
2. `registration-wizard.js` → `validateStep(8)` → if invalid: alert + review errors
3. `app.js` → wizard mode: sync email, skip duplicate validation → native `POST submit.php`
4. `submit.php` → `saveProfileOnlyRegistration()` → **302** → `staff-app.php?registered=profile`

---

## Test 1 — New Steward (LIVE EXECUTED)

| Step | Result | Evidence |
|------|--------|----------|
| GET `index.php?form=steward` | **PASS** HTTP 200 | `data-registration-account-only="1"` |
| POST `submit.php` (no PSA fields) | **PASS** HTTP 302 | `Location: staff-app.php?registered=profile` |
| Steward PSA not required | **PASS** | Before fix: `psa_licence` error; after fix: success |
| Redirect target | **PASS** | `staff-app.php?registered=profile` |
| Success page loads | **PASS** HTTP 200 | Sign-in / registration complete markers |
| HTTP 500 | **PASS** | None |

**Test account created:** `olasentra.e2e.steward.20260630010641@example.com`  
**Cookie/session log:** `docs/e2e3-headers.txt`

**Not executed in this run (requires browser):** JavaScript console, DevTools Network tab for wizard UI click.

---

## Test 2 — New Static / DSP (LIVE EXECUTED partial)

| Step | Result | Evidence |
|------|--------|----------|
| POST static without PSA | **PASS** blocked correctly | HTTP 302 → `index.php?error=1&form=static` |
| Server errors | **PASS** | `psa_licence`, `psa_expiry_date`, `psa_front_image`, `psa_back_image` required |
| POST static with PSA text only | **NOT RUN** | Requires multipart file upload (PSA images) |
| Full static success E2E | **PENDING** | Run in browser with PSA photos |

**Test email (validation-only):** `olasentra.e2e.static.20260630010703@example.com` (not created — validation rejected)

---

## Test 3 — Returning user

| Step | Result |
|------|--------|
| Full returning-user wizard + submit | **NOT RUN** — needs existing production account credentials |
| Code/deploy check | `registration-wizard-returning.js` deployed; returning flow unchanged this session |

**Action for sign-off:** Re-run with a known existing staff email in browser.

---

## Test 4 — Staff App (post-registration)

| Step | Result | Evidence |
|------|--------|----------|
| `staff-app.php?registered=profile` | **PASS** HTTP 200 | Loaded after steward registration |
| Logged-in dashboard / shifts | **NOT RUN** | Requires Google/OTP sign-in |
| No-shifts message (in codebase + deploy) | **PASS** (deployed) | `staff-app-v3-pages.php` in manifest |

---

## Test 5 — Database verification

| Check | Result |
|-------|--------|
| `staff` row created for steward E2E email | **NOT VERIFIED** — no production DB access from this environment |
| No `staff_registrations` row for profile-only | **NOT VERIFIED** — requires SQL on production |

**Recommended SQL (admin run):**

```sql
SELECT id, email, staff_role, created_at FROM staff WHERE email = 'olasentra.e2e.steward.20260630010641@example.com';
SELECT COUNT(*) FROM staff_registrations sr INNER JOIN staff s ON LOWER(sr.email) = LOWER(s.email) WHERE s.email = 'olasentra.e2e.steward.20260630010641@example.com';
```

Expected: 1 staff row, 0 registration rows.

---

## Test 6–7 — Browser console / network

| Item | Result |
|------|--------|
| Console errors on Review → Submit | **NOT RUN** — no headless browser in CI environment |
| Network POST on button click | **SIMULATED** via curl POST (equivalent server path) |

**Please re-test in Chrome:** Review step → Complete registration → confirm POST `submit.php` → 302 → `staff-app.php?registered=profile`.

---

## Test 8 — Server logs

| Item | Result |
|------|--------|
| PHP error log review | **NOT AVAILABLE** — logs not exposed to this agent |
| Response body PHP fatals | **PASS** — no fatal text in probed responses |

---

## Test 9 — HTTP 500 audit (LIVE EXECUTED)

**311 URLs probed** — `docs/audit-full-site-errors-2026-06-30-submit-fix.csv`

| Metric | Count |
|--------|------:|
| HTTP 500 | **0** |
| PHP error in body | **0** |
| HTTP 403 (dev probes) | 2 |
| False blank API | 1 (`api/events.php` JSON `[]`) |

---

## Remaining issues

| Severity | Issue | Status |
|----------|-------|--------|
| **Critical** | Complete registration button (wizard + email validation) | **FIXED** — deployed |
| **Critical** | Steward blocked by PSA in `validateFinancialStaffFields` | **FIXED** — deployed |
| **High** | Full static/DSP E2E with PSA image upload | **PENDING** — browser test |
| **High** | Returning user E2E | **PENDING** — needs existing account |
| **High** | Logged-in staff app shifts apply | **PENDING** — needs sign-in |
| **Medium** | Production DB row verification | **PENDING** — admin SQL |
| **Low** | Browser console/network capture | **PENDING** — manual DevTools |

---

## Scripts for repeat execution

```powershell
# HTTP 500 audit
powershell -ExecutionPolicy Bypass -File .\scripts\audit-full-site-errors.ps1

# Live steward submit (creates real staff row)
powershell -ExecutionPolicy Bypass -File .\scripts\live-e2e-registration-submit.ps1 -Role steward
```

---

## Sign-off status

**NOT READY** for full production sign-off until:

1. You confirm **Complete registration** works in browser after hard refresh (Ctrl+F5).
2. Static/DSP registration with PSA photos completes in browser.
3. Returning user flow tested in browser.
4. Admin confirms DB: staff row only, no `staff_registrations` for profile-only accounts.
5. Optional: delete E2E test staff rows (`olasentra.e2e.*@example.com`).

**Ready** for re-test of steward account-only path — server-side submit **proven** with live POST.
