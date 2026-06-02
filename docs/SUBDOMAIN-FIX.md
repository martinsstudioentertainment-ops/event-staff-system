# Subdomain not working — fix in 2 minutes

## What you see

- `https://register.olasentra.com/` → **Index of /** (empty folder)
- `https://admin.olasentra.com/login.php` → **404**

Main site `https://olasentra.com/` works because files are only in **`public_html`**.

---

## Fix (recommended): point subdomains to `public_html`

Use **one folder** for all three hostnames. `.htaccess` already routes each domain correctly.

### Step 1 — register.olasentra.com

1. cPanel → **Domains** → **Manage** `register.olasentra.com`
2. **Document root** → set to: **`public_html`**
3. **Update**

### Step 2 — admin.olasentra.com

1. **Manage** `admin.olasentra.com`
2. **Document root** → **`public_html`**
3. **Update**

### Step 3 — Deploy (updates `.htaccess` in public_html)

**Git** → **Update from Remote** → **Deploy HEAD commit**

### Step 4 — Test

| URL | Expected |
|-----|----------|
| `https://register.olasentra.com/` | Registration form |
| `https://admin.olasentra.com/login.php` | Admin login |
| `https://olasentra.com/` | Homepage |

No need to copy files into `register.olasentra.com` or `admin.olasentra.com` folders when using this setup.

---

## Alternative: keep separate folders

Only if document root stays `/home/olastofx/register.olasentra.com`:

1. **Deploy HEAD commit** and read the deploy log (must say `OK:` for each path).
2. File Manager → that folder must contain `index.php`, `admin/`, `assets/`, `config.php`.
3. If deploy says `SKIP` or `WARN`, use the **public_html** fix above instead.
