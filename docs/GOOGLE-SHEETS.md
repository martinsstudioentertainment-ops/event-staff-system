# Google Sheets — live registration sync

When a staff member registers on the website, a new row is appended to **that event’s Google Sheet** (each event has its own sheet URL).

Data is always saved to the **database first**. Google Sheets sync is extra — if it fails, registration still succeeds (errors go to `storage/logs/google-sheets.log`).

---

## One-time setup (Google Cloud)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or use an existing one)
3. **APIs & Services → Enable APIs** → enable **Google Sheets API**
4. **IAM & Admin → Service accounts** → Create service account
5. **Keys → Add key → JSON** — download the file
6. Admin → **Settings → System → Google Sheets sync** → upload the JSON file
7. Copy the **service account email** (e.g. `event-staff@my-project.iam.gserviceaccount.com`)

---

## Per event

1. Create a Google Sheet for the event (or use an existing one)
2. **Share** the sheet with the service account email as **Editor**
3. Admin → **Events → Edit event**
4. Paste **Google Sheet URL** (from browser address bar)
5. Set **Sheet tab name** if not `Sheet1` (bottom tab in Google Sheets)

---

## Row columns (same as Admin → Export Staff CSV)

Employee payroll columns only (10):

| Column | Content |
|--------|---------|
| Surname, First Name, Full Address | Registration form |
| Postcode | Eircode from the form |
| Email, Mobile Number | Registration form |
| Date Of Birth, Gender | Registration form |
| National Insurance/PPS | Full PPS (not last-4) |
| Bank Account/IBAN | Full IBAN |

Role, event, status, GPS, and timestamps are **not** written to the sheet.

If the sheet is **empty**, this header row is added automatically on first sync.

---

## Enable sync

Admin → **Settings → System** → tick **Enable live sync to Google Sheets** → Save.

Without this, sheet URLs on events are ignored.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Row not appearing | Check sync is enabled, event has sheet URL, service account uploaded |
| Permission denied | Share sheet with service account email as Editor |
| Wrong tab | Set correct **Sheet tab name** on the event |
| Still failing | Read `storage/logs/google-sheets.log` |

---

## Security

- Keep `storage/google/service-account.json` private (not in git)
- Service account only needs access to sheets you share with it
- Full IBAN and PPS are written to the sheet (same as CSV export for payroll)
