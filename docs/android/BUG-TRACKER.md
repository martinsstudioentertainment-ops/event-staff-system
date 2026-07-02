# Olasentra Staff v1.0.0 — Bug Tracker

**Program:** Phase 3A Internal Testing  
**Last updated:** 2026-06-12

---

## Summary

| Severity | Open | Fixed | Won't fix |
|----------|------|-------|-----------|
| P0 | 0 | 0 | 0 |
| P1 | 3 | 0 | 0 |
| P2 | 0 | 0 | 0 |
| P3 | 0 | 0 | 0 |

*Infrastructure blockers below are pre-device-test findings from Phase 3/3A audit — not application logic defects.*

---

## Open bugs

### BUG-001 — Placeholder Firebase configuration

| Field | Value |
|-------|-------|
| **ID** | BUG-001 |
| **Severity** | P1 |
| **Status** | Open |
| **Area** | Push / FCM |
| **Found** | 2026-06-12 (Phase 3A audit) |
| **Device** | N/A — config |

**Description:** `app/google-services.json` contains placeholder values (`YOUR_FIREBASE_API_KEY`, project_number `000000000000`). Push notification receive/register cannot be validated until replaced with production Firebase project file.

**Steps to reproduce:**
1. Install app with current `google-services.json`
2. Sign in and wait for FCM token registration
3. Send test push from admin/Firebase

**Expected:** Push received in notification tray  
**Actual:** FCM non-functional with placeholder config (predicted)

**Workaround:** Copy real `google-services.json` from Firebase Console before push test sessions.

---

### BUG-002 — Missing local.properties (Google OAuth)

| Field | Value |
|-------|-------|
| **ID** | BUG-002 |
| **Severity** | P1 |
| **Status** | Open |
| **Area** | Auth |
| **Found** | 2026-06-12 (Phase 3A audit) |
| **Device** | N/A — build env |

**Description:** `local.properties` not present in build workspace. `GOOGLE_WEB_CLIENT_ID` empty → Google Sign-In fails at runtime unless configured per developer machine.

**Steps to reproduce:**
1. Build without `local.properties`
2. Launch app → Sign in with Google

**Expected:** OAuth flow completes  
**Actual:** Sign-in error (empty client ID)

**Workaround:** Copy `local.properties.example` → `local.properties` with Web Client ID from admin Settings.

---

### BUG-003 — Release build pipeline not verified

| Field | Value |
|-------|-------|
| **ID** | BUG-003 |
| **Severity** | P1 |
| **Status** | Open |
| **Area** | Build / Release |
| **Found** | 2026-06-12 (Phase 3A audit) |
| **Device** | N/A — CI |

**Description:** No `gradlew.bat`, no `keystore.properties`, release APK/AAB not produced or smoke-tested. ProGuard/R8 release path unverified on device.

**Steps to reproduce:**
1. Run `build-release.ps1` on audit machine
2. Observe missing wrapper / keystore

**Expected:** Signed APK + AAB artifacts  
**Actual:** Build not executed

**Workaround:** Use `assembleProductionDebug` for initial internal tests; complete release build before closed testing track.

---

## Device test bugs (fill during internal testing)

| ID | Severity | Area | Device | Summary | Status | Assigned |
|----|----------|------|--------|---------|--------|----------|
| BUG-100 | | | | | Open | |
| BUG-101 | | | | | Open | |
| BUG-102 | | | | | Open | |

---

## Fixed bugs

| ID | Severity | Summary | Fixed in | Verified |
|----|----------|---------|----------|----------|
| — | — | *None yet* | — | — |

---

## Bug report template (copy for new entries)

```markdown
### BUG-XXX — [Short title]

| Field | Value |
|-------|-------|
| **ID** | BUG-XXX |
| **Severity** | P0 / P1 / P2 / P3 |
| **Status** | Open / Fixed / Won't fix |
| **Area** | Auth / GPS / Push / … |
| **Found** | YYYY-MM-DD |
| **Device** | Model, Android XX |
| **Tester** | Name |

**Description:** …

**Steps to reproduce:**
1. …

**Expected:** …  
**Actual:** …

**Screenshots / logs:** …

**Workaround:** …
```

---

## Triage rules

1. **P0** — Stop testing; fix before continuing program  
2. **P1** — Must fix or accept risk before closed testing  
3. **P2** — Fix before Play Store if time permits  
4. **P3** — Backlog

**Closed testing gate:** Zero open P0; zero open P1 unless explicitly accepted by product owner with documented workaround.
