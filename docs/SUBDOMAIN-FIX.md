# Subdomain not working — fix in 2 minutes

## What you see

- `https://register.[REDACTED]sentra.com/` → **Index of /** (empty folder)
- `https://admin.[REDACTED]sentra.com/login.php` → **404**

Main site `https://[REDACTED]sentra.com/` works because files are only in **`public_html`**.

---

## Fix A (easiest): one folder `public_html` for all domains

Use **one folder** for all three hostnames. `.htaccess` already routes each domain correctly.

### Step 1 — register.[REDACTED]sentra.com

1. cPanel → **Domains** → **Manage** `register.[REDACTED]sentra.com`
2. **New Document Root** — delete `/register` and type: **`public_html`**
3. Click **Update**

### Step 2 — admin.[REDACTED]sentra.com

1. **Manage** `admin.[REDACTED]sentra.com`
2. **New Document Root** → **`public_html`** (not `/admin`)
3. **Update**

---

## Fix B: keep `/register` folder (your current screen)

Your screenshot shows document root **`/register`** (= `/home/[REDACTED]stofx/register`). That folder was empty.

1. Leave document root as **`/register`**
2. **Git** → **Update from Remote** → **Deploy HEAD commit** (deploy now copies files into `/home/[REDACTED]stofx/register`)
3. File Manager → `/home/[REDACTED]stofx/register` must contain `index.php`, `admin/`, `assets/`, `config.php`
4. Repeat deploy for **admin** subdomain folder `/home/[REDACTED]stofx/admin` if admin uses that path

### Step 3 — Deploy (updates `.htaccess` in public_html)

**Git** → **Update from Remote** → **Deploy HEAD commit**

### Step 4 — [REDACTED]

| URL | Expected |
|-----|----------|
| `https://register.[REDACTED]sentra.com/` | Registration form |
| `https://admin.[REDACTED]sentra.com/login.php` | Admin login |
| `https://[REDACTED]sentra.com/` | Homepage |

No need to copy files into `register.[REDACTED]sentra.com` or `admin.[REDACTED]sentra.com` folders when using this setup.

---

## Alternative: keep separate folders

Only if document root stays `/home/[REDACTED]stofx/register.[REDACTED]sentra.com`:

1. **Deploy HEAD commit** and read the deploy log (must say `OK:` for each path).
2. File Manager → that folder must contain `index.php`, `admin/`, `assets/`, `config.php`.
3. If deploy says `SKIP` or `WARN`, use the **public_html** fix above instead.
