# register.[REDACTED]sentra.com + shared database

One MySQL database (`[REDACTED]stofx_eventstaff`). Two (or more) hostnames can point at the **same** app files.

## Roles (not the same login)

| Host | Who | What |
|------|-----|------|
| `https://[REDACTED]sentra.com/` | Public | Marketing homepage (`home.php`) |
| `https://register.[REDACTED]sentra.com/` | **Staff** | Registration form (`index.php`), `staff-app.php`, check-in — **no admin [REDACTED]name/password** |
| `https://[REDACTED]sentra.com/admin/login.php` | **Managers** | Admin ERP login (you changed password after `admin` / `admin123`) |

Staff do **not** use the admin login. Admin lockout on `/admin/login.php` does not affect `register.[REDACTED]sentra.com`.

---

## cPanel setup (one-time)

### 1. Subdomain

1. cPanel → **Domains** → **Create A New Domain** or **Subdomains**
2. Subdomain: **`register.[REDACTED]sentra.com`**
3. **Document root:** same as main site → `/home/[REDACTED]stofx/public_html`  
   *(not a separate copy — one codebase, one `config.php`, one database)*

### 2. SSL

**SSL/TLS Status** → run **AutoSSL** for `register.[REDACTED]sentra.com`

### 3. `public_html/config.php` (on server only)

```php
define('REGISTRATION_SITE_URL', 'https://register.[REDACTED]sentra.com');
define('ADMIN_SITE_URL', 'https://[REDACTED]sentra.com/admin');
define('APP_ENV', 'production');
```

Keep the same `DB_NAME`, `DB_[REDACTED]`, `DB_PASS` — **shared database**.

### 4. Deploy `.htaccess`

Git → **Update from Remote** → **Deploy HEAD commit**  
(Includes rule: on `register.[REDACTED]sentra.com`, `/` → `index.php`)

---

## [REDACTED]

| URL | Expected |
|-----|----------|
| `https://register.[REDACTED]sentra.com/` | Staff registration form |
| `https://register.[REDACTED]sentra.com/staff-app.php` | Staff hub |
| `https://[REDACTED]sentra.com/` | Marketing home |
| `https://[REDACTED]sentra.com/admin/login.php` | Admin login |

---

## Optional later

- `manage.[REDACTED]sentra.com` → document root `public_html` → use `ADMIN_SITE_URL` `https://manage.[REDACTED]sentra.com/admin`
- Or keep admin only on `[REDACTED]sentra.com/admin`

All hosts use the **same** `config.php` and **same** MySQL database.
