# Phase 2E — Test Reports

**Date:** 2026-06-12  

Reports based on implementation review. Execute checklists on a physical device with production login and FCM configured.

---

## 1. Device test report

| # | Test | Code review |
|---|------|-------------|
| 1 | Profile → Documents | Pass |
| 2 | Profile → Availability | Pass |
| 3 | Profile → Notifications | Pass |
| 4 | Dashboard → Notifications | Pass |
| 5 | Document open (PSA image) | Pass (pending device) |
| 6 | Set availability day | Pass (pending device) |
| 7 | Submit leave request | Pass (pending device) |
| 8 | Logout clears cache | Pass |

---

## 2. Push notification test report

| # | Test | Code review |
|---|------|-------------|
| 1 | FCM token registered after login | Pass |
| 2 | Token refresh updates server | Pass |
| 3 | Notification displayed in tray | Pass |
| 4 | Tap opens correct screen | Pass |
| 5 | Unregister on logout | Pass |
| 6 | Category deep link mapping | Pass |

---

## 3. Availability test report

| # | Test | Code review |
|---|------|-------------|
| 1 | Month navigation | Pass |
| 2 | Set available/unavailable/preferred | Pass |
| 3 | Leave/holiday request | Pass |
| 4 | Past date blocked | Pass |
| 5 | Offline queue | Pass |
| 6 | Cached month when offline | Pass |

---

## 4. Leave request test report

| # | Test | Code review |
|---|------|-------------|
| 1 | POST /leave success | Pass |
| 2 | Pending status on calendar | Pass |
| 3 | Conflict with existing leave | Pass (server) |
| 4 | Offline queue | Pass |

---

## 5. Manual checklist

- [ ] Sign in with Google
- [ ] Open Documents — verify PSA licence expiry and image open
- [ ] Open Availability — set a future day to Preferred
- [ ] Submit leave request — verify pending state
- [ ] Receive test FCM push — tap opens app to correct screen
- [ ] Mark notification read and mark all read
- [ ] Airplane mode — set availability — verify queue message
- [ ] Sign out — verify push unregister and cache clear

---

## Verdict

**Implementation complete.** Live device + FCM sign-off required before production rollout.
