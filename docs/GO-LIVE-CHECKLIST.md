# Go live checklist — Event Staff System

Use **Admin → Go live** in the ERP console. One **Complete checklist** covers automated checks (core, email, backups, Sheets, Maps, push, phase 31 schema) and manual server tasks.

---

## 1. Server & config (manual)

- [ ] VPS/hosting with PHP 8.1+, MySQL 8, HTTPS
- [ ] Upload project files (exclude `.vscode/`)
- [ ] Create MySQL database and user
- [ ] Copy `config.production.example.php` values into `config.php`:
  - `APP_ENV` = `production`
  - Real `DB_*` credentials
  - `REGISTRATION_SITE_URL` = `https://…`
  - `ADMIN_SITE_URL` = `https://…/admin` (if separate)
- [ ] Point domain DNS to server; install SSL (Let's Encrypt)
- [ ] `storage/logs` and `storage/backups` writable (755/775)

---

## 2. Database (automated + one click)

- [ ] Import `database/database.sql` on fresh install, **or** migrate existing DB
- [ ] In admin: **Go live → Apply safe schema updates** (phases 28–31, incl. Google Sheets columns)
- [ ] **Go live → Run database backup now** (before first real data)

---

## 3. Security (automated)

- [ ] Change admin password (**Settings → Account**) — default `admin123` is blocked in production
- [ ] Enable **Activity logging** (Settings → System)
- [ ] Optional: `admin/.htaccess.example` for IP restriction
- [ ] Remove demo/test staff and events you don't need
- [ ] **Go live → Remove demo invoices** (Aviva sample)

---

## 4. Email (automated check)

- [ ] **Settings → Email**: transport = **SMTP** with real host/port
- [ ] Set **From email** and test with **Send test email**
- [ ] Set **Cron secret key** for web cron

---

## 5. Business settings (automated check)

- [ ] Company name, logo, contact (**Website / Settings**)
- [ ] **Invoice bank details** and commission rates (**Settings → System**)
- [ ] Create real **events** with venue Eircode + GPS
- [ ] Registration URL in settings matches live HTTPS domain
- [ ] **Google Maps API key** (Settings → Security) if using venue GPS / maps
- [ ] **Google Sheets** (optional): service account JSON, sheet URL per event, share sheet with robot email
- [ ] **PWA push** (optional): VAPID keys + `composer install` on server

---

## 6. Cron jobs (server — required)

Schedule daily on the server:

```bash
# Reminders + no-show blacklist (required)
0 9 * * * php /path/to/event-staff-system/cron/daily-reminders.php

# Weekly full backup (DB + site files — overwrites previous copy)
0 3 * * 0 php /path/to/event-staff-system/cron/weekly-backup.php
```

Web cron alternative (set secret in admin):

```
https://your-site.com/cron/daily-reminders.php?key=YOUR_SECRET
```

---

## 7. Staff-facing (manual)

- [ ] Share staff link: `https://your-domain.com/staff-app.php`
- [ ] Test on a **real phone** over HTTPS:
  1. Register
  2. Admin approve
  3. Status / check-in
  4. Work hours → invoice

---

## 8. When all checks are green

Admin **Go live** page shows **Ready for go live** when:

- All automated checks pass (no FAIL)
- No warnings (SMTP, HTTPS, backup, etc.)
- All manual tasks ticked
- `APP_ENV=production` in config.php

---

## Not required for first go-live

These are on the long-term roadmap (payroll, assignments, WhatsApp API, client portal, etc.) — see master plan phases 29–40.

---

## Quick links (local dev)

- Admin: `http://127.0.0.1:8080/admin/login.php`
- Go live: `http://127.0.0.1:8080/admin/go-live.php`
- Staff app: `http://127.0.0.1:8080/staff-app.php`

On production, replace with your HTTPS domain.
