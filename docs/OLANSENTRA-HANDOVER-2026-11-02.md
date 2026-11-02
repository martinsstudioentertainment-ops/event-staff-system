# OLANSENTRA HANDOVER, 2026-11-02 00:35 (Europe/Dublin)

## PROJECT
"Olansentra" chat: olasentra.com event-staffing app (PHP, cPanel). The current job is **AVIVA Match – Event Staff**, Aviva Stadium, **Sun 8 Nov 2026, 13:00 – Finish, 70 places**, open only to staff already registered on Olasentra.

## ACCESS
- **Box:** shell plus the bot's own browser panel. Repo at /workspace/olasentra-aviva-match. `gh` is logged in. The PHP linter is /tmp/php (8.2); it may need reinstalling.
- **GitHub:** martinsstudioentertainment-ops/event-staff-system. It is a **PUBLIC** repo.
- **cPanel:** user `olastofx`, login in the vault. Reachable **only through the user's computer network**: :2083 gives ERR_CONNECTION_CLOSED from the box's own network, probably an IP allowlist. The server is used through the cPanel Terminal, typed by a browser agent. There is no SSH key from the box.
- **Server paths:**
  - repo /home/olastofx/repositories/event-staff-system
  - web root /home/olastofx/public_html
  - DB olastofx_eventstaff
- **Google account:** olabodeoluwafemi2580@gmail.com owns the forms and sheets.
- **Service account:** event-staff-sheets@event-staff-system-498114.iam.gserviceaccount.com. Its key is on the server only.
- **Never touch:**
  - the ERP (martinsstudiotv.com)
  - the 1 Oct Aviva event, form and sheet, except the approved key rotation
  - the registration system
  - staff passwords, roles and data (only PSA fields and photos, as 1 Oct does)
  - the "Event full" logic and the column P status logic
- **Never** take over the user's PC. Never deploy without approval. Never force push.

## CURRENT STATE (live)
- **Deployed:** server repo and public_html at **276a625**. Homepage 200; www redirects to the bare domain; Kimi's canonical/og URLs and the emerald share image are live.
- **Page:** https://olasentra.com/aviva-match returns 200, shows **15/70** and opens the form.
- **Form:** Google Form id 1799UH70D-dTpccnXP7P25ivGhU18q_MNF_iEpLkEwGo. Public URL: setting `google_form_aviva_match_url` (fallback in AVIVA_MATCH_FORM_FALLBACK_URL).
- **Form responses sheet:** 1WQpUucBP2PVwLX1x3dLNI_qrpM0wiMcsJwesq3ev9ok. Column P "Status" is written back by the import.
- **AVIVA event:** **id 56**, set by setting `aviva_match_event_id`.
  - Inactive on purpose, so it is not on the public sign-up list. Approved.
  - 70 places, start 13:00, times hidden.
  - Name "AVIVA Match – Event Staff (13:00 – Finish)".
- **Roster sheet:** 1qLEZsz88k7SapELtn0JV2AbE93nMYdTWl9O5d_EPjPY, tab "Registrations" (same A–S headers as 1 Oct). Linked to event 56 and shared with the service account.
- **Sign-ups:** `list` shows count=15, onboarded=0. **No real staff have been emailed yet.**
- **Onboarding code** (includes/aviva-match-onboard.php) is deployed but has not run yet. For each accepted row it runs the 1 Oct steps in order:
  1. PSA register lookup
  2. PSA fields saved to the staff profile
  3. badge photos saved
  4. admin PSA notice
  5. AVIVA PSA-number email (approved wording plus the two approved extra lines; sent once)
  6. approved staff_registrations row
  7. shift-details email (sent once)
  8. roster row

  A failed PSA check never blocks the person.

## RUNNING ON ITS OWN
- **Old AVIVA curl cron:** still in crontab. It now gets 403, so **imports are paused**. Remove it at the cron swap.
- **New */5 command-line cron:** **NOT added yet.**
- **myroster reminder/scheduler crons and the other site crons:** they use the old cron key. The full list is in the backup's crontab.txt. Job B must list them and test each one.

## OPEN TASKS (all wait on the user's computer being online for cPanel)
1. **Test run** in `cd ~/public_html`. Each command is `php -q cron/aviva-match-admin.php` followed by:
   1. `import` (reads rows and writes column P; does not onboard)
   2. `find olabodeoluwafemi2580+avivatest@gmail.com`
   3. `onboard-one <signupId>`, run twice

   Check:
   - PSA result is not_found and the admin alert appears
   - the registration is approved
   - the PSA email and the shift email each arrive exactly once
   - the roster row is added
   - column P shows Accepted
2. **The dummy form submit is UNCONFIRMED.** Google showed an error, then "already responded". Check the responses sheet and the `find` output. If it's missing, the import has nothing to test.
3. **Cleanup:** `cleanup-test <signupId> <staffId> --delete-staff`. The dummy staff **STF-0263** (olabodeoluwafemi2580+avivatest@gmail.com, +353830000123) **must be deleted**; use admin if the script refuses. Then `list` and confirm the count is back to the real number. Don't delete the form sheet row; mark its column P "TEST – removed".
4. **Cron swap:** remove the old curl line and add:
   `*/5 * * * * cd /home/olastofx/public_html && /usr/local/bin/php -q cron/aviva-match-sheet-sync.php >> /home/olastofx/public_html/storage/logs/aviva-cron-stderr.log 2>&1`

   The first runs onboard the 15 real people, 10 per run (backfill approved).
5. **Job B** (approved; back up and write a rollback first):
   - replace the old cron key in the 102 live-only files with one config value (patch at /workspace/server-key-patch.zip)
   - one-time staff re-login (remember-me tokens only)
   - change the DB password in cPanel and config.php (the old one leaked in public git history)
   - then test every cron (including myroster), staff login, the homepage and /aviva-match
6. **Close the form at 70** by hand (Google blocks the Apps Script auto-close). Later entries get "Event full" anyway.
7. Check that the 1 Oct event and the registration system are unchanged after the test.

## KEY DATES
- **Sun 8 Nov 2026**, AVIVA Match, 13:00 – Finish.

## FILES AND LOCATIONS (no secrets here)
- **Box repo:** /workspace/olasentra-aviva-match, branch main. Branch `kimi-homepage-og` = 4713097.
- **Key commits:**

  | Commit | What |
  |---|---|
  | 6769fe6 | onboarding |
  | ed819d5 | ops scripts |
  | 263359d | Kimi merge, with camera=(self) |
  | 2328681 | admin helper |
  | 276a625 | maps include fix (**deployed**) |
  | 9abf3cc | handover |
- **Code:**
  - includes/aviva-match.php
  - includes/aviva-match-sheet-sync.php
  - includes/aviva-match-onboard.php
  - cron/aviva-match-sheet-sync.php (command line only)
  - cron/aviva-match-admin.php (command line only)
  - cron/.htaccess (blocks web access)
  - aviva-match-event.php
  - scripts/ops/ (backup, deploy, rollback)
- **Handovers:**
  - /workspace/handover/AVIVA-MATCH-HANDOVER-2026-11-01.md (detailed log)
  - this file
  - copies of both in the repo's docs/
- **Box files:** mode 600 because they contain secrets. Never print or commit them.
  - /workspace/new-keys.txt
  - rotate-keys.sql
  - rotate-intake-key.sql
  - config-line-intake.txt
  - aviva-cron-cmd-new.txt
  - server-key-patch.zip
  - keytrace.txt
  - patchlist.txt
  - keypatch.py
- **Live snapshot (read-only):** /workspace/live-2026-11-01/public_html.
- **Server backup:** /home/olastofx/backups/pre-merge-20261101-1905/ (public_html.tgz, DB dump, crontab.txt, server-repo.tgz, HEAD; Kimi's commits bundle).
- **Secrets live only in:** server config.php, the vault, and the 600 box files above.

## ROLLBACKS
- **Deploy rollback (files and repo):** `bash /home/olastofx/repositories/event-staff-system/scripts/ops/rollback.sh /home/olastofx/backups/pre-merge-20261101-1905`. Add `--db` to restore the database as well.
- **config.php:** the server has dated config.php .bak copies from the key-rotation edits; restore by copying one back.
- **AVIVA test data:** `cleanup-test` (see open task 3).

## RULES
- Follow the 1 Oct method exactly, and reuse its functions rather than inventing new flows.
- Keys go in config/env only, in one place; never in the repo, which is public. Never print secrets.
- No public cron URLs: crons are command-line only, and the web gets 403.
- Keep other bots' commits; Kimi now works only in GitHub branches, and Cursor merges and deploys.
- Back up and write a rollback before every deploy or job. Don't deploy without approval. No force push.
- Update this handover and commit it after each job. Report **done / tested / live** separately, with pass/fail.
- Stop and ask only for a login, a 2FA code or a payment. Don't take over the user's PC; use only the bot's own browser.
