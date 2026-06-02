# Auto-deploy config + files to Namecheap (GitHub Actions)

Use this once so every `git push` uploads the app **and** a correct `config.php` to `public_html`.

## 1. Add GitHub secrets

Repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**

| Secret | Example (olasentra.com) |
|--------|-------------------------|
| `FTP_SERVER` | `ftp.olasentra.com` or server hostname from cPanel |
| `FTP_USERNAME` | cPanel FTP user (often `olastofx`) |
| `FTP_PASSWORD` | FTP password from cPanel → FTP Accounts |
| `FTP_SERVER_DIR` | `/public_html/` |
| `DB_HOST` | `localhost` |
| `DB_NAME` | `olastofx_eventstaff` |
| `DB_USER` | `olastofx_dbuser` |
| `DB_PASS` | MySQL password from cPanel → MySQL Databases |
| `REGISTRATION_SITE_URL` | `https://olasentra.com` |
| `ADMIN_SITE_URL` | `https://olasentra.com/admin` |

Also in cPanel: **MySQL** → add user to database with **ALL PRIVILEGES**.

## 2. Push from your PC

```powershell
cd c:\laragon\www\event-staff-system
git push origin main
```

**Actions** tab → workflow **Deploy to Namecheap** → green check.

## 3. Verify

- `https://olasentra.com/api/health.php` → `"database":"ok"`
- `https://olasentra.com/admin/login.php`

## Without FTP secrets

Use **cPanel → Git Version Control → Update from Remote → Deploy HEAD commit**, then paste `config.php` manually from `config.production.example.php`.
