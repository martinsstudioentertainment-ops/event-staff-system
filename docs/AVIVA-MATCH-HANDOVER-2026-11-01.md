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
