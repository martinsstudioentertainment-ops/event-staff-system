# Update the live site — Git Version Control only

Use this every time. No FTP script required.

---

## One-time setup (if not done already)

1. **GitHub** — code is at `martinsstudioentertainment-ops/event-staff-system`
2. **cPanel → Git™ Version Control → Create**
   - Clone URL: `https://github.com/martinsstudioentertainment-ops/event-staff-system.git`
   - Repository path: e.g. `/home/olastofx/repositories/event-staff-system` (use cPanel’s suggestion)
3. Open the repo → **Update from Remote** → **Deploy HEAD commit**
4. **File Manager → `public_html`**
   - Copy `config.production.example.php` → rename to **`config.php`**
   - Edit `DB_*` and passwords (once; Git never overwrites `config.php`)
5. **File Manager → `public_html/storage`**
   - Delete any **0-byte files** named `logs`, `database`, or `backups`
   - After next deploy, you should have a real folder: `storage/logs/`
6. **MultiPHP Manager** — PHP **8.1** or **8.2** for the domain

---

## Every update (3 steps)

### Step 1 — On your PC (Laragon terminal)

```powershell
cd c:\laragon\www\event-staff-system
git add .
git commit -m "Describe your change"
git push origin main
```

### Step 2 — cPanel

1. **Git™ Version Control**
2. Click your **event-staff-system** repository
3. Click **Update from Remote** (wait until it finishes)
4. Click **Deploy HEAD commit** (wait until it finishes)

### Step 3 — Test

- `https://olasentra.com/api/health.php`
- `https://olasentra.com/`
- `https://olasentra.com/admin/login.php`

---

## Do not delete on the server

- **`public_html/config.php`** — Git deploy does not replace it. Keep your working DB password there.

---

## If `storage` is still wrong after deploy

1. File Manager → `public_html/storage`
2. Delete **files** (not folders) named `logs`, `database`, or extra `backups` (0 bytes)
3. Git → **Deploy HEAD commit** again
4. Confirm folder exists: `public_html/storage/logs/` (directory, not a file)

---

## If Deploy fails

Open the deploy log in Git Version Control and read the red error line, or use **File Manager** to confirm `.cpanel.yml` exists in the repo root on GitHub.
