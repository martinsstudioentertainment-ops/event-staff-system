# Deploy to Namecheap with Git push (no manual file upload)

This guide gives you a **repeatable** flow: change code on your PC → **push** → live site updates in a few minutes.

---

## Stellar Plus (your plan) — recommended path

Namecheap **Stellar Plus** includes **cPanel**, **AutoSSL**, **unlimited MySQL databases**, **Cron Jobs**, and **Git™ Version Control**. That is enough for this app without manual uploads.

| What you get on Stellar Plus | Use for Event Staff System |
|------------------------------|----------------------------|
| cPanel Git Version Control | **Method A** — clone GitHub, Pull, Deploy |
| `.cpanel.yml` in this repo | One-click **Deploy HEAD commit** after pull |
| Cron Jobs | `cron/daily-reminders.php` + `cron/weekly-backup.php` |
| AutoSSL | HTTPS for staff registration & check-in |
| Jailed SSH (may need enabling) | Optional faster deploy — see below |

### Best workflow for Stellar Plus

1. **PC:** `git commit` → `git push` → GitHub  
2. **cPanel:** Git Version Control → **Update from Remote** → **Deploy HEAD commit** (~1–2 min)  
3. **Admin (live):** Go live → schema updates only when DB changed  

**Optional:** enable **SSH** (Namecheap support or cPanel → **SSH Access**) so you can run `git pull` in terminal later — not required if you use cPanel Git.

**FTP auto-deploy (Method B)** also works on Stellar Plus if you prefer zero cPanel clicks after push; set GitHub secrets once.

**Typical document root:** `public_html` (main domain) or `public_html/register` (subdomain for staff only).

---

## All hosting plans

You can use **either** method below. **Method A** (GitHub → cPanel Git) is easiest on Stellar Plus. **Method B** (GitHub Actions → FTP) runs automatically on every push.

---

## What you need

| Item | Where to get it |
|------|-----------------|
| Namecheap hosting | Stellar / Stellar Plus / Business (cPanel) |
| Domain pointed to hosting | Namecheap DNS → your server |
| GitHub account | [github.com](https://github.com) (free) |
| Git on your PC | [git-scm.com](https://git-scm.com) or Laragon includes Git |

**Time (first setup):** about **45–90 minutes** once.  
**Time (each update after):** about **2–5 minutes** (commit + push).

---

## One-time: prepare the project on your PC

### 1. Open terminal in the project folder

```powershell
cd c:\laragon\www\event-staff-system
```

### 2. Start Git (first time only)

```powershell
git init
git add .
git commit -m "Initial commit — Event Staff System"
```

`config.php` is **not** committed (see `.gitignore`). Production uses its own `config.php` on the server.

### 3. Create a GitHub repository

1. GitHub → **New repository** → name e.g. `event-staff-system` → **Private** recommended.
2. Do **not** add README if you already committed locally.
3. Copy the repo URL, e.g. `https://github.com/YOURUSER/event-staff-system.git`

### 4. Push from your PC

```powershell
git branch -M main
git remote add origin https://github.com/YOURUSER/event-staff-system.git
git push -u origin main
```

You now have a single source of truth on GitHub.

---

## One-time: prepare Namecheap (cPanel)

### 1. SSL

cPanel → **SSL/TLS Status** → run **AutoSSL** for your domain (HTTPS required for staff PWA and check-in).

### 2. MySQL database

cPanel → **MySQL Databases**:

- Create database (e.g. `username_eventstaff`)
- Create user + strong password
- Add user to database with **ALL PRIVILEGES**

Import once:

- **phpMyAdmin** → Import → `database/database.sql`  
  **or** after first deploy use **Admin → Go live → Apply safe schema updates** on an empty DB.

### 3. Create `config.php` on the server (once)

`config.php` stays **only on the server**, not in Git.

1. cPanel → **File Manager** → `public_html` (or your app folder).
2. Copy **`config.production.example.php`** → rename to **`config.php`** (keep the whole file — it must include `getDB()` and the `require_once` lines, not only the `define()` lines).
3. Edit **`config.php`** and set your real `DB_NAME`, `DB_USER`, `DB_PASS`, and HTTPS URLs.

**Common 500 error:** `config.php` created with only six `define()` lines and no `getDB()` function. Use the full template from `config.production.example.php` in the repo.

4. Create writable folders if missing:

- `storage/logs`
- `storage/backups`
- `storage/backups/weekly`

Set permissions **755** (or **775** if the host requires it).

---

## Method A — cPanel Git Version Control (recommended)

Best when you want to **see the step** on Namecheap: pull from GitHub in cPanel.

### 1. Clone the repo in cPanel

1. cPanel → **Git™ Version Control** (or **Git Version Control**).
2. **Create** → Clone a repository.
3. **Clone URL:** `https://github.com/YOURUSER/event-staff-system.git`
4. **Repository Path:** e.g. `/home/CPANEL_USER/repos/event-staff-system`  
   (use the path cPanel suggests; not necessarily `public_html` yet.)
5. Clone.

### 2. Deploy files into `public_html`

**Option 1 — Document root in repo folder (simplest)**  
If your host allows it, set the domain document root to the cloned folder (Advanced → Domains → document root). Many shared plans use `public_html` only.

**Option 2 — Copy on each update (common)**  
Add a file **`.cpanel.yml`** in the repo root (already in this project if present):

```yaml
---
deployment:
  tasks:
    - export DEPLOYPATH=/home/YOUR_CPANEL_USER/public_html/
    - /bin/cp -R admin assets check-in.php config.production.example.php cron database docs home.php includes index.php manifest.php offline.php privacy.php staff-app.php status.php submit.php sw.js vendor $DEPLOYPATH
```

Replace `YOUR_CPANEL_USER` with your cPanel username (File Manager shows `/home/username/...`).

Then in cPanel Git → **Manage** → **Pull or Deploy** → **Deploy HEAD commit**.

**Option 3 — Manual pull + File Manager**  
After each `git push` on GitHub:

1. cPanel Git → your repo → **Update from Remote** (pull).
2. Copy changed files from the repo folder into `public_html` (or use a small sync script if you have SSH).

### 3. Keep `config.php` safe

Never overwrite `public_html/config.php` when deploying. The `.cpanel.yml` copy list **excludes** `config.php` on purpose.

---

## Method B — GitHub Actions → FTP (automatic on push)

Every push to `main` uploads files to Namecheap via FTP.

### 1. FTP account in cPanel

cPanel → **FTP Accounts** → create account or use main user.  
Note:

- **FTP server:** often `ftp.yourdomain.com` or the server hostname from cPanel
- **Directory:** `/public_html/` (or subfolder)
- Username / password

### 2. GitHub secrets

Repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**:

| Secret name | Value |
|-------------|--------|
| `FTP_SERVER` | e.g. `ftp.yourdomain.com` |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_SERVER_DIR` | e.g. `/public_html/` (trailing slash) |

### 3. Workflow file

This repo includes `.github/workflows/deploy-namecheap.yml`. Push to `main` triggers deploy.

### 4. First push after secrets are set

```powershell
git add .
git commit -m "Enable Namecheap deploy"
git push
```

GitHub → **Actions** tab → watch the workflow (about **2–4 minutes**).

---

## After every code change (your normal routine)

```powershell
cd c:\laragon\www\event-staff-system
git add .
git commit -m "Describe what you changed"
git push
```

| Method | What happens next |
|--------|---------------------|
| **A — cPanel Git** | cPanel → Git → **Update from Remote** → **Deploy** (~1–2 min) |
| **B — GitHub Action** | Wait for green check in GitHub Actions (~2–4 min) |

Then on the live site:

1. **Admin → Go live → Apply safe schema updates** (only when we added DB migrations).
2. Hard refresh staff pages (**Ctrl+F5**) or clear phone browser cache.
3. Quick test: `index.php`, `admin/login.php`.

---

## Cron on Namecheap (required for reminders)

cPanel → **Cron Jobs**:

```text
0 9 * * * /usr/local/bin/php /home/CPANEL_USER/public_html/cron/daily-reminders.php
0 3 * * 0 /usr/local/bin/php /home/CPANEL_USER/public_html/cron/weekly-backup.php
```

Use **PHP path** from cPanel (sometimes `php` or `/usr/bin/php`).

---

## Checklist — first live deploy

- [ ] GitHub repo created and PC pushed
- [ ] Namecheap: SSL active (HTTPS)
- [ ] Database created and schema imported (or Go live schema button)
- [ ] `config.php` on server with `APP_ENV=production`
- [ ] Deploy Method A or B completed
- [ ] `storage/logs` and `storage/backups` writable
- [ ] Admin password changed (not default)
- [ ] **Go live** checklist in admin completed
- [ ] Test registration on a phone

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Site shows 500 | cPanel → **Errors** / PHP error log; check `config.php` DB credentials |
| `config.php` missing | Create from `config.production.example.php` on server |
| Deploy overwrote config | Restore backup; exclude `config.php` from deploy (see `.gitignore` / workflow) |
| CSS old on phone | Ctrl+F5; service worker: clear site data once |
| Git push asks login | Use GitHub Personal Access Token as password, or SSH key |

---

## Summary

| Step | You do |
|------|--------|
| 1 | Code on PC → `git commit` → `git push` to GitHub |
| 2 | Namecheap pulls or FTP deploy runs |
| 3 | DB/schema via admin if needed |
| 4 | Test HTTPS URLs |

No more uploading folders by hand — you **push**, and you always know the step: **commit → push → deploy (pull or Action) → test**.
