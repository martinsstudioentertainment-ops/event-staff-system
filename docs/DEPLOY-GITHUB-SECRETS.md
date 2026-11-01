# Auto-deploy config + files to Namecheap (GitHub Actions)

Use this once so every `git push` uploads the app **and** a correct `config.php` to `public_html`.

## 1. Add GitHub secrets

Repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

| Secret | Example ([REDACTED]sentra.com) |
|--------|-------------------------|
| `FTP_SERVER` | `ftp.[REDACTED]sentra.com` or server hostname from cPanel |
| `FTP_[REDACTED]NAME` | cPanel FTP [REDACTED] (often `[REDACTED]stofx`) |
| `FTP_PASSWORD` | FTP password from cPanel → FTP Accounts |
| `FTP_SERVER_DIR` | `/public_html/` |
| `DB_HOST` | `localhost` |
| `DB_NAME` | `[REDACTED]stofx_eventstaff` |
| `DB_[REDACTED]` | `[REDACTED]stofx_db[REDACTED]` |
| `DB_PASS` | MySQL password from cPanel → MySQL Databases |
| `REGISTRATION_SITE_URL` | `https://[REDACTED]sentra.com` |
| `ADMIN_SITE_URL` | `https://[REDACTED]sentra.com/admin` |

Also in cPanel: **MySQL** → add [REDACTED] to database with **ALL PRIVILEGES**.

## 2. Push from your PC

```powershell
cd c:\laragon\www\event-staff-system
git push origin main
```

**Actions** tab → workflow **Deploy to Namecheap** → green check.

## 3. [REDACTED]

- `https://[REDACTED]sentra.com/api/health.php` → `"database":"ok"`
- `https://[REDACTED]sentra.com/admin/login.php`

## Without FTP secrets

Use **cPanel → Git Version Control → Update from Remote → Deploy HEAD commit**, then paste `config.php` manually from `config.production.example.php`.
