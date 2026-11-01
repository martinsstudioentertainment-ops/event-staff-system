# Deploy once — three hosts, one database

Create subdomains in cPanel, then set **both** subdomain document roots to **`public_html`** (same as main site).

| Host | Document root |
|------|----------------|
| `[REDACTED]sentra.com` | `public_html` |
| `register.[REDACTED]sentra.com` | **`public_html`** |
| `admin.[REDACTED]sentra.com` | **`public_html`** |

If you see **Index of /** on a subdomain, the folder is empty — use **`public_html`** for document root. See [SUBDOMAIN-FIX.md](SUBDOMAIN-FIX.md).

**Deploy HEAD commit** updates `public_html` (and tries to mirror other folders if they exist).

---

## One edit: `public_html/config.php` only

```php
define('MAIN_SITE_URL', 'https://[REDACTED]sentra.com');
define('REGISTRATION_SITE_URL', 'https://register.[REDACTED]sentra.com');
define('ADMIN_SITE_URL', 'https://admin.[REDACTED]sentra.com');
define('APP_ENV', 'production');
```

(Keep your `DB_*` lines unchanged.)

**Important:** `DB_PASS` must be your real cPanel MySQL password — not `YOUR_DATABASE_PASSWORD_HERE`.  
If health shows `"database":"error"` after deploy, fix this file and **[REDACTED]**, then deploy again.

---

## Deploy

**Git** → **Update from Remote** → **Deploy HEAD commit**

**AutoSSL** for `admin.[REDACTED]sentra.com` and `register.[REDACTED]sentra.com`.

---

## URLs

| URL | Use |
|-----|-----|
| `https://admin.[REDACTED]sentra.com/login.php` | Admin login |
| `https://admin.[REDACTED]sentra.com/dashboard.php` | Admin (after login) |
| `https://register.[REDACTED]sentra.com/` | Staff registration |
| `https://[REDACTED]sentra.com/` | Homepage |

`https://[REDACTED]sentra.com/admin/` redirects to `https://admin.[REDACTED]sentra.com/`.

---

## Ongoing

```powershell
git push origin main
```

cPanel → **Update from Remote** → **Deploy HEAD commit**
