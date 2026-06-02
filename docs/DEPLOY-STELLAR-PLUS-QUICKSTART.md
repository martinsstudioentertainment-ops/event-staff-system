# Stellar Plus — quick deploy steps

Copy-paste checklist for Namecheap **Stellar Plus**. Full detail: [DEPLOY-NAMECHEAP.md](DEPLOY-NAMECHEAP.md).

---

## Phase 1 — GitHub (your PC, ~15 min)

```powershell
cd c:\laragon\www\event-staff-system
git init
git add .
git commit -m "Initial commit"
```

1. Create **private** repo on GitHub: `event-staff-system`
2. Then:

```powershell
git branch -M main
git remote add origin https://github.com/YOURUSER/event-staff-system.git
git push -u origin main
```

---

## Phase 2 — Namecheap cPanel (~30 min)

### Database

1. cPanel → **MySQL® Databases** → create DB + user → **ALL PRIVILEGES**
2. phpMyAdmin → **Import** → `database/database.sql`  
   *(or skip import and use Admin → Go live → Apply safe schema updates after Phase 3)*

### config.php (once, never in Git)

1. File Manager → `public_html`
2. Upload nothing yet if using Git deploy below — after first deploy:
3. Copy `config.production.example.php` → rename **`config.php`**
4. Edit:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpaneluser_eventstaff');  // your real names
define('DB_USER', 'cpaneluser_dbuser');
define('DB_PASS', 'strong_password_here');
define('REGISTRATION_SITE_URL', 'https://yourdomain.com');
define('ADMIN_SITE_URL', 'https://yourdomain.com/admin');
define('APP_ENV', 'production');
```

5. Folders writable: `storage/logs`, `storage/backups` (chmod 755)

### SSL

cPanel → **SSL/TLS Status** → **Run AutoSSL**

---

## Phase 3 — cPanel Git deploy (~15 min)

1. Edit **`.cpanel.yml`** in your PC repo — replace `YOUR_CPANEL_USER` with cPanel username (from File Manager path `/home/username/`)
2. Commit and push:

```powershell
git add .cpanel.yml
git commit -m "Configure cPanel deploy path"
git push
```

3. cPanel → **Git™ Version Control** → **Create**
4. **Clone URL:** `https://github.com/YOURUSER/event-staff-system.git`
5. **Repository path:** e.g. `/home/CPANEL_USER/repos/event-staff` (cPanel suggests a path)
6. **Create** → open repo → **Update from Remote** → **Deploy HEAD commit**
7. Confirm files appear under `public_html` (admin, index.php, assets, vendor, …)
8. Visit `https://yourdomain.com/admin/login.php`

---

## Phase 4 — Go live (~20 min)

| Step | Where |
|------|--------|
| Change admin password | Admin → Settings → Account |
| SMTP email | Admin → Settings → Email → Send test |
| Company + notice | Admin → Website CMS |
| Schema | Admin → **Go live** → Apply safe schema updates |
| Backup | Go live → Run database backup now |
| Cron | cPanel → Cron Jobs (see below) |

### Cron (Stellar Plus)

```text
0 9 * * * /usr/local/bin/php /home/CPANEL_USER/public_html/cron/daily-reminders.php
0 3 * * 0 /usr/local/bin/php /home/CPANEL_USER/public_html/cron/weekly-backup.php
```

Use **PHP** path from cPanel cron screen (may differ).

---

## Every update after (~3 min)

```powershell
git add .
git commit -m "What you changed"
git push
```

Then cPanel → Git → **Update from Remote** → **Deploy HEAD commit** → test site.

---

## If Git deploy fails

Use **Method B** in [DEPLOY-NAMECHEAP.md](DEPLOY-NAMECHEAP.md): GitHub Actions + FTP secrets — works on all Stellar Plus accounts.
