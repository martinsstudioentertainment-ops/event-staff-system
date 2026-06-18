# Olasentra Staff v1.0.0 — Internal Testing Checklist

**Program:** Phase 3A Internal Testing  
**App:** Olasentra Staff (`com.olasentra.staff`)  
**Version:** 1.0.0 (versionCode 1)  
**API:** `https://register.olasentra.com/api/mobile/v1`  
**Build under test:** `productionDebug` or `productionRelease` (record build type below)

---

## Test session setup

| Field | Value |
|-------|-------|
| Tester name | |
| Tester email (staff Google account) | |
| Device model | |
| Android version | |
| Build flavor | production |
| Build type | debug / release |
| APK/AAB path or install method | |
| Test date | |
| Network | Wi‑Fi / 4G / 5G |

### Prerequisites (test lead confirms before sessions)

- [ ] APK installed on device (`assembleProductionDebug` or signed release)
- [ ] `local.properties` → `GOOGLE_WEB_CLIENT_ID` matches admin Settings
- [ ] Real `app/google-services.json` (not placeholder) for push tests
- [ ] Staff account already exists in admin (no new registration via app)
- [ ] Approved shift scheduled for GPS test day (or open event for eligibility)
- [ ] Admin notified of test window (attendance records will be real)

---

## 1. Authentication

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| A1 | Google Sign-In | Tap Sign in with Google → select linked account | Lands on Home dashboard | ☐ | ☐ | |
| A2 | Existing user | Sign in with staff already in admin | No registration prompt; profile loads | ☐ | ☐ | |
| A3 | Privacy / terms links | On login screen, tap Privacy and Terms | Opens register.olasentra.com pages | ☐ | ☐ | |
| A4 | Session persist | Kill app, reopen | Returns to Home without re-login | ☐ | ☐ | |
| A5 | Token refresh | Use app >24h or force 401 (if possible) | Session refreshes or prompts login cleanly | ☐ | ☐ | |
| A6 | Logout | Profile → Sign out | Login screen; re-login works | ☐ | ☐ | |

---

## 2. Dashboard & profile

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| D1 | Dashboard load | Open Home tab | Name, approval status, unread counts, shifts | ☐ | ☐ | |
| D2 | Pull to refresh | Pull down on Home | Data refreshes; last sync updates | ☐ | ☐ | |
| D3 | Unread notifications tap | Tap unread notifications card | Opens Notifications screen | ☐ | ☐ | |
| D4 | Profile load | Open Profile tab | Name, email, phone, approval status | ☐ | ☐ | |
| D5 | Work hub links | Profile → Documents / Availability / Notifications | Each screen opens | ☐ | ☐ | |

---

## 3. Shifts & messages

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| S1 | Shifts list | Shifts tab | Today + list from API | ☐ | ☐ | |
| S2 | Shift detail | Tap a shift | Detail with registration ID, eligibility | ☐ | ☐ | |
| S3 | Offline shifts cache | Load shifts → airplane mode → reopen Shifts | Cached data shown with offline banner | ☐ | ☐ | |
| M1 | Messages inbox | Messages tab | Inbox threads load | ☐ | ☐ | |
| M2 | Messages refresh | Pull to refresh | Updates from server | ☐ | ☐ | |

---

## 4. Notifications & documents

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| N1 | Notifications list | Profile → Notifications | List with unread count | ☐ | ☐ | |
| N2 | Category filter | Tap category chip | Filters list | ☐ | ☐ | |
| N3 | Mark read | Tap unread notification | Marked read; count decreases | ☐ | ☐ | |
| N4 | Mark all read | Tap Mark all read | All marked read | ☐ | ☐ | |
| N5 | Action button | Tap notification action / card | Navigates to correct screen | ☐ | ☐ | |
| DOC1 | Documents list | Profile → Documents | PSA licence + images listed | ☐ | ☐ | |
| DOC2 | Open file | Tap Open file on PSA front/back | Downloads and opens in viewer | ☐ | ☐ | |
| DOC3 | Licence metadata | View PSA licence row | Expiry, approval status shown | ☐ | ☐ | |

---

## 5. Availability & leave

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| AV1 | Calendar load | Profile → Availability | Current month grid loads | ☐ | ☐ | |
| AV2 | Set available | Tap future day → Available | Day updates; server reflects in admin | ☐ | ☐ | |
| AV3 | Set unavailable | Tap future day → Unavailable | Day updates | ☐ | ☐ | |
| AV4 | Set preferred | Tap future day → Preferred | Day updates | ☐ | ☐ | |
| AV5 | Past date blocked | Tap past day | Cannot change; message shown | ☐ | ☐ | |
| LV1 | Leave request | Tap day → Leave | Pending status on calendar + admin | ☐ | ☐ | |
| LV2 | Holiday request | Tap day → Holiday | Pending status on calendar + admin | ☐ | ☐ | |

---

## 6. GPS check-in / check-out (critical path)

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| G1 | Location permission | Open Check-In tab first time | Rationale → system permission dialog | ☐ | ☐ | |
| G2 | GPS coordinates | Grant permission | Lat/lng and accuracy displayed | ☐ | ☐ | |
| G3 | Inside venue | At venue within radius | In zone; Check in enabled when eligible | ☐ | ☐ | |
| G4 | Check-in | Tap Check in | Success; status checked in | ☐ | ☐ | |
| G5 | GPS ping / monitoring | After check-in (if monitoring required) | Foreground notification appears | ☐ | ☐ | |
| G6 | Check-out | Tap Check out when eligible | Success; hours worked shown | ☐ | ☐ | |
| G7 | Outside venue | Away from venue | Out of zone; Check in disabled | ☐ | ☐ | |
| G8 | Deny permission | Deny location | Check in disabled; rationale shown | ☐ | ☐ | |
| G9 | Poor accuracy | Indoors / weak signal | Button disabled if accuracy > max | ☐ | ☐ | |

Record venue test coordinates: Lat ______ Lng ______ Distance ______ m

---

## 7. Offline queue

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| O1 | Offline check-in | Airplane mode → Check in | Queued message; pending count shown | ☐ | ☐ | |
| O2 | Offline check-out | Checked in → airplane → Check out | Queued for sync | ☐ | ☐ | |
| O3 | Offline availability | Airplane → set day status | Queued for sync | ☐ | ☐ | |
| O4 | Sync on reconnect | Restore network; wait or pull refresh | Queue drains; server updated | ☐ | ☐ | |
| O5 | Duplicate prevention | Queue check-in twice same shift | Second blocked or idempotent | ☐ | ☐ | |

---

## 8. Push notifications

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| P1 | POST_NOTIFICATIONS | Android 13+ first launch | Permission prompt on main shell | ☐ | ☐ | |
| P2 | Receive push | Admin sends test notification | Notification in tray | ☐ | ☐ | |
| P3 | Tap notification | Tap push | App opens correct screen | ☐ | ☐ | |
| P4 | Deep link — shifts | Push with shift category | Navigates to Shifts or detail | ☐ | ☐ | |
| P5 | Deep link — check-in | Push with check-in reminder | Navigates to Check-In | ☐ | ☐ | |
| P6 | Logout unregister | Sign out → verify in admin/FCM | Token unregistered | ☐ | ☐ | |

---

## 9. Logout & data isolation

| # | Test | Steps | Expected | Pass | Fail | Notes |
|---|------|-------|----------|------|------|-------|
| L1 | Logout clears session | Sign out | Login screen only | ☐ | ☐ | |
| L2 | No stale data | After logout, second user signs in (if available) | Previous user data not visible | ☐ | ☐ | |
| L3 | Cache cleared | Logout → login → offline first load | Fresh fetch, not prior user cache | ☐ | ☐ | |

---

## Release verification checklist (sign-off gate)

Complete after all critical-path tests pass on at least **two** physical devices.

| # | Gate | Owner | Done |
|---|------|-------|------|
| R1 | All A1–A6 authentication tests pass on 2+ devices | Test lead | ☐ |
| R2 | GPS path G1–G6 pass at real venue | Test lead | ☐ |
| R3 | Offline O1–O4 pass on 1+ device | Test lead | ☐ |
| R4 | Push P2–P5 pass with production Firebase | Test lead | ☐ |
| R5 | No P0/P1 bugs open (see BUG-TRACKER.md) | Test lead | ☐ |
| R6 | Device matrix ≥2 devices tested (see DEVICE-COMPATIBILITY-MATRIX.md) | Test lead | ☐ |
| R7 | Release build smoke test (R8/R8 ProGuard if using release) | Dev lead | ☐ |
| R8 | Tester feedback forms collected (≥3 testers) | PM | ☐ |
| R9 | Admin confirms attendance/availability records correct | Admin | ☐ |
| R10 | GO/NO-GO decision recorded in INTERNAL-TESTING-REPORT.md | Test lead | ☐ |

---

## Severity definitions (for bug reports)

| Severity | Definition |
|----------|------------|
| **P0** | Blocker — cannot sign in, check in, or data loss |
| **P1** | Major — core feature broken; workaround difficult |
| **P2** | Minor — UI issue or edge case with workaround |
| **P3** | Cosmetic / enhancement |

---

## Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Test lead | | | |
| Dev lead | | | |
| Product owner | | | |

**Program status:** ☐ In progress  ☐ Complete — ready for closed testing review
