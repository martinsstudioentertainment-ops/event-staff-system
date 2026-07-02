# Phase 4A — Live Hours Deployment & Validation Report

**Date:** 2026-06-21  
**Deploy time:** 06:13:53 (local)  
**Status:** Deployed successfully — **rollback not required**  
**Scope:** Three approved display-layer files only

---

## 1. Deployment Report

### Pre-deploy

| Step | Result |
|------|--------|
| Deploy safety gate | **PASS** — `deploy_allowed: true` |
| Critical missing files | 0 |
| Zero-byte scan (deploy tree) | 0 |
| Safe excluded paths | 22 |
| Gate report | `docs/phase2-deploy-safety-gate.json` |

### Deployment backup

Production copies saved before upload:

**Location:** `storage/backups/phase4a-pre-deploy-20260621-061333/`

| File | Pre-deploy size (production) | Post-deploy size (production) |
|------|------------------------------|-------------------------------|
| `includes/staff-app-v3-data.php` | 13,942 bytes | 18,920 bytes |
| `includes/staff-app-v3-pages.php` | 29,793 bytes | 29,321 bytes |
| `includes/staff-app-v3-shell.php` | 18,155 bytes | 17,785 bytes |

Manifest: `storage/backups/phase4a-pre-deploy-20260621-061333/manifest.json`  
Deploy result: `storage/backups/phase4a-pre-deploy-20260621-061333/deploy-result.json`

### File hashes

#### Pre-deploy (production, before upload)

| File | SHA256 |
|------|--------|
| `includes/staff-app-v3-data.php` | `44758ac5fa785eb3a3eb482e8eac1f37bd0a16254b08cca43db2bcc44f92429d` |
| `includes/staff-app-v3-pages.php` | `761998990af808943d06728db55e58a85fc23fd9742b3168965820f6353a7ddd` |
| `includes/staff-app-v3-shell.php` | `d985a1708730604cc8bb172cbd77dd9cde4f4ec81b04bcfb2c1c98ffbfa0f8a5` |

#### Deployed (local → production, verified via re-download)

| File | SHA256 |
|------|--------|
| `includes/staff-app-v3-data.php` | `dae1f8547d17ce7f7b668d964d07c8045c492d3bc4b5f8e2ca5f5c97bf0d729f` |
| `includes/staff-app-v3-pages.php` | `f06a8d9b4992b882388a2add5de827d73e4829c7957b65b6f8b070c4c89f0d1a` |
| `includes/staff-app-v3-shell.php` | `ce7c622ea5d72d0c8d9d1247edceeb26ad4e11de9edd1e34fe1149b2f262c82b` |

Post-upload verification: **size match + SHA256 match** for all three files.

### Deploy method

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\deploy-phase4a-live-hours.ps1
```

- FTP target: `admin.olasentra.com` / `public_html` (same tree as `register.olasentra.com`)
- **Only** the three approved files uploaded
- No git push, no apply-site upload, no other files deployed

### Production code confirmation

Re-downloaded files from production contain Phase 4 markers:

- `staffV3ComputeLiveShiftProgress()` in `staff-app-v3-data.php`
- `staffV3ShiftIsActiveForDisplay()` in `staff-app-v3-data.php`
- `Worked (completed)` label in `staff-app-v3-pages.php`
- Live progress % rendering in `staff-app-v3-shell.php`

---

## 2. Device Test Results

### Automated (post-deploy, unauthenticated)

| Test | URL | HTTP | Result |
|------|-----|------|--------|
| Staff App Home | register.olasentra.com/staff-app.php | 200 | No fatal/parse errors; login page loads |
| Staff Shifts | register.olasentra.com/staff-shifts.php | 200 | No fatal/parse errors; login page loads |
| Staff Check-in | register.olasentra.com/staff-checkin.php | 200 | No fatal/parse errors; login page loads |
| Staff Profile Hub | register.olasentra.com/staff-profile-hub.php | 200 | No fatal/parse errors; login page loads |
| Staff Messages | register.olasentra.com/staff-messages.php | 200 | Page loads (8317–4934 bytes) |
| Registration | register.olasentra.com/ | 200 | Page loads |

**Automated result:** No dashboard errors detected on public/unauthenticated routes. No rollback triggers hit.

### Manual device tests (requires staff login on device)

These cannot be completed from the deployment agent (no staff credentials / no device access). **Please verify on a physical device:**

| Test | Expected | Status |
|------|----------|--------|
| Staff Login | Google OAuth / email login works | **Pending — user device** |
| Active Check-In | Check-in succeeds (GPS/BIB unchanged) | **Pending — user device** |
| Active Shift Progress | Label `Active · X / Y hrs`, % from wall-clock (not 100% immediately) | **Pending — user device** |
| Clock Out | Sign-out completes; shows stored hours after checkout | **Pending — user device** |
| Monthly Statistics | "Worked (completed)" excludes in-progress projected hours | **Pending — user device** |
| Overnight Shift Display | Yesterday's active shift visible after midnight if window running | **Pending — user device** |
| Dashboard Cards | Home stats + today's shift card render without error | **Pending — user device** |
| Shift History | In-progress rows show "In progress", not projected hours | **Pending — user device** |

---

## 3. Validation Report

### Validation criteria

| Criterion | Automated | Manual |
|-----------|-----------|--------|
| Active shifts display elapsed time | Code deployed ✓ | Pending device |
| Progress bar uses wall-clock time | Code deployed ✓ | Pending device |
| Monthly stats exclude active projected hours | SQL filter deployed ✓ | Pending device |
| Completed shifts unchanged | Logic preserved ✓ | Pending device |
| Overnight shifts display correctly | Helper deployed ✓ | Pending device |
| No attendance architecture changes | ✓ (files scoped) | N/A |
| No API contract changes | ✓ (same fields) | N/A |
| No database changes | ✓ | N/A |

### Rollback criteria check

| Trigger | Observed | Action |
|---------|----------|--------|
| Dashboard errors | None on probed URLs | No rollback |
| Active shifts disappear | Not testable without login | Monitor on device |
| Monthly stats incorrect | Not testable without login | Monitor on device |
| Overnight shifts fail | Not testable without overnight event | Monitor on device |
| Staff cannot access dashboard | All staff URLs return HTTP 200 | No rollback |

**Validation status:** **Deploy validated (automated). Functional validation pending device sign-off.**

---

## 4. Rollback Status

**Rollback required:** No  
**Rollback performed:** No  
**Rollback ready:** Yes

To rollback, restore pre-deploy production files from backup and re-upload via FTP:

```powershell
$backup = 'storage\backups\phase4a-pre-deploy-20260621-061333'
# Upload backed-up production copies:
#   includes/staff-app-v3-data.php
#   includes/staff-app-v3-pages.php
#   includes/staff-app-v3-shell.php
```

Pre-deploy production SHA256 values are in `manifest.json` for verification after rollback.

---

## 5. Production Screenshots

**Not captured by deployment agent** — staff dashboard requires authenticated Google OAuth session on a physical device. Public login pages load correctly (HTTP 200, no PHP errors) but do not show active-shift progress UI.

**Recommended screenshots from your device** (after login):

1. Home dashboard — monthly cards showing "Worked (completed)" / "Paid hrs (completed)"
2. Today's shift — active progress bar with `Active · X / Y hrs` and % 
3. Same shift after clock-out — `X.X hrs worked`
4. Check-in history — "In progress" vs completed hours

---

## 6. Summary

Phase 4A deployed successfully with safety gate PASS, production backup, hash verification, and scoped FTP upload of three files only. Post-deploy HTTP probes show no PHP fatal errors on staff routes. **Rollback is not required.**

**Next step:** Device test on register.olasentra.com with an active or test shift to confirm live progress and monthly stats behavior. Reply with device results or request rollback if any rollback criteria are met.

---

## Related documents

- Phase 4 implementation: `docs/PHASE4-LIVE-HOURS-COUNTER-IMPLEMENTATION.md`
- Deploy script: `scripts/deploy-phase4a-live-hours.ps1`
- Local regression: `scripts/phase4-live-hours-display-test.php`
