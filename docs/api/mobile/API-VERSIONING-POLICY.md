# API Versioning Policy — Olasentra Staff Mobile API

**Effective:** June 2026  
**Owner:** Platform Engineering  
**Applies to:** `/api/mobile/v1/*` on `register.olasentra.com`

---

## 1. Purpose

This policy defines how the Olasentra Staff Mobile API is versioned so native Android clients, staging environments, and future iOS clients can evolve without breaking the existing web platform (PWA, TWA, admin ERP).

---

## 2. Versioning model

### 2.1 URL path versioning (primary)

All mobile endpoints include the major version in the URL path:

```
https://register.olasentra.com/api/mobile/v{major}/
```

**Current:** `v1`  
**Future:** `v2` when breaking changes are required.

Path versioning is **mandatory** for all mobile REST endpoints. Query-string or header-only versioning is not used for breaking changes.

### 2.2 API contract version

The `GET /config` response includes:

```json
{ "api_version": "1" }
```

This is informational and mirrors the URL major version.

### 2.3 OpenAPI document version

The OpenAPI spec uses semantic versioning for the **document** (`info.version`), independent of the URL:

| Component | Example | Meaning |
|-----------|---------|---------|
| URL major | `v1` | Breaking-change boundary |
| OpenAPI `info.version` | `1.2.0` | Spec patch/minor updates within v1 |
| `min_app_version` (config) | `1.0.0` | Minimum native app build allowed |

---

## 3. What constitutes a breaking change

A **breaking change** requires a new major URL version (`v2`):

| Change type | Breaking? |
|-------------|-----------|
| Remove endpoint | Yes |
| Rename endpoint path | Yes |
| Remove response field used by clients | Yes |
| Change field type (string → int) | Yes |
| Change authentication scheme | Yes |
| Tighten validation rejecting previously accepted input | Yes |
| Add optional request field | No |
| Add response field | No |
| Add new endpoint | No |
| Deprecate endpoint (with sunset period) | No (during deprecation window) |
| Bug fix aligning with documented behaviour | No |

---

## 4. Non-breaking evolution within v1

Allowed without a new major version:

1. Add new endpoints under `/api/mobile/v1/`
2. Add optional JSON fields to requests and responses
3. Add new error codes (clients must ignore unknown codes)
4. Add new enum values only if clients treat unknown values gracefully
5. Performance improvements and internal refactors

All non-breaking changes must:

- Update `docs/api/mobile/openapi.yaml`
- Update Postman collection
- Pass regression tests for web platform (no changes to existing `/api/*` cookie endpoints)

---

## 5. Parallel version support

When `v2` is introduced:

| Rule | Detail |
|------|--------|
| Overlap period | Minimum **6 months** where v1 and v2 both operate |
| Default | New app releases target latest major version |
| Admin control | `system_settings.mobile_api_enabled` applies to all versions |
| Sunset | v1 receives deprecation headers (see Deprecation Policy) before removal |

Both versions share:

- Same MySQL database
- Same PHP repositories
- Same admin platform as source of truth

---

## 6. Client responsibilities

Native Android clients must:

1. Read `min_app_version` from `GET /config` on startup
2. Send `app_version` query param on config requests
3. Pin to a single major URL version per app release
4. Handle unknown JSON fields gracefully (ignore)
5. Never hardcode production URLs without TLS

---

## 7. Server responsibilities

Backend must:

1. Maintain OpenAPI spec per major version
2. Never change v1 contract in breaking ways — create v2 instead
3. Log requests with version prefix in audit table
4. Document all changes in CHANGELOG (when introduced)

---

## 8. Relationship to existing web APIs

| Surface | Versioning |
|---------|------------|
| `/api/staff-shift-gps.php` etc. | Unversioned — frozen for PWA/TWA |
| `/api/mobile/v1/*` | Versioned — native app only |
| Admin `/admin/*` | Unversioned — internal |

Web APIs are **not** migrated to `/api/mobile/v1/`. They remain unchanged until explicitly deprecated under a separate policy.

---

## 9. Review and approval

| Action | Approver |
|--------|----------|
| Non-breaking v1 change | Backend developer + code review |
| New major version (v2) | Product owner + backend lead |
| Remove major version | Product owner + 6-month deprecation completed |

---

## 10. References

- [API-DEPRECATION-POLICY.md](./API-DEPRECATION-POLICY.md)
- [openapi.yaml](./openapi.yaml)
- Phase 0 / Phase 1 architecture documents
