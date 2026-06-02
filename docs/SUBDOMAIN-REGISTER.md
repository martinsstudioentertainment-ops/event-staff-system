# register.olasentra.com + shared database

One MySQL database (`olastofx_eventstaff`). Two (or more) hostnames can point at the **same** app files.

## Roles (not the same login)

| Host | Who | What |
|------|-----|------|
| `https://olasentra.com/` | Public | Marketing homepage (`home.php`) |
| `https://register.olasentra.com/` | **Staff** | Registration form (`index.php`), `staff-app.php`, check-in — **no admin username/password** |
| `https://olasentra.com/admin/login.php` | **Managers** | Admin ERP login (you changed password after `admin` / `admin123`) |

Staff do **not** use the admin login. Admin lockout on `/admin/login.php` does not affect `register.olasentra.com`.

---

## cPanel setup (one-time)

### 1. Subdomain

1. cPanel → **Domains** → **Create A New Domain** or **Subdomains**
2. Subdomain: **`register.olasentra.com`**
3. **Document root:** same as main site → `/home/olastofx/public_html`  
   *(not a separate copy — one codebase, one `config.php`, one database)*

### 2. SSL

**SSL/TLS Status** → run **AutoSSL** for `register.olasentra.com`

### 3. `public_html/config.php` (on server only)

```php
define('REGISTRATION_SITE_URL', 'https://register.olasentra.com');
define('ADMIN_SITE_URL', 'https://olasentra.com/admin');
define('APP_ENV', 'production');
```

Keep the same `DB_NAME`, `DB_USER`, `DB_PASS` — **shared database**.

### 4. Deploy `.htaccess`

Git → **Update from Remote** → **Deploy HEAD commit**  
(Includes rule: on `register.olasentra.com`, `/` → `index.php`)

---

## Test

| URL | Expected |
|-----|----------|
| `https://register.olasentra.com/` | Staff registration form |
| `https://register.olasentra.com/staff-app.php` | Staff hub |
| `https://olasentra.com/` | Marketing home |
| `https://olasentra.com/admin/login.php` | Admin login |

---

## Optional later

- `manage.olasentra.com` → document root `public_html` → use `ADMIN_SITE_URL` `https://manage.olasentra.com/admin`
- Or keep admin only on `olasentra.com/admin`

All hosts use the **same** `config.php` and **same** MySQL database.
