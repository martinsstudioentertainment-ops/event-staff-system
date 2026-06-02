# Deploy once — three hosts, one database

Create subdomains in cPanel, then set **both** subdomain document roots to **`public_html`** (same as main site).

| Host | Document root |
|------|----------------|
| `olasentra.com` | `public_html` |
| `register.olasentra.com` | **`public_html`** |
| `admin.olasentra.com` | **`public_html`** |

If you see **Index of /** on a subdomain, the folder is empty — use **`public_html`** for document root. See [SUBDOMAIN-FIX.md](SUBDOMAIN-FIX.md).

**Deploy HEAD commit** updates `public_html` (and tries to mirror other folders if they exist).

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
