# AVIVA Match – Event Staff (olasentra.com) — Handover 2026-11-01

## Links
- Live page: https://olasentra.com/aviva-match
- Form (public): https://docs.google.com/forms/d/e/1FAIpQLSdE_ZTal9HKrAdVh2ZY3H2R5Hd-L4NFK1VhAzCWpUK3cbkmFg/viewform
- Form edit (ID 1799UH70D-dTpccnXP7P25ivGhU18q_MNF_iEpLkEwGo), owner olabodeoluwafemi2580@gmail.com
- Response sheet: https://docs.google.com/spreadsheets/d/1WQpUucBP2PVwLX1x3dLNI_qrpM0wiMcsJwesq3ev9ok/edit (tab "Form Responses 1"), shared Editor with event-staff-sheets@event-staff-system-498114.iam.gserviceaccount.com

## How it works
1. Page button opens the form (setting google_form_aviva_match_url).
2. cPanel cron every 5 min (host minimum) curls /cron/aviva-match-sheet-sync.php?key=<cron fallback key>.
3. includes/aviva-match-sheet-sync.php reads new sheet rows (counter setting aviva_match_sheet_rows), checks each via includes/aviva-match.php: registered staff by email OR mobile (digits), not blacklisted, no duplicate, cap 70. Never creates staff, never calls processGoogleFormStaffIntake.
4. Accepted -> aviva_match_signups (this is the X/70 count). Every row logged in aviva_match_sheet_rows. Status written to sheet column P: Accepted / Duplicate / Not registered - register on olasentra.com first / Event full.
5. At 70 the page hides the button and shows "Event full".
- Code: repo martinsstudioentertainment-ops/event-staff-system, branch aviva-match-event, commit 9f73102, deployed via cPanel Git 2026-11-01 12:40. Old 1 Oct event, heartbeat and registration untouched.

## Tests (2026-11-01) — all PASS
1 Registered accepted+counted (1/70) PASS · 2 Unregistered blocked with register-first status PASS · 3 Duplicate rejected PASS · 4 Counter X/70 PASS · 5 Cleanup to 0/70 PASS (test staff id 262, responses, rows, badge files in Drive Bin) · 6 Desktop+mobile+form link PASS

## Not done / open
- Match date TBC (setting aviva_match_date + form description).
- Google Form does not close itself at 70 (Apps Script trigger blocked by Google in bot browser): turn off "Accepting responses" manually at 70; late entries are marked Event full and not counted.
- Form has no PPS number / Date of birth questions (old form reportedly had them) — confirm.
- Form is limited to 1 response per Google account (verified email collection on).
- Exposed keys in public repo history (cron fallback key, Google Form intake key) should be rotated.
- Branch not merged to main.

## Update 2026-11-01 (later): 1 Oct steps now run for accepted AVIVA Match rows (on main, NOT deployed)

New file `includes/aviva-match-onboard.php`, called at the end of `avivaMatchSheetSync()` (so the existing cron runs it).
For each `aviva_match_signups` row with `onboarded_at IS NULL` and `is_test = 0` (10 per run, max 5 attempts each). This covers new rows and backfills rows that were accepted earlier:

1. `lookupPsaLicenceOnRegister` (the form number, or the profile number if the form one is blank)
2. `googleFormIntakeUpdateStaff`, with PSA fields only (licence, register status/name/checked_at)
3. `googleFormIntakeSavePsaBadgePhotos`, using the form's Drive links
4. `notifyAdminPsaLicenceProblemIfNeeded`
5. The AVIVA Match PSA-number email. It is sent only for not_found/invalid_format, once per staff member per event (setting `aviva_match_psa_email_sent_{staff}_{event}`)
6. An approved `staff_registrations` row. It is inserted only if this staff member/email has no row for the event yet
7. `sendGoogleFormShiftDetailsEmailIfNeeded` (1 Oct dedupe key)
8. `syncRegistrationToGoogleSheet`

A failed or unreachable PSA check never blocks: the person stays accepted and still gets the shift email.
New columns on aviva_match_signups: event_registration_id, psa_status, onboarded_at, onboard_attempts, onboard_error.

**Event row:** it is created once with a direct INSERT and its id is saved in setting `aviva_match_event_id`. The values are:
- name "AVIVA Match – Event Staff (13:00 – Finish)"
- date 2026-11-08, start 13:00, staff_needed 70
- **is_active = 0** and times_confirmed = 0

Why I did it this way:
- I did not use createEvent() because it rewrites venue GPS and spreads it to every event linked to that venue, which could change the 1 Oct event. It also creates a roster sheet.
- With is_active = 0, the event is not on the public olasentra.com sign-up list, so nobody can get round the 70 cap or the registered-only check.
- With times_confirmed = 0, the shared email code does not print "13:00 – 23:00". The 13:00 – Finish time is in the event name instead.
- The event has no roster sheet linked, so step 8 is skipped until an admin links one.

**Deploy/test (pending: wait until Kimi's server commits 4713097/e90f4a9 are merged to GitHub main):**
1. Merge, then run cPanel Git Deploy HEAD.
2. Before you enable the cron, mark any test signups `is_test=1`, so they don't get real emails.
3. Run `php -q cron/aviva-match-sheet-sync.php` once over SSH and check `onboard` in storage/logs/aviva-match-sheet-sync.log.
4. Check:
   - setting aviva_match_event_id exists, and the event is inactive
   - each accepted signup has onboarded_at and event_registration_id set
   - staff_registrations rows are approved
   - one shift email is sent per person
   - a PSA email goes out only for not_found/invalid_format
   - running the script a second time sends nothing new
5. Test with one real accepted row first if possible.

## Update 2026-11-02 00:15: merge/deploy prep (approved, [pt29u]). BLOCKED on server access
- Approved: the two extra PSA email lines (already in 6769fe6); the AVIVA event stays inactive.
- Inactive event vs the import: the import never reads `is_active`. It uses `aviva_match_event_id` and getEventById, and the shift email doesn't check it either. /aviva-match reads aviva_match_signups and google_form_aviva_match_url, not the events row, so the counter and the form button are unaffected (from the code; still to be tested live).
- The box has no SSH/terminal access to olasentra.com, so the server steps need the cPanel Terminal in a browser. Scripts are ready in `scripts/ops/` (no secrets; DB credentials are read from config.php into a temporary 600 file and never printed):
  1. `1-backup-and-bundle.sh` → `/home/olastofx/backups/pre-merge-<TS>/`. It saves public_html.tgz, server-repo.tgz, olastofx_eventstaff.sql.gz, crontab.txt, server-repo-HEAD.txt, and kimi-commits.bundle and kimi-commits.txt (the server commits not on GitHub main).
  2. Download kimi-commits.bundle to the box. Cursor then pushes it to the `kimi-homepage-og` branch and merges it into main (Kimi wins on homepage/OG files, AVIVA wins on AVIVA files).
  3. `2-deploy-merged-main.sh`: fast-forwards the server repo to GitHub main and runs scripts/cpanel-deploy.sh. The server HEAD then equals GitHub main.
  4. `rollback.sh <backup dir> [--db]`: resets the repo HEAD, restores the files (it doesn't touch uploads/ or logs) and, with --db, the database.
- Roster sheet: Admin → Events → tick "AVIVA Match – Event Staff (13:00 – Finish)" → **Create sheets**. This is the same createGoogleSheetForEvent format as 1 Oct, and it fills events.google_sheet_url/google_sheet_tab. To link a sheet by hand instead: Edit event → "Google Sheet URL" (`https://docs.google.com/spreadsheets/d/<ID>/edit`) and "Sheet tab name". The sheet must be shared with event-staff-sheets@event-staff-system-498114.iam.gserviceaccount.com as Editor. Keep Active unticked.

## Update 2026-11-02 00:25: Kimi merge done on GitHub main (NOT deployed yet)
- Backup on the server: `/home/olastofx/backups/pre-merge-20261101-1905/` (public_html.tgz, DB dump, crontab, server-repo.tgz, HEAD).
- Rollback: `bash /home/olastofx/repositories/event-staff-system/scripts/ops/rollback.sh /home/olastofx/backups/pre-merge-20261101-1905` (add `--db` to restore the database as well). Run it after the deploy, when the script is in the repo, or use the copy in public_html/../ (see the commands below).
- Branch `kimi-homepage-og` = 4713097 (12442ef → e90f4a9 → 4713097), pushed to GitHub.
- Merge commit **263359d** on main. There were no git conflicts: Kimi's commits touch only homepage/theme/OG/.htaccess/robots/sitemap/deploy-script files, and the AVIVA work touches only AVIVA files.
- One manual change in the merge: `.htaccess` Permissions-Policy changed from `camera=()` to `camera=(self)`, because admin QR scan check-in (assets/js/admin-scan-checkin.js) uses the camera.
- Checked:
  - The www→apex 301 runs before the /aviva-match rewrites, so the page still works.
  - cron/.htaccess deny is unaffected.
  - The deploy script only adds robots.txt, sitemap.xml and root images.
  - php -l is clean on all 9 changed PHP files.
  - 4713097 is an ancestor of main, so the server fast-forwards.
- Watch after deploy: HSTS includeSubDomains (every subdomain must have a valid SSL certificate), and X-Frame-Options SAMEORIGIN (stops other sites from showing olasentra pages inside a frame).

## Update 2026-11-02 00:40: deployed f532306; helper added
- Live: server and public_html at f532306. Checked: www redirect, /aviva-match 200, cron URL 403, homepage canonical/og. The old curl cron now gets 403, so imports are paused at 15/70.
- Roster sheet: 1qLEZsz88k7SapELtn0JV2AbE93nMYdTWl9O5d_EPjPY, tab "Registrations", shared with the service account.
- New command-line-only helper `cron/aviva-match-admin.php` (web access denied in the file and in cron/.htaccess). Commands: setup, list, import (no onboarding), find, onboard-one, cleanup-test.
- `avivaMatchSheetSync($pdo, $max, $onboard = true)`: the cron still onboards; the helper's `import` does not.
- Test plan: register a dummy staff member with a test email you control. Submit the form with a fake PSA number such as EM000000/00 so the not_found path runs. Run `import`, then `onboard-one` twice, then `cleanup-test --delete-staff`. Leave the test row in the sheet: deleting form-response rows shifts the row counter.
