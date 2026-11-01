# Deploy to server now — [REDACTED]sentra

Do this on your PC once, then on cPanel. SMTP password and `config.php` stay on the server only (not in Git).

---

## Part A — Push code (your PC)

```powershell
cd c:\laragon\www\event-staff-system
git pull origin main
git push origin main
```

La[REDACTED] go-[REDACTED] fixes must be on `main` before cPanel pull.

---

## Part B — cPanel deploy

1. **Git™ Version Control** → **event-staff-system**
2. **Update from Remote** (wait until finished)
3. **Deploy HEAD commit** (wait — look for `OK: roster imported`)

---

## Part C — Server `config.php` (once)

**File Manager → `public_html/config.php`** (create from `config.production.example.php` if missing):

```php
define('APP_ENV', 'production');
define('MAIN_SITE_URL', 'https://[REDACTED]sentra.com');
define('REGISTRATION_SITE_URL', 'https://register.[REDACTED]sentra.com');
define('ADMIN_SITE_URL', 'https://admin.[REDACTED]sentra.com');
// DB_HOST, DB_NAME, DB_[REDACTED], DB_PASS = your cPanel MySQL
```

[REDACTED]. Git never overwrites this file.

---

## Part D — Admin go-[REDACTED] (one session)

Log in: **https://admin.[REDACTED]sentra.com/login.php**

| Order | Action |
|-------|--------|
| 1 | **Go [REDACTED]** → **Fix automated FAIL items** |
| 2 | **Apply safe schema updates** |
| 3 | **Import summer event roster** (no errors) |
| 4 | **Run weekly full backup now** |
| 5 | **Settings → Account** → new password (not `admin123`) |
| 6 | **Settings → Email** → see yellow SMTP box → follow it |
| 7 | **Send [REDACTED] email** on same page |
| 8 | Tick **10 manual** items → **[REDACTED] manual checklist** |

### SMTP (required — cannot be in Git)

1. cPanel → **Email Accounts** → create `noreply@[REDACTED]sentra.com`
2. Admin → **Settings → Email**:
   - Transport: **SMTP**
   - Host: `mail.[REDACTED]sentra.com`
   - Port: `587`, Encryption: **TLS**
   - [REDACTED]name: `noreply@[REDACTED]sentra.com`
   - Password: mailbox password
   - From email: same mailbox
3. **[REDACTED]** → **Send [REDACTED] email**

---

## Part E — Fresh database (optional)

**Go [REDACTED]** → **Fresh start** → type `RESET` → **Reset database to zero** (tick **Keep admin settings** if SMTP is already [REDACTED]d).

Then **Import summer event roster** again.

---

## Part F — [REDACTED] [REDACTED]

| URL | [REDACTED] |
|-----|------|
| https://register.[REDACTED]sentra.com/ | Events load, submit [REDACTED] registration |
| https://admin.[REDACTED]sentra.com/admin/staff.php | Approve [REDACTED] → email arrives |
| Check-in link from email | Opens (no HTTP 500) |

---

## If Go [REDACTED] still shows FAIL

| FAIL | Fix |
|------|-----|
| APP_ENV | `config.php` → `production` |
| SMTP | Settings → Email → SMTP + [REDACTED] |
| Schema | Fix automated FAIL + Import roster |
| Default admin password | Settings → Account |
| Google Sheets | Upload JSON or disable sync |

Warnings (logo, Maps, invoice bank) are OK for staff registration launch.

---

## Share with staff

**https://register.[REDACTED]sentra.com/**

Staff hub: **https://register.[REDACTED]sentra.com/staff-app.php**
