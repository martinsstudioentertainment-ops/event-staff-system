# Live Browser E2E Sign-Off Report — 30 June 2026

**Environment:** Production (`https://register.olasentra.com`, `https://admin.olasentra.com`)  
**Method:** Real Chromium browser automation (Playwright), not source inspection  
**Test runner:** `scripts/e2e-browser/registration-production.mjs`  
**Artifacts:** `docs/browser-e2e-2026-06-30/`

---

## Executive summary

| Area | Result |
|------|--------|
| Complete Registration button (POST → submit.php → redirect) | **PASS** |
| New Steward registration (browser) | **PASS** |
| Static/DSP PSA validation block (browser) | **PASS** |
| Returning user (profile-only staff lookup) | **PASS** (after fix deployed) |
| Staff App post-registration guest page | **PASS** |
| Database verification (profile-only) | **PASS** |
| Admin pages (unauthenticated, no HTTP 500) | **PASS** |
| Authenticated Staff App dashboard / shift apply | **Not automated** (requires Google/OTP) |
| Authenticated Admin UI smoke | **Not automated** (requires admin session) |

**Overall:** Core registration workflows verified end-to-end in a real browser. **23/23 automated checks passed.**

---

## 1. Complete Registration button (highest priority)

### Evidence

| Check | Result |
|-------|--------|
| Click fires submit (`#reg-wizard-submit`) | PASS |
| POST sent to `submit.php` | PASS |
| HTTP response | **302** |
| `Location` header | `https://register.olasentra.com/staff-app.php?registered=profile` |
| Final browser URL | `staff-app.php?registered=profile` |
| JavaScript errors | None (only PWA SW warnings when SW blocked in test) |
| PHP fatal on submit path | None observed |

### Network log (`steward-network.json`)

```json
[
  { "phase": "request", "method": "POST", "url": "https://register.olasentra.com/submit.php" },
  {
    "phase": "response",
    "status": 302,
    "url": "https://register.olasentra.com/submit.php",
    "location": "https://register.olasentra.com/staff-app.php?registered=profile"
  }
]
```

### Root causes fixed earlier (deployed)

1. **`app.js`** — wizard submit no longer runs full-form `validateForm()` that failed on hidden email fields.
2. **`registration-wizard.js` / validation** — steward PSA exempt server- and client-side.
3. **`registrant-lookup.php`** — profile-only staff rows now discoverable for returning users.
4. **`registration-returning-profile.php`** — synced to production (removed call to undefined `staffRoleRequiresPsa()`).

---

## 2. New Steward registration

| Step | Result |
|------|--------|
| Open `index.php?form=steward` | PASS |
| PSA fields not required on review step | PASS |
| Complete Registration | PASS |
| Redirect to Staff App | PASS |
| Account created | PASS (`staff` id 182+) |

**Test account:** `browser.steward.20260630010112@olasentra-e2e.test`

---

## 3. Static / DSP registration

| Step | Result |
|------|--------|
| PSA step shown (step 7) | PASS |
| Continue without PSA data blocked | PASS — licence, expiry, front/back photo errors |
| Full browser submit with PSA photos | **Not run** (file upload in headless CI omitted; server path validated separately via curl in prior audit) |

---

## 4. Returning user

| Step | Result |
|------|--------|
| Lookup finds profile-only `staff` row | PASS |
| Welcome back panel displayed | PASS |
| No redirect to shift step (step 2) | PASS |
| Stays on email step when profile flagged incomplete | PASS (steward shows “PSA Missing” in onboarding summary — PSA not required for stewards at registration but still counted in onboarding %) |

**Note:** Messaging still says “pick shifts” in one line of the returning panel copy; account-only mode skips shift step correctly.

---

## 5. Staff App

| Step | Result |
|------|--------|
| Post-registration redirect loads | PASS |
| “Registration complete. Sign in below…” notice | PASS |
| Guest sign-in UI present | PASS |
| Dashboard with shifts / apply | **Manual** — requires Google or email OTP sign-in |

---

## 6. Database verification

Probe: `cron/probe-profile-only-registration.php`

```json
{
  "staff_count": 1,
  "staff_registrations": 0,
  "profile_only_ok": true,
  "checks": {
    "exactly_one_staff_row": true,
    "no_staff_registrations": true,
    "no_duplicate_staff": true
  }
}
```

---

## 7. Admin (unauthenticated HTTP probe)

All return **302** to login (expected) — **no HTTP 500**:

- Dashboard, Events, Staff List, Staff Inbox, Staff Thread, Messaging, Settings

Authenticated admin UI was **not** exercised (no credentials in automated runner).

---

## 8. Browser & server logs

### Browser (Playwright)

- No `error` or `pageerror` console events during steward submit.
- PWA service worker warnings only when SW intentionally blocked for test stability.

### Server

- No PHP fatals on tested paths.
- `registrant-lookup.php` previously threw `Call to undefined function staffRoleRequiresPsa()` — **fixed and deployed**.

---

## Known residual risks

### PWA service worker reload during registration

When the service worker updates, `pwa.js` calls `window.location.reload()` on `controllerchange`. This **was observed** resetting the wizard to step 1 and clearing the email field mid-flow.

**Mitigation for tests:** Playwright uses `serviceWorkers: 'block'`.  
**Production risk:** A user on a slow connection could lose wizard progress during an SW update. Consider suppressing reload when `data-wizard-mode="1"` and step > 1.

### Steward returning-user “PSA Missing” status

`getStaffOnboardingRequiredFields()` always includes PSA fields. Stewards who registered successfully are still shown “PSA Missing” on return. Functional impact is cosmetic (no erroneous shift step); copy could be improved.

### Authenticated flows

Sign-off for **logged-in** Staff App (shifts list, apply, no-shifts message) and **logged-in** Admin still requires manual device/session testing with real Google/OTP/admin credentials.

---

## How to re-run

```powershell
cd scripts\e2e-browser
npm install
npx playwright install chromium
npm run test:production
```

Output directory: `docs/browser-e2e-YYYY-MM-DD/`

---

## Sign-off position

**Registration core path is production-ready** for:

- Account-only steward registration via real browser
- Complete Registration POST → DB → redirect
- PSA enforcement for static/DSP at wizard step 7
- Profile-only DB integrity (one `staff` row, zero `staff_registrations`)
- Returning profile-only staff detection (post-fix)

**Recommend manual confirmation** before final business sign-off:

1. Google/OTP sign-in → Staff App dashboard and shift apply (or no-shifts message)
2. Admin session smoke (dashboard, inbox thread, messaging)
3. Static/DSP full registration with PSA photo upload on a physical device

---

*Generated after live Playwright run: 2026-06-30T01:01 UTC — 23 checks, 0 failures.*
