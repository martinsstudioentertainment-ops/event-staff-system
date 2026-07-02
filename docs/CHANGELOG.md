# Changelog

All notable changes to Olasentra follow [semantic versioning](https://semver.org/) for product releases.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased] — v1.1 development

### Added
- v1.1 development baseline documentation (`docs/V1.1-DEVELOPMENT-BASELINE.md`)
- Extension directory scaffolding (`modules/`, `features/`, `integrations/`, `plugins/`, `services/`)
- Protected modules inventory (`docs/PROTECTED-MODULES.md`)
- Change request template (`docs/templates/CHANGE_REQUEST.md`)
- Development version tracking (`storage/version-dev.json`)

### Changed
- Development policy: all new work extends v1.0 certified baseline without breaking compatibility

---

## [1.0.0] — 2026-06-28 — Olasentra ERP Version 1.0 (Production Sign-Off)

**Build:** `2026062800` · **Label:** `v1.0-certified`  
**Status:** **CERTIFIED FOR LIVE OPERATIONS ✅**

### Certified
- Full internal production verification (incomplete profiles, zero-hour attendance, module health)
- Operational stabilization: admin notification backfill, commission events #8/#10, housekeeping, duplicate-slot analysis
- Production baseline manifest `OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE`
- Official sign-off documentation `docs/OLASENTRA_ERP_V1.0_PRODUCTION_SIGNOFF.md`

### Frozen (protected)
- Master Staff Identity, Attendance, Payroll, Commission, Recruitment Core, Google Sheets, Mobile Auth, DB relationships

### Operational follow-ups (not defects)
- PSA document completion, historical zero-hour review, profile_completed flag sync, housekeeping

---

## [1.0.0] — 2026-06-26 — Olasentra v1.0 Stable (initial release)

**Build:** `2026062600` · **Label:** `v1.0-stable`

### Added
- Zero-regression polish pass (authentication, APIs, logging, probes)
- Steward registration without PSA; short links `/steward`
- Staff PWA v3 shell fixes; notifications session from status token
- Mobile PPS gated by `pps_signin_enabled`
- `PROJECT_HEALTH_REPORT.md`, `RELEASE_CERTIFICATION.md`

### Security
- Registration verified-email POST/session mismatch blocked
- Probe endpoints disabled in production (`guardDevOnlyEndpoint`)
- Generic JSON errors on `push-vapid-public`, `staff-offline-sync`

### Documentation
- Full architecture health report and release certification

---

## Version series guide

| Series | When to use |
|--------|-------------|
| 1.0.x | Hotfixes on production stable baseline |
| 1.1.x | New backward-compatible features |
| 2.0.0 | Breaking changes (new API major, removed routes) |
