# Endpoint Ownership Matrix — Olasentra Staff Mobile API

**Version:** 1.0 · Phase 69  
**Last updated:** June 2026

Legend: **Owner** = accountable team · **Implementer** = ships code · **Reviewer** = security/QA sign-off

---

## Summary

| Domain | Endpoints | Owner | Sprint |
|--------|-----------|-------|--------|
| Auth & config | 5 | Platform Backend | 1 |
| Profile | 2 | Platform Backend | 2 |
| Dashboard | 1 | Platform Backend | 2 |
| Shifts | 4 | Platform Backend | 3 |
| Check-in & GPS | 4 | Platform Backend | 4 |
| Notifications | 3 | Platform Backend | 5 |
| Messages | 2 | Platform Backend | 5 |
| Documents | 2 | Platform Backend | 6 |
| Availability | 3 | Platform Backend | 6 |
| Push (FCM) | 2 | Platform Backend | 5 |
| Offline sync | 1 | Platform Backend | 6 |

---

## Full matrix

| # | Method | Route | Controller | Service | Repository / existing PHP | Owner | Sprint | Reviewer |
|---|--------|-------|------------|---------|---------------------------|-------|--------|----------|
| 1 | GET | `/config` | ConfigController | MobileConfigService | settings-repository, staff-google-oauth, attendance-gps-phase1 | Platform Backend | 1 | QA |
| 2 | POST | `/auth/google` | AuthController | MobileGoogleAuthService | staff-google-oauth, staff-repository, MobileTokenRepository | Platform Backend | 1 | Security |
| 3 | POST | `/auth/pps` | AuthController | MobileAuthService | staff-portal-session, MobileTokenRepository | Platform Backend | 1 | Security |
| 4 | POST | `/auth/refresh` | AuthController | MobileAuthService | MobileTokenRepository, mobile-auth | Platform Backend | 1 | Security |
| 5 | POST | `/auth/logout` | AuthController | MobileAuthService | MobileTokenRepository, MobileFcmRepository | Platform Backend | 1 | Security |
| 6 | GET | `/me` | ProfileController | MobileProfileService | staff-repository, staff-onboarding, staff-profile-gate | Platform Backend | 2 | QA |
| 7 | PATCH | `/me` | ProfileController | MobileProfileService | staff-repository (updateStaffProfile) | Platform Backend | 2 | Security |
| 8 | GET | `/dashboard` | DashboardController | MobileDashboardService | staff-app-v3-shell, staff-portal-dashboard | Platform Backend | 2 | QA |
| 9 | GET | `/shifts` | ShiftsController | MobileShiftsService | staff-app-v3-data, status-repository | Platform Backend | 3 | QA |
| 10 | GET | `/shifts/{registrationId}` | ShiftsController | MobileShiftsService | staff-app-v3-data, events-repository | Platform Backend | 3 | QA |
| 11 | POST | `/shifts/{registrationId}/respond` | ShiftsController | MobileShiftsService | staff-self-service (ssp_set_shift_response) | Platform Backend | 3 | QA |
| 12 | GET | `/shifts/today` | ShiftsController | MobileShiftsService | staff-venue-checkin, staff-app-v3-data | Platform Backend | 3 | QA |
| 13 | POST | `/checkin` | CheckinController | MobileCheckinService | staff-venue-checkin (processStaffAppVenueCheckin) | Platform Backend | 4 | Security + Ops |
| 14 | POST | `/gps/ping` | GpsController | MobileGpsService | attendance-gps-signout (processGpsAttendancePing) | Platform Backend | 4 | Security + Ops |
| 15 | GET | `/gps/status` | GpsController | MobileGpsService | staff-portal-shift | Platform Backend | 4 | QA |
| 16 | POST | `/checkout` | CheckinController | MobileCheckinService | attendance-gps-signout (autoSignOutAttendance) | Platform Backend | 4 | Security + Ops |
| 17 | GET | `/notifications` | NotificationsController | MobileNotificationsService | notification-center | Platform Backend | 5 | QA |
| 18 | POST | `/notifications/{id}/read` | NotificationsController | MobileNotificationsService | notification-center | Platform Backend | 5 | QA |
| 19 | POST | `/notifications/read-all` | NotificationsController | MobileNotificationsService | notification-center | Platform Backend | 5 | QA |
| 20 | GET | `/messages` | MessagesController | MobileMessagesService | staff-messages | Platform Backend | 5 | QA |
| 21 | POST | `/messages` | MessagesController | MobileMessagesService | staff-messages | Platform Backend | 5 | QA |
| 22 | GET | `/documents` | DocumentsController | MobileDocumentsService | staff-portal (portal_staff_documents), contracts-repository | Platform Backend | 6 | Security |
| 23 | GET | `/documents/{key}/file` | DocumentsController | MobileDocumentsService | staff-psa (isStoredPsaImagePath) | Platform Backend | 6 | Security |
| 24 | GET | `/availability` | AvailabilityController | MobileAvailabilityService | staff-portal, staff-availability | Platform Backend | 6 | QA |
| 25 | PUT | `/availability/{date}` | AvailabilityController | MobileAvailabilityService | staff-self-service (ssp_confirm_availability) | Platform Backend | 6 | QA |
| 26 | POST | `/leave` | AvailabilityController | MobileAvailabilityService | staff-self-service (ssp_request_leave) | Platform Backend | 6 | QA |
| 27 | POST | `/push/register` | PushController | MobilePushService | MobileFcmRepository | Platform Backend | 5 | Security |
| 28 | DELETE | `/push/register` | PushController | MobilePushService | MobileFcmRepository | Platform Backend | 5 | Security |
| 29 | POST | `/sync/offline` | SyncController | MobileSyncService | staff-offline-sync, platform-schema | Platform Backend | 6 | QA |

---

## Cross-cutting components

| Component | Owner | File(s) |
|-----------|-------|---------|
| Router | Platform Backend | `includes/mobile/mobile-router.php` |
| JWT auth | Platform Backend | `includes/mobile/mobile-auth.php` |
| Auth middleware | Platform Backend | `includes/mobile/middleware/MobileAuthMiddleware.php` |
| Rate limiting | Platform Backend | `includes/mobile/mobile-rate-limit.php` |
| Audit logging | Platform Backend | `includes/mobile/mobile-audit-log.php` |
| DB schema | Platform Backend | `includes/mobile/schema/mobile-api-schema.php` |
| Admin settings UI | Platform Backend | `admin/settings-production.php` |
| FCM dispatcher | Platform Backend | `includes/mobile/services/MobilePushService.php` + `notification-center.php` extension |
| OpenAPI spec | Platform Backend | `docs/api/mobile/openapi.yaml` |
| Postman collection | Platform Backend | `docs/api/mobile/postman/` |

---

## Escalation

| Issue type | Escalate to |
|------------|-------------|
| Auth / data leak | Security reviewer + disable `mobile_api_enabled` |
| GPS / check-in incorrect | Ops + attendance-repository owner |
| Push not delivered | Firebase admin + MobilePushService owner |
| Admin data wrong | Admin ERP team — **not** mobile API (read-only mirror) |

---

## Unowned / frozen (do not modify via mobile)

| Surface | Owner |
|---------|-------|
| `admin.olasentra.com` | Admin ERP |
| `staff-app.php` web session | PWA/TWA |
| `api/staff-shift-gps.php` | PWA (frozen) |
| `apply/admin` vault DB | Apply team |
