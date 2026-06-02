# Event Staff System — Production Deployment Guide

This guide covers moving the Event Staff System from **Laragon (local)** to a **live web server**.

**Namecheap Stellar Plus + Git push:** **[docs/DEPLOY-STELLAR-PLUS-QUICKSTART.md](docs/DEPLOY-STELLAR-PLUS-QUICKSTART.md)** (short checklist) · **[docs/DEPLOY-NAMECHEAP.md](docs/DEPLOY-NAMECHEAP.md)** (full guide).

---

## 1. Server requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Extensions | `pdo_mysql`, `mbstring`, `openssl` |
| HTTPS | Recommended (required for PWA install & secure cookies) |

---

## 2. Upload files

Upload the entire project folder to your web root, e.g.:

```
/public_html/event-staff/
  index.php
  admin/
  assets/
  includes/
  ...
```

**Do not upload:**
- `.vscode/` (optional)
- Local-only secrets if you add them later

**Ensure writable:**
- `storage/logs/` — email log fallback (chmod `755` or `775`)

---

## 3. Create the database

1. Create a MySQL database and user in your hosting panel (cPanel, Plesk, etc.).
2. Import the schema:

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < database/database.sql
```

Or use **phpMyAdmin → Import →** select `database/database.sql`.

If upgrading an existing database, also run migrations in order:

```
database/migrate-phase3.sql
database/migrate-phase4.sql
database/migrate-phase5-attendance.sql
database/migrate-phase5-token.sql
database/migrate-phase6-smtp.sql
database/migrate-phase7.sql
database/migrate-phase8-backfill.sql
```

---

## 4. Configure database connection

Edit `config.php`:

```php
define('DB_HOST', 'localhost');        // or your host's MySQL hostname
define('DB_NAME', 'your_database');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
```

---

## 5. Security checklist (before go-live)

**Use Admin → Go live** for the full interactive checklist (`admin/go-live.php`). See also `docs/GO-LIVE-CHECKLIST.md`.

- [ ] **Change admin password** — Admin → Settings → Admin Account (default is `admin` / `admin123`)
- [ ] **Use HTTPS** — Let's Encrypt via hosting panel
- [ ] **Restrict admin** — consider IP allowlist or HTTP auth on `/admin/` at server level
- [ ] **Remove test data** if imported from local dev
- [ ] **SMTP** — configure real email (Settings → Email Notifications)
- [ ] **File permissions** — PHP files `644`, folders `755`

---

## 6. Email (SMTP)

For production, use **SMTP** in Admin → Settings:

| Provider | Host | Port | Encryption |
|----------|------|------|------------|
| Gmail | smtp.gmail.com | 587 | TLS |
| SendGrid | smtp.sendgrid.net | 587 | TLS |
| Office 365 | smtp.office365.com | 587 | TLS |

Use an **app password** or API key — not your main account password.

Click **Send Test Email** after saving.

---

## 7. Web server configuration

### Apache (.htaccess optional)

If the site lives in a subfolder, no change needed. For clean URLs later, enable `mod_rewrite`.

### Document root

Point the domain or subdomain to the folder containing `index.php`.

### PHP built-in server (local only)

```bash
php -S localhost:8080 -t .
```

Use Laragon/`start-dev.bat` for local development only — not for production.

---

## 8. Cron jobs

### Daily email reminders (optional)

If enabled in **Admin → Settings → Email**, the system sends:

1. **Daily event reminders** — one email per registration per day, from sign-up until the event ends (check-in window closed).
2. **Signup nudges** — delayed emails listing other upcoming events the person has not registered for (stops once they sign up or the event passes).

**CLI (recommended)** — run once daily, e.g. 09:00:

```bash
php cron/daily-reminders.php
```

**Windows Task Scheduler:** Program `php`, arguments `C:\path\to\event-staff-system\cron\daily-reminders.php`, daily trigger.

**Web cron (shared hosting):** Set a secret in Admin → Email → Cron secret key, then call:

```
https://your-registration-site.com/cron/daily-reminders.php?key=YOUR_SECRET
```

Log output: `storage/logs/reminders.log`

---

## 9. PWA (install on phone)

Staff can install the app from:

- Registration page (`index.php`)
- Check-in page (`check-in.php`)
- Status page (`status.php`)

Requirements:
- **HTTPS** on production
- `manifest.php` and `sw.js` accessible at site root

On iPhone: Safari → Share → **Add to Home Screen**  
On Android: Chrome → menu → **Install app**

---

## 10. Post-deploy verification

| Test | URL |
|------|-----|
| Registration form | `/index.php` |
| Form submit | Submit a test registration |
| Admin login | `/admin/login.php` |
| Approve staff | Admin → Staff List |
| Status link | Open link from approval email |
| Check-in | `/check-in.php?token=...` |
| QR print | Admin → Attendance → filter event → Print QR Sheets |
| CSV export | Staff export + Attendance export |
| Theme | Admin → Settings → Appearance |
| SMTP test | Admin → Settings → Send Test Email |

---

## 11. Backups

Back up regularly:

1. **MySQL database** — full dump via hosting panel or `mysqldump`
2. **`storage/logs/`** — if you rely on mail logs
3. **`config.php`** — store credentials securely offline

---

## 12. Troubleshooting

| Problem | Fix |
|---------|-----|
| Blank page / 500 error | Check PHP error log; verify `pdo_mysql` enabled |
| Database connection failed | Verify `config.php` credentials and DB host |
| Emails not sending | Switch to SMTP; check `storage/logs/mail.log` |
| CSS/theme not loading | Ensure `assets/theme.css.php` is reachable |
| PWA not installing | Site must use HTTPS |
| QR codes not loading | External API `api.qrserver.com` must be allowed outbound |

---

## 13. Local development (Laragon)

1. Laragon → **Start All**
2. Cursor → **Ctrl+Shift+B** or run `start-dev.bat`
3. Open `http://localhost:8080/index.php`

Import DB once:

```bash
database/setup.bat
```

---

## Support files reference

| File | Purpose |
|------|---------|
| `config.php` | Database + base URL |
| `database/database.sql` | Full schema + seed events |
| `storage/logs/mail.log` | Email log when SMTP/mail fails |
| `manifest.php` | PWA manifest (dynamic site name) |
| `sw.js` | Service worker for offline shell |
| `assets/theme.css.php` | Dynamic theme from admin settings |

---

*Event Staff System — ready for production after completing sections 3–6 and the security checklist.*
