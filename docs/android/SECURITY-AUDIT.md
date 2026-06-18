# Phase 3 — Full Application Security Audit

**Date:** 2026-06-12  
**Scope:** Olasentra Staff Android 1.0.0 RC + Mobile API v1.5 client integration

---

## 1. Secrets & credentials

| Check | Result | Evidence |
|-------|--------|----------|
| No hardcoded OAuth client secrets | **Pass** | `GOOGLE_WEB_CLIENT_ID` from `local.properties` → `BuildConfig` |
| No hardcoded Firebase keys in source | **Pass** | `google-services.json` gitignored; `.example` only in repo |
| No hardcoded API keys in Kotlin | **Pass** | Grep audit — no `AIza`, passwords in `.kt` |
| No committed keystores | **Pass** | `.gitignore` excludes `*.jks`, `keystore.properties` |
| No debug API endpoints in production flavor | **Pass** | `production` → `register.olasentra.com/api/mobile/v1` |

---

## 2. Token storage

| Data | Storage | Encryption |
|------|---------|------------|
| JWT access / refresh | `EncryptedSharedPreferences` | AES256-GCM MasterKey |
| Staff ID / email | Same secure prefs | Yes |
| FCM token | DataStore preferences | App-private (not secret-equivalent) |
| Device ID | DataStore | Random 32-char, app-private |

**Verdict:** **Pass** — auth tokens meet Android security best practice.

---

## 3. Network security

| Control | Status |
|---------|--------|
| Cleartext HTTP blocked | `network_security_config.xml` |
| TLS trust anchors | System CAs only |
| Auth on all protected endpoints | `AuthInterceptor` + `TokenAuthenticator` |
| HTTP body logging in release | **Fixed Phase 3** — `Level.NONE` when not debug |
| Timber in release | Disabled (`BuildConfig.DEBUG` gate) |

---

## 4. GPS attendance security

| Control | Implementation |
|---------|----------------|
| Check-in duplicate (client) | `checkin-reg-{id}` + `hasPendingClientId()` |
| Check-out duplicate (client) | `checkout-reg-{id}` |
| Server duplicate | HTTP 409 / `already` → non-fatal |
| Permission enforcement | Fine location required before actions |
| Accuracy gate | Server `max_accuracy_m` + client check |
| Zone gate | Server `in_zone` + client check |
| Location spoof | **Server-side** — client sends coordinates; admin ERP validates policy |
| Session expiry | `TokenAuthenticator` → login screen |
| Background location | Declared but **not requested** (reduces attack/review surface) |

**Verdict:** **Pass** for client responsibilities. Spoof detection is server/admin policy.

---

## 5. Offline sync security

| Action | Client ID pattern | Dedup |
|--------|-------------------|-------|
| checkin | `checkin-reg-{id}` | Yes |
| checkout | `checkout-reg-{id}` | Yes |
| gps_ping | `gps-ping-{uuid}` | Per ping (by design) |
| availability_set | `availability-{date}-{status}` | Yes |
| leave_request | `leave-{date}-{type}` | Yes |

Queue: app-private Room DB. Sync via authenticated `POST /sync/offline`. Cleared on logout (`apiCacheDao.deleteAll()` + session clear).

**Verdict:** **Pass**

---

## 6. Push notification security

| Control | Status |
|---------|--------|
| FCM register requires JWT | `POST /push/register` |
| Unregister on logout | `DELETE /push/register` |
| Deep links | In-app routes only — no WebView |
| Notification permission | **Fixed Phase 3** — runtime request API 33+ |

**Gap:** Production Firebase config must be supplied locally (not in repo).

---

## 7. Manifest & export surface

| Component | exported |
|-----------|----------|
| MainActivity | true (launcher only) |
| FCM service | false |
| GPS foreground service | false |
| FileProvider | false |
| allowBackup | false |

**Verdict:** **Pass**

---

## 8. Dependency audit

**Source:** `gradle/libs.versions.toml`

| Library | Version | Notes |
|---------|---------|-------|
| AGP | 8.7.3 | Current stable |
| Kotlin | 2.0.21 | |
| Compose BOM | 2024.12.01 | |
| Hilt | 2.52 | |
| Retrofit / OkHttp | 2.11.0 / 4.12.0 | |
| Room | 2.6.1 | |
| Firebase BOM | 33.7.0 | |
| Play Services Location | 21.3.0 | |
| WorkManager | 2.9.1 | |

**Recommendation:** Run `./gradlew dependencyUpdates` or GitHub Dependabot before store submission. No known vulnerable versions flagged in static review.

---

## 9. Database consistency (client)

| Store | Purpose | Cleared on logout |
|-------|---------|-------------------|
| `api_cache` | GET response cache | Yes (`deleteAll`) |
| `offline_sync_queue` | Pending writes | Persists until sync* |
| Encrypted prefs | JWT | Yes |

*Offline queue persists across logout by design if user logs back in — consider clearing on logout in future if policy requires.

**Server DB:** Authoritative. Android never creates staff/shifts/approvals.

---

## 10. Overall security verdict

**PASS for internal testing release candidate**, with mandatory pre-store items:

1. Real Firebase configuration
2. Release build smoke test with R8 enabled
3. Play Console data safety declaration

**Rating:** Suitable for **internal testing** with production API and real credentials on controlled devices.
