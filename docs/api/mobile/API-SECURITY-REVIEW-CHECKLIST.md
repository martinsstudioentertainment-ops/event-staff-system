# API Security Review Checklist — Olasentra Staff Mobile API

**Version:** 1.0  
**Review cadence:** Before each sprint merge, before staging promotion, before production enable  
**Owner:** Backend lead + security reviewer

---

## How to use

Complete all **Blocker** items before enabling `mobile_api_enabled = 1` in production.  
**Warning** items should be resolved or documented with accepted risk.

Record review in admin audit log or sprint notes with date and reviewer initials.

---

## 1. Authentication & session

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| A1 | JWT secret strength | Blocker | ≥ 256-bit random; stored in `system_settings`; not in git |
| A2 | Access token TTL | Blocker | ≤ 15 minutes (default 900s) |
| A3 | Refresh token storage | Blocker | Only SHA-256 hash stored; plaintext never logged |
| A4 | Refresh rotation | Blocker | Old refresh revoked on rotation |
| A5 | Google ID token verification | Blocker | Verified server-side via Google; `aud` matches client ID |
| A6 | Email verified | Blocker | Google `email_verified` required |
| A7 | No staff creation on login | Blocker | 403 if email not in `staff` / not eligible |
| A8 | Blacklist enforcement | Blocker | `staff.is_blacklisted` and `staff_blacklist` checked |
| A9 | Device limit | Warning | Max 5 active refresh tokens per staff |
| A10 | Logout revokes tokens | Blocker | Refresh + FCM deactivated on logout |
| A11 | PPS auth rate limit | Blocker | 10/min/IP, 5/min/email |
| A12 | Web session unchanged | Blocker | PHP session login still works independently |

---

## 2. Authorization & data access

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| B1 | Resource ownership | Blocker | Every endpoint validates `staff_id` owns registration/resources |
| B2 | No IDOR on shifts | Blocker | Cannot read another staff member's `registration_id` |
| B3 | No IDOR on documents | Blocker | File stream validates staff owns document |
| B4 | No IDOR on notifications | Blocker | `staff_email` must match JWT |
| B5 | Profile PATCH whitelist | Blocker | PSA/PPS/IBAN blocked for complete profiles |
| B6 | No admin escalation | Blocker | Mobile JWT cannot access `/admin/*` |
| B7 | Cross-staff message block | Blocker | Messages scoped to authenticated email only |

---

## 3. Input validation

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| C1 | JSON body size limit | Warning | ≤ 1 MB request body |
| C2 | GPS coordinate bounds | Blocker | lat -90..90, lng -180..180 |
| C3 | Message body length | Blocker | ≤ 4000 chars |
| C4 | SQL injection | Blocker | All queries parameterized (PDO prepared statements) |
| C5 | Path traversal on documents | Blocker | Resolved path under allowed storage root |
| C6 | Date format validation | Blocker | ISO dates validated before DB write |

---

## 4. Transport & infrastructure

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| D1 | HTTPS only | Blocker | HTTP redirects to HTTPS on production |
| D2 | TLS version | Blocker | TLS 1.2+ on production host |
| D3 | Security headers | Warning | `X-Content-Type-Options: nosniff` on JSON responses |
| D4 | CORS | Info | Not required for native; if added, no wildcard with credentials |
| D5 | Certificate pinning | Info | Android client responsibility (Phase 2) |
| D6 | `includes/` blocked | Blocker | `.htaccess` denies direct access |

---

## 5. Sensitive data handling

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| E1 | PPS not in GET /me | Blocker | Masked or omitted |
| E2 | IBAN not in GET /me | Blocker | Omitted from mobile profile response |
| E3 | No PII in logs | Blocker | Auth failures log email hash only in production |
| E4 | PSA images authenticated | Blocker | Not served via public URL without Bearer token |
| E5 | JWT not in audit log | Blocker | Audit stores endpoint + staff_id only |
| E6 | Error messages | Warning | No stack traces in production JSON |

---

## 6. Rate limiting & abuse

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| F1 | Auth endpoints throttled | Blocker | Per policy |
| F2 | GPS ping throttled | Blocker | 120/hour/staff |
| F3 | Global API kill switch | Blocker | `mobile_api_enabled` returns 503 |
| F4 | Brute force PPS | Blocker | Lockout or throttle after repeated failures |

---

## 7. Business logic integrity

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| G1 | No duplicate staff | Blocker | Login never INSERT INTO staff |
| G2 | No duplicate attendance | Blocker | Check-in idempotent via existing `recordCheckin()` |
| G3 | Admin source of truth | Blocker | Mobile cannot create events/approvals |
| G4 | Repository reuse | Blocker | Check-in uses `processStaffAppVenueCheckin()` not duplicate logic |
| G5 | Web GPS unchanged | Blocker | `api/staff-shift-gps.php` regression passed |
| G6 | Web Push unchanged | Blocker | `push_subscriptions` not modified by FCM register |

---

## 8. Firebase & push (Sprint 5+)

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| H1 | FCM service account | Blocker | Not in git; path in settings only |
| H2 | FCM token storage | Blocker | Linked to `staff_id`; deactivated on logout |
| H3 | Push payload | Warning | No PII in notification title/body beyond shift name |
| H4 | Dual push coexistence | Blocker | Web Push still works for PWA users |

---

## 9. Deployment & rollback

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| I1 | Migration tested | Blocker | `migrate-phase69-mobile-api.sql` on staging |
| I2 | Rollback script exists | Blocker | `rollback-phase69-mobile-api.sql` reviewed |
| I3 | Feature flag default off | Blocker | `mobile_api_enabled = 0` on first deploy |
| I4 | config.php not overwritten | Blocker | Deploy excludes production config |
| I5 | Regression checklist | Blocker | Web staff login + check-in verified |

---

## 10. Documentation

| # | Check | Severity | Pass criteria |
|---|-------|----------|---------------|
| J1 | OpenAPI current | Warning | Matches implemented endpoints |
| J2 | Postman collection | Warning | Imports and runs against staging |
| J3 | Ownership matrix | Info | Updated for new endpoints |

---

## Sign-off template

```
Sprint: ___
Reviewer: ___
Date: ___
Environment: staging | production
Blockers open: ___
Approved for mobile_api_enabled=1: YES / NO
Notes:
```

---

## References

- [API-VERSIONING-POLICY.md](./API-VERSIONING-POLICY.md)
- [FIREBASE-ARCHITECTURE.md](./FIREBASE-ARCHITECTURE.md)
- [STAGING-DEPLOYMENT-PLAN.md](./STAGING-DEPLOYMENT-PLAN.md)
