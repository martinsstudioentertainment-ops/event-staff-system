# Deploy once — three hosts, one database

Create subdomains in cPanel (document roots can stay the default folders cPanel gives you):

| Subdomain | Typical folder |
|-----------|----------------|
| `register.olasentra.com` | `/home/olastofx/register.olasentra.com` |
| `admin.olasentra.com` | `/home/olastofx/admin.olasentra.com` |
| `olasentra.com` | `/home/olastofx/public_html` |

**Deploy HEAD commit** copies the full app into all three folders and syncs `config.php` from `public_html`.

---

## One edit: `public_html/config.php` only

```php
define('MAIN_SITE_URL', 'https://olasentra.com');
define('REGISTRATION_SITE_URL', 'https://register.olasentra.com');
define('ADMIN_SITE_URL', 'https://admin.olasentra.com');
define('APP_ENV', 'production');
```

(Keep your `DB_*` lines unchanged.)

**Important:** `DB_PASS` must be your real cPanel MySQL password — not `YOUR_DATABASE_PASSWORD_HERE`.  
If health shows `"database":"error"` after deploy, fix this file and **Save**, then deploy again.

---

## Deploy

**Git** → **Update from Remote** → **Deploy HEAD commit**

**AutoSSL** for `admin.olasentra.com` and `register.olasentra.com`.

---

## URLs

| URL | Use |
|-----|-----|
| `https://admin.olasentra.com/login.php` | Admin login |
| `https://admin.olasentra.com/dashboard.php` | Admin (after login) |
| `https://register.olasentra.com/` | Staff registration |
| `https://olasentra.com/` | Homepage |

`https://olasentra.com/admin/` redirects to `https://admin.olasentra.com/`.

---

## Ongoing

```powershell
git push origin main
```

cPanel → **Update from Remote** → **Deploy HEAD commit**
