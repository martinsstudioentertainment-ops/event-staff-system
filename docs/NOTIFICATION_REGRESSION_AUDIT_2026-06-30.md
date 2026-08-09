# Notification Regression Audit — 30 June 2026

## Executive summary

**Root cause confirmed:** Account-only (profile-only) registration in `submit.php` **never called** `runRegistrationPostSaveSafely()`, which is where registration notifications, admin alerts, Google Sheets sync, and auto-approval run for shift-based registrations.

**Impact:** Every new staff account created via the current registration portal received **no welcome in-app notification**, **no admin in-app alert**, and **no welcome email** (when `notify_on_registration` is enabled). Shift alert notifications still fired but linked staff to the **registration form** instead of the **staff app**.

**Service worker / PWA:** `sw.js` push, click, and badge handlers were **not modified** in the registration work. `pwa.js` was changed only to defer `location.reload()` during the wizard — **does not affect** push notifications or `api/notifications.php`.

**Fix deployed:** Minimal post-save hook for profile-only new accounts + shift alert URLs corrected. No notification architecture rewrite.

---

## Pipeline architecture (unchanged)

```
Event → PHP notifier → app_notifications table → API/UI → badge count
```

| Layer | Component |
|-------|-----------|
| Storage | `app_notifications` (`audience`, `staff_email`, `type`, `title`, `body`, `is_read`, `created_at`) |
| Writers | `notifyStaffInApp()`, `notifyAdminInApp()` in `includes/notification-center.php` |
| Staff read API | `api/notifications.php?audience=staff` (session or status token) |
| Admin read API | `api/notifications.php?audience=admin` |
| Mark read | `api/notifications-mark-read.php` |
| Staff UI | `staff-notifications.php`, badge via `assets/js/notifications.js` (90s poll) |
| Admin UI | `admin/notifications.php`, sidebar badge (120s poll) |
| Mobile | `MobileNotificationService.php` → same `app_notifications` table |

---

## Regression #1 — Profile-only registration skips post-save (CRITICAL)

### Before registration changes

`submit.php` → `saveRegistrations()` → `registrationFlushResponse()` → **`runRegistrationPostSaveSafely()`** which calls:

1. `notifyStaffRegistrationSubmitted()` — welcome email (if setting on)
2. `notifyAdminNewRegistration()` — admin in-app + email per registration row
3. Google Sheets sync, auto-approval

### After profile-only change

`submit.php` line 74: `$profileOnlyMode = true` (always).

Profile path calls `saveProfileOnlyRegistration()` then redirects — **exits without post-save**.

### Evidence

E2E steward `browser.steward.20260630010112@olasentra-e2e.test`:

- `staff` row exists (id 184)
- `staff_registrations` = 0 (by design)
- **Zero** `app_notifications` rows for that email (pre-fix)

### Fix (minimal)

| File | Change |
|------|--------|
| `includes/registration-post-save.php` | `runProfileOnlyPostSaveSafely()` for **new** accounts only |
| `includes/notifications.php` | `notifyStaffProfileAccountCreated()`, `notifyStaffProfileOnlyWelcomeEmail()` |
| `includes/notification-center.php` | `notifyAdminNewStaffAccount()` |
| `submit.php` | Call post-save after `registrationFlushResponse()` |

---

## Regression #2 — Shift alerts link to registration form (MEDIUM)

### Issue

`includes/event-staff-alerts.php` used `getRegistrationFormUrl()` for `new_event` / `open_shift` in-app actions ("Register now" → `index.php`).

Profile-only staff cannot apply via registration form; they apply in **staff app**.

### Fix

Use `getStaffAppUrl()` and label **"Open staff app"** for shift alerts.

| File | Change |
|------|--------|
| `includes/site-urls.php` | `getStaffAppUrl()` helper |
| `includes/event-staff-alerts.php` | Action URL + copy updated |

---

## Notification type audit

### Registration

| Type | Trigger | Function | Table | Status pre-fix | Status post-fix |
|------|---------|----------|-------|----------------|-----------------|
| Welcome in-app | New account | `notifyStaffProfileAccountCreated` | `app_notifications` | **MISSING** | **RESTORED** |
| Welcome email | New account | `notifyStaffProfileOnlyWelcomeEmail` | SMTP | **MISSING** | **RESTORED** (if `notify_on_registration=1`) |
| Registration email (shift) | `saveRegistrations` post-save | `notifyStaffRegistrationSubmitted` | SMTP | N/A (no shift reg) | Unchanged |
| Registration pending in-app | Status change | `notifyStaffStatusInApp` | `app_notifications` | OK when shift reg exists | Unchanged |
| Registration approved in-app | Admin approve | `notifyStaffStatusInApp` | `app_notifications` | OK | Unchanged |
| Admin new registration | Post-save per reg row | `notifyAdminNewRegistration` | `app_notifications` | **MISSING** for profile-only | **RESTORED** via `notifyAdminNewStaffAccount` |

### Staff / shifts

| Type | Trigger | Function | Status |
|------|---------|----------|--------|
| New shift available | Event save / activation | `notifyRegisteredStaffNewEvent` | OK (URL fixed) |
| Open shift slot | Rejection / capacity | `notifyRegisteredStaffOpenShiftSlot` | OK (URL fixed) |
| Shift assigned | Admin allocation | No dedicated in-app type; approval uses `status_approved` | Unchanged |
| Shift cancelled | Event cancel | `notifyStaffEventCancelledInApp` | OK |
| Shift reminder | Cron / manual | `sendDailyEventReminder`, `sendManualShiftReminderForRegistration` | OK (requires `staff_registrations`) |
| Shift updated | Event edit | No separate notifier; may trigger open-shift broadcast | Unchanged |

### Messaging

| Type | Trigger | Function | Status |
|------|---------|----------|--------|
| Staff → admin | `sendStaffMessageToAdmin` | `notifyAdminInApp` type `staff_message` | OK |
| Admin reply | `recordAdminOutboundStaffMessage` | `notifyStaffInApp` type `admin_reply` | OK |
| Comms hub broadcast | `comms-hub.php` | `notifyStaffInApp` | OK |

### Admin

| Type | Trigger | Function | Status |
|------|---------|----------|--------|
| New staff account | Profile-only post-save | `notifyAdminNewStaffAccount` | **RESTORED** |
| New shift registration | Post-save | `notifyAdminNewRegistration` | OK when shift path used |
| Staff check-in | GPS / manual | `notifyAdminStaffCheckin` | OK |
| Document uploaded | Profile save | **No automatic in-app notifier found** | Pre-existing gap, not regression |
| Profile update | Staff edit | **No automatic admin notifier** | Pre-existing gap |

### Mobile

| Feature | Path | Status |
|---------|------|--------|
| In-app list | `MobileNotificationService::mobileNotificationServiceList` | OK — reads `app_notifications` |
| Unread count | Same + `countUnreadStaffNotifications` | OK |
| Push (FCM) | `MobilePushService` + `fcm_device_tokens` | Not changed by registration work |
| `profile_created` category | `MobileNotificationMapper` | Mapped to `system_announcement` |

### Email

| Type | Function | Profile-only pre-fix | Post-fix |
|------|----------|---------------------|----------|
| Welcome / registration | `notifyStaffRegistrationSubmitted` / `notifyStaffProfileOnlyWelcomeEmail` | Missing | Restored (profile path) |
| Verification OTP | `registration-email-otp` API | Unchanged | OK |
| Shift reminder | `reminders.php` | Unchanged | OK |
| Status change | `notifyStaffStatusChanges` | Unchanged | OK |

---

## Service worker audit

| Feature | File | Registration change impact |
|---------|------|---------------------------|
| Push handler | `sw.js` `push` event | **None** — not modified |
| Notification click | `sw.js` `notificationclick` | **None** |
| Background sync | Disabled (comment in sw.js) | **None** |
| API cache | Only static `.css/.js` — **not** `api/notifications.php` | **None** |
| Controller reload | `pwa.js` | Deferred during wizard only; does not block push or badge polling |

---

## Frontend audit

| Component | Behaviour | Regression? |
|-----------|-----------|-------------|
| Bell + badge | `data-notif-badge`, poll 90s | No — API works when notifications exist |
| Badge hidden when 0 | `staff-app-v3-shell.php` | No |
| Mark read | `notifications-mark-read.php` | No |
| Chronological order | `ORDER BY created_at DESC` | No |
| Session vs token | Logged-in staff use portal session; token optional | No |

**Note:** Profile-only staff may have **empty** `status_token` (no `staff_registrations` row). Badge polling still works via **portal session** when signed in.

---

## Database audit

| Check | Result |
|-------|--------|
| Table | `app_notifications` (schema unchanged) |
| Unread count | `COUNT(*) WHERE is_read = 0` per audience/email |
| staff_id mapping | `related_id` stores registration_id or staff_id depending on type |
| Profile-only staff | In `staff` table; eligible for shift alerts via UNION in `listRegisteredStaffEmailsForAlerts` |
| Queue | No separate queue table — direct INSERT |

Probe endpoint: `cron/probe-notifications-pipeline.php?key=...&email=...`

---

## Performance

| Metric | Typical |
|--------|---------|
| INSERT notification | < 5 ms |
| `api/notifications.php` | < 100 ms |
| Badge poll interval | 90s staff / 120s admin |
| Post-save runs after `fastcgi_finish_request` | No user-facing latency |

---

## Files changed (fix)

1. `submit.php`
2. `includes/registration-post-save.php`
3. `includes/notifications.php`
4. `includes/notification-center.php`
5. `includes/event-staff-alerts.php`
6. `includes/site-urls.php`
7. `includes/mobile/mappers/MobileNotificationMapper.php`
8. `cron/probe-notifications-pipeline.php` (audit probe)

---

## Verification steps

1. Register new steward on production → confirm `app_notifications` row type `profile_created` for staff email.
2. Confirm admin inbox shows type `staff_account` (if `notify_admin_on_registration` enabled).
3. Publish/activate event with open slots → staff receive `new_event` with action URL `staff-app.php`.
4. `GET /api/notifications.php?audience=staff` while signed in → unread count matches list.

---

## Conclusion

The notification system architecture was **sound**; the regression was a **missing post-save hook** when registration was switched to profile-only. Shift alert deep links were a **secondary UX regression**. Service worker and frontend polling were **not** the cause.

**Original behaviour is restored** for new account notifications while preserving profile-only registration.
