# Deploy config to Namecheap from your PC (one command)

The AI in Cursor **cannot** log into your cPanel. This script uploads **`config.php`** for you using FTP from your computer.

## One-time setup (2 minutes)

1. Copy **`deploy.local.ps1.example`** → **`deploy.local.ps1`** (project root).
2. Edit **`deploy.local.ps1`** with:
   - **FTP** — cPanel → **FTP Accounts** (server, username, password)
   - **MySQL** — cPanel → **MySQL Databases** (`olastofx_eventstaff`, `olastofx_dbuser`, password)
3. In cPanel: add MySQL user to database with **ALL PRIVILEGES**.

## Each time you need to fix / update production config

```powershell
cd c:\laragon\www\event-staff-system
powershell -ExecutionPolicy Bypass -File .\scripts\push-production.ps1
```

Then open:

- `https://olasentra.com/api/health.php` → `"database":"ok"`
- `https://olasentra.com/admin/login.php`

`deploy.local.ps1` is **not** committed to Git.

## Alternative: GitHub Actions

If you add FTP + DB secrets in GitHub (see [DEPLOY-GITHUB-SECRETS.md](DEPLOY-GITHUB-SECRETS.md)), every `git push` deploys automatically — no local script needed.

## Code updates (not config)

**cPanel → Git Version Control → Update from Remote → Deploy HEAD commit**
