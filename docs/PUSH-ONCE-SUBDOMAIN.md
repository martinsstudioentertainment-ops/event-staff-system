# One push — register.olasentra.com + shared DB

Do these in order **once**, then only **git push + cPanel deploy** for code updates.

---

## Part A — On your PC (now)

```powershell
cd c:\laragon\www\event-staff-system
git pull origin main
git push origin main
```

*(If you have local commits: `git add .` → `git commit -m "Subdomain layout"` → `git push`)*

---

## Part B — cPanel subdomain (one-time)

1. **Domains** → add **`register.olasentra.com`**
2. **Document root:** `/home/olastofx/public_html` (same as main site — one copy of files, one database)
3. **SSL/TLS Status** → AutoSSL for `register.olasentra.com`

---

## Part C — Edit `public_html/config.php` on server (one-time)

Open the file in File Manager. Set these lines (keep your existing `DB_*` password):

```php
define('MAIN_SITE_URL', 'https://olasentra.com');
define('REGISTRATION_SITE_URL', 'https://register.olasentra.com');
define('ADMIN_SITE_URL', 'https://olasentra.com/admin');
define('APP_ENV', 'production');
```

Same `DB_NAME` / `DB_USER` / `DB_PASS` as now — **one shared database**.

---

## Part D — Deploy code

**Git™ Version Control** → **event-staff-system** → **Update from Remote** → **Deploy HEAD commit**

---

## Part E — Test

| URL | Who | What |
|-----|-----|------|
| `https://olasentra.com/` | Public | Company homepage |
| `https://register.olasentra.com/` | Staff | Registration form |
| `https://register.olasentra.com/staff-app.php` | Staff | Staff hub |
| `https://olasentra.com/admin/login.php` | You | Admin ERP (your new password) |

`https://olasentra.com/index.php` should redirect to `https://register.olasentra.com/`.

---

## After this

Every code change:

```powershell
git push origin main
```

cPanel → **Update from Remote** → **Deploy HEAD commit**

Do **not** delete `config.php` on deploy.
