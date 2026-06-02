# Deploy once — minimal steps

Your subdomain can stay on folder **`/home/olastofx/register.olasentra.com`** (no need to change document root in cPanel).

Each **Deploy HEAD commit** copies the full app to **both**:

- `/home/olastofx/public_html/`
- `/home/olastofx/register.olasentra.com/`

It also copies `public_html/config.php` → register folder (same database, same settings).

---

## One-time: `config.php` only in `public_html`

File Manager → **`public_html/config.php`** — set these lines (keep your `DB_*` password):

```php
define('MAIN_SITE_URL', 'https://olasentra.com');
define('REGISTRATION_SITE_URL', 'https://register.olasentra.com');
define('ADMIN_SITE_URL', 'https://olasentra.com/admin');
define('APP_ENV', 'production');
```

You do **not** edit register’s folder by hand — deploy syncs it.

---

## Every update (only this)

### PC

```powershell
cd c:\laragon\www\event-staff-system
git push origin main
```

### cPanel

**Git Version Control** → **Update from Remote** → **Deploy HEAD commit**

---

## URLs

| URL | Page |
|-----|------|
| `https://register.olasentra.com/` | Staff registration |
| `https://olasentra.com/` | Homepage |
| `https://olasentra.com/admin/login.php` | Admin |

---

## Subdomain in cPanel

Only confirm **`register.olasentra.com`** exists with document root  
`/home/olastofx/register.olasentra.com` — leave it as-is.

Run **AutoSSL** for the subdomain once.
