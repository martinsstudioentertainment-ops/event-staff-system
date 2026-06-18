# Olasentra Web — Maintenance Mode Operations Guide

**Effective:** 18 June 2026  
**Project:** Olasentra Web Ecosystem (event-staff-system)  
**Status:** Maintenance mode — no new features unless explicitly approved

---

## 1. Purpose

This guide defines how to operate, deploy, back up, and recover the Olasentra web platform after active development closure. It does **not** cover Android app releases or Phase 2 preference features.

---

## 2. Live environments

| Environment | URL | Role |
|-------------|-----|------|
| Public marketing | https://olasentra.com | Homepage, roles, events, FAQ, contact |
| Registration / staff | https://register.olasentra.com | Apply, staff portal, mobile API |
| Admin ERP | https://admin.olasentra.com | Events, staff, attendance, settings |
| Apply portal | https://apply.olasentra.com | Apply admin sync |

**Master control:** admin.olasentra.com — single database, single business logic.

---

## 3. What must never be removed or broken

- Google Sign-In (staff + registration)
- Email OTP sign-in
- GPS / QR attendance
- Staff registration and approval workflow
- Admin dashboard, exports, payroll backend
- Mobile API v1 (`/api/mobile/v1/*`)
- PWA / mobile portal config
- Existing users, shifts, attendance history, messages, documents

See `.cursor/rules/olasentra-master-development-rules.mdc` for the full protection list.

---

## 4. Deployment procedure

### Standard deploy (after any PHP change)

```powershell
cd e:\event-staff-system
powershell -ExecutionPolicy Bypass -File .\deploy.ps1
```

This runs in order:

1. Local pre-deploy backup → `storage/backups/pre-deploy-YYYYMMDD-HHMMSS.zip`
2. Cleanup audit (read-only)
3. `git push origin main`
4. FTP upload → admin.olasentra.com (`public_html`)
5. FTP upload → apply.olasentra.com

### One-time FTP setup

Copy `deploy.local.ps1.example` → `deploy.local.ps1` and set `FtpPassword` from cPanel.

### Never overwrite on FTP

- `config.php` (production DB credentials)
- `storage/google/service-account.json`
- `apply/admin/config/database.php`
- `apply/admin/config/eventstaff-database.php`

### Post-deploy smoke tests

- https://olasentra.com/
- https://register.olasentra.com/api/health.php
- https://register.olasentra.com/staff-app.php
- https://admin.olasentra.com/admin/login.php
- https://register.olasentra.com/api/mobile/v1/config

---

## 5. Backup and recovery

### Automatic (every deploy)

`scripts/pre-deploy-backup.ps1` creates a full source snapshot (excludes vendor, `.git`, secrets, existing backups).

### Server weekly backup (admin)

**Admin → Backup Center → Run backup** — creates/overwrites:

- `storage/backups/weekly/database.sql`
- `storage/backups/weekly/settings-and-cms.json`
- `storage/backups/weekly/site-files.zip`

Run manually after major changes if weekly cron is not confirmed.

### Restore options

| Scenario | Action |
|----------|--------|
| Roll back specific files | Extract `storage/backups/pre-deploy-*.zip`, FTP upload affected paths |
| Restore from snapshot | `scripts/restore-production-snapshot.ps1 -Label <label>` (requires `storage/backups/restore-points/`) |
| Zero-byte corruption | `scripts/restore-zero-byte-files.ps1` + forensic snapshot under `_recovery-staging/` |
| Database only | Restore `database.sql` via phpMyAdmin or admin backup tools |

### Recovery package locations

- `handover-package/HANDOVER-COMPLETE.txt`
- `docs/DISASTER-RECOVERY-REPORT.html`
- `_recovery-staging/forensic-snapshot/`

---

## 6. Git as source of truth

After closure, **GitHub `main` must match production**. Do not deploy from an uncommitted working tree.

Workflow:

1. Change code locally
2. Test on staging or limited FTP test path if available
3. `git add`, `git commit`, `git push origin main`
4. `deploy.ps1`

Verify sync: compare deploy bundle file sizes (see closure audit script pattern in `scripts/upload-to-server.ps1`).

---

## 7. Public website — legacy URLs

| URL | Behaviour | Notes |
|-----|-----------|-------|
| `about.php` | 301 → `how-it-works.php` | Standalone About page out of scope; company story in CMS |
| `services.php` | 301 → `roles.php` | Standalone Services page out of scope; roles list is canonical |

Live navigation uses: Home, Roles, Events, How it works, FAQ, Contact.

---

## 8. Monitoring and health

| Check | Frequency | Method |
|-------|-----------|--------|
| Health API | Weekly | `GET /api/health.php` → database + storage OK |
| Mobile config | Weekly | `GET /api/mobile/v1/config` → 200 |
| Cron jobs | Weekly | cPanel cron logs for `cron/*.php` |
| Error log | Weekly | Review `error_log` on server (FTP) |
| Backups | Weekly | Confirm Backup Center date + local pre-deploy zip age |

---

## 9. Issue triage

| Priority | Examples | Response |
|----------|----------|----------|
| P1 — Down | Login broken, DB error, registration down | Fix + deploy same day; restore from backup if needed |
| P2 — Degraded | OTP delays, Sheets sync fail, single admin page error | Fix within 1–3 days |
| P3 — Cosmetic | Styling, non-critical 404, copy | Schedule in maintenance window |

**Out of scope in maintenance mode:** Phase 2 preferences UI, new Android features, payroll/allocation redesign.

---

## 10. Contacts and references

| Document | Path |
|----------|------|
| Architecture | `docs/SYSTEM-ARCHITECTURE-MASTER-DOCUMENT.html` |
| Deployment | `docs/DEPLOY-FROM-PC.md`, `docs/DEPLOY-NAMECHEAP.md` |
| Handover | `handover-package/HANDOVER-COMPLETE.txt` |
| Stabilization | `docs/STABILIZATION-REPORT-2026-06-18.md` |
| Closure certificate | `docs/PROJECT-CLOSURE-CERTIFICATE.md` |

**Repository:** github.com/martinsstudioentertainment-ops/event-staff-system

---

## 11. Maintenance mode declaration

Active development on the Olasentra **web** project is closed. Allowed work:

- Security patches
- Bug fixes for production incidents
- Config / content updates via admin
- Backup verification
- Dependency updates (PHP, with regression testing)

Not allowed without new project approval:

- Phase 2 staff preferences (web)
- New portal features
- Architecture rewrites
- Android development (separate track)
