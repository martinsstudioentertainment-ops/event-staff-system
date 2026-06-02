# Event Staff System

Multi-event staff registration and management for Ireland-based event teams. Built with **PHP**, **MySQL**, and a responsive HTML/CSS/JS frontend — designed for **Laragon** local development and production deployment.

---

## Features

| Area | Capabilities |
|------|----------------|
| **Registration** | Multi-event checkboxes, Eircode, Security/Steward roles, server validation |
| **Admin** | Login, dashboard, staff approve/reject, event CRUD, settings |
| **Email** | PHP mail, SMTP (Gmail/SendGrid), log-only dev mode, test email |
| **Attendance** | QR check-in, bulk print, manual check-in, CSV export |
| **Staff portal** | Personal status page via email link (`status.php`) |
| **Branding** | Site name, primary color, font — from admin settings |
| **Mobile** | Responsive layout, card tables, PWA install support |
| **Export** | Staff CSV + attendance CSV with filters |

---

## Quick start (Laragon + Cursor)

### 1. Start MySQL
Laragon tray → **Start All**

### 2. Setup database (first time only)
**Option A — Cursor task:** `Ctrl+Shift+P` → **Tasks: Run Task** → **Event Staff: Setup Database**

**Option B — Browser:** open `http://localhost:8080/database/setup.php` → **Build / Reset Database**

**Option C — CLI:**
```bat
cd database
setup.bat
```

### 3. Start dev server
**Option A:** `Ctrl+Shift+B` (starts server + opens browser)

**Option B:**
```bat
start-dev.bat
```

### 4. Open the app
| Page | URL |
|------|-----|
| Registration | http://localhost:8080/index.php |
| Admin login | http://localhost:8080/admin/login.php |
| Health check | http://localhost:8080/api/health.php |

**Default admin:** `admin` / `admin123` — change before production.

---

## Project structure

```
event-staff-system/
├── index.php              # Registration form (main entry)
├── submit.php             # Form handler
├── status.php             # Staff self-service status portal
├── check-in.php           # QR / link check-in
├── manifest.php           # PWA manifest
├── sw.js                  # Service worker
├── config.php             # Database config
├── admin/                 # Admin panel
├── api/                   # Public JSON + health
├── assets/css/            # variables.css + style.css
├── assets/theme.css.php   # Dynamic theme from settings
├── includes/              # PHP modules (auth, mail, repos)
├── database/              # SQL schema + migrations
├── storage/logs/          # Email log (local dev)
├── start-dev.bat          # PHP dev server
├── DEPLOY.md              # Production deployment guide
└── README.md              # This file
```

---

## Admin guide

| Task | Path |
|------|------|
| Review pending staff | Admin → Staff List → filter Pending |
| Approve / reject | Staff List or View Staff |
| Manage events | Admin → Event Management |
| Attendance & QR | Admin → Attendance |
| Print all QR codes | Attendance → filter event → Print QR Sheets |
| Export CSV | Admin → Export Data |
| Email / SMTP | Admin → Settings |
| Theme / colors | Admin → Settings → Appearance |
| Change password | Admin → Settings → Admin Account |

---

## Email setup (local vs production)

**Local dev:** Settings → Email Transport → **Log only**  
Emails are written to `storage/logs/mail.log`.

**Production:** Settings → **SMTP** with your provider (see `DEPLOY.md`).

Enable notifications:
- Email on approve/reject (includes check-in + status links)
- Email on registration received (optional)

---

## Database migrations

Fresh install: import `database/database.sql`.

Existing database: run migrations in order, or use:

```bat
cd database
migrate-all.bat
```

| File | Purpose |
|------|---------|
| migrate-phase3.sql | Admin users |
| migrate-phase4.sql | System settings |
| migrate-phase5-*.sql | Attendance + QR tokens |
| migrate-phase6-smtp.sql | SMTP settings |
| migrate-phase7.sql | Status tokens + theme |
| migrate-phase8-backfill.sql | Backfill status tokens |

---

## Configuration

Edit `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'event_staff_system');
define('DB_USER', 'root');
define('DB_PASS', '');
```

---

## Production

See **[DEPLOY.md](DEPLOY.md)** for full deployment checklist:
- Upload files
- Import database
- Configure SMTP + HTTPS
- Change admin password
- PWA install on staff phones

---

## Tech stack

- PHP 8.1+ with PDO/MySQL
- Vanilla JS (no framework)
- CSS custom properties (`variables.css`)
- Laragon (local Apache/MySQL/PHP)

---

## License

Internal event operations tool — adjust licensing as needed for your organisation.
