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
- `https://register.olasentra.com/` (Ctrl+F5) — shifts show real venues (on-site company only if set per event)
- `https://admin.olasentra.com/login.php`

---

## Summer event roster (your table in Git)

The master list is **`database/live-events-2026.php`** (date, event, location, staff needed, times, working for).

| What Git deploy does | What it does **not** do by itself |
|----------------------|-----------------------------------|
| Copies the updated `.php` file to the server | Old behaviour: nothing — you had to import manually |

**Current deploy script** runs `php database/sync-live-events.php` automatically after each **Deploy HEAD commit** (see deploy log: `OK: roster imported`).

### Edit the roster

1. On your PC, edit `database/live-events-2026.php`
2. `git add` → `git commit` → `git push origin main`
3. cPanel → **Update from Remote** → **Deploy HEAD commit**
4. Check deploy output for `OK: roster imported`  
   If you see `WARN: auto roster import failed`, import manually (below).

### Manual import (if auto-import fails)

1. Log in: `https://admin.olasentra.com/login.php`
2. Open: `https://admin.olasentra.com/import-roster.php` → **Run import now**  
   Or: **Events** → **Import master roster**
3. Hard refresh registration: `https://register.olasentra.com/` (Ctrl+F5)

Do **not** use `register.olasentra.com/import-summer-roster.php` (often 404).

---

## Which URL is which (not admin login)

| URL | Page |
|-----|------|
| `https://olasentra.com/` | Marketing **homepage** (`home.php`) after deploy |
| `https://olasentra.com/index.php` | **Staff registration** form (email, name, events — not admin login) |
| `https://olasentra.com/staff-app.php` | Staff hub (register, check-in, status) |
| `https://olasentra.com/admin/login.php` | **Admin** login (username + password only) |

If `/` looked like a “login form”, you were probably on **registration** (`index.php`) or **admin** (`/admin/`).

---

## Do not delete on the server

- **`public_html/config.php`** — Git deploy does not replace it. Keep your working DB password there.

---

## If `storage` did not deploy

1. File Manager → delete the whole folder `/home/olastofx/public_html/storage` (only if it has wrong 0-byte **files** named `logs` / `database`)
2. Git → **Update from Remote** → **Deploy HEAD commit**
3. Confirm these **folders** exist (not 0-byte files):
   - `/home/olastofx/public_html/storage/logs/`
   - `/home/olastofx/public_html/storage/backups/database/`
   - `/home/olastofx/public_html/storage/backups/weekly/`
4. Permissions on `storage/logs` → **755**

---

## Why the public page looked wrong or broken

| Cause | What you saw |
|--------|----------------|
| **Old deploy copied only some files** | Missing `submit.php`, `staff-app.php`, or `assets/css/public-front.css` → plain layout, form does not save, links 404 |
| **`config.php` is not in Git** | Page can load but DB/features fail until you created `config.php` on the server |
| **Database OK but schema old** | Page loads; admin **Go live** still needed for full features |
| **`parking-page.shtml` still in `public_html`** | Namecheap default page sometimes shows instead of your app |
| **Wrong `storage` setup** | Does **not** break the public page; only logs/backups |

After **Update from Remote → Deploy HEAD commit**, check these exist:

`/home/olastofx/public_html/index.php`  
`/home/olastofx/public_html/submit.php`  
`/home/olastofx/public_html/staff-app.php`  
`/home/olastofx/public_html/assets/css/public-front.css`  
`/home/olastofx/public_html/.htaccess`

Optional: delete `/home/olastofx/public_html/parking-page.shtml` if you do not need it.

---

## If Deploy fails

Open the deploy log in Git Version Control and read the red error line, or use **File Manager** to confirm `.cpanel.yml` exists in the repo root on GitHub.
