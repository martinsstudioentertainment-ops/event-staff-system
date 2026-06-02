# Google Sheets — live registration sync

When a staff member registers on the website, a new row is appended to **that event’s Google Sheet** (each event has its own sheet URL).

Data is always saved to the **database first**. Google Sheets sync is extra — if it fails, registration still succeeds (errors go to `storage/logs/google-sheets.log`).

---

## One-time setup (Google Cloud)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or use an existing one)
3. **APIs & Services → Library** (with project **event-staff-system-498114** selected in the top bar) → enable **Google Sheets API** and **Google Drive API** — both are required for auto-create. If you only enabled APIs under Credentials, open **Library** and enable them there.
4. **Billing** → link a billing account to the project if Google prompts you (Sheets/Drive are free at normal volume, but new projects sometimes block API calls until billing is linked).
5. **IAM & Admin → Service accounts** → Create service account
6. **Keys → Add key → JSON** — download the file (create a **new** key after APIs are enabled)
7. Admin → **Settings → System → Google Sheets sync** → upload the JSON file
8. Copy the **service account email** (e.g. `event-staff-sheets@event-staff-system-498114.iam.gserviceaccount.com`)

---

## Per event (manual — optional)

1. Create a Google Sheet for the event (or use an existing one)
2. **Share** the sheet with the service account email as **Editor**
3. Admin → **Events → Edit event**
4. Paste **Google Sheet URL** (from browser address bar)
5. Set **Sheet tab name** if not `Sheet1` (bottom tab in Google Sheets)

## Auto-create one sheet per event (recommended for many events)

You do **not** need to create 100 spreadsheets by hand.

1. Complete **One-time setup** (service account JSON uploaded)
2. Admin → **Settings → System → Google Sheets**:
   - Optional: **Share new auto-created sheets with** your Gmail (enable **Google Drive API** in the same Google Cloud project)
   - Optional: change default tab name (default `Registrations`)
3. Admin → **Events** → click **Create N Google Sheet(s)** (only events without a link)
4. The system creates each spreadsheet, adds the payroll header row, saves the URL on the event, and shares with your Gmail if configured
5. Tick **Enable live sync** and save

For 32 summer events this takes about 10–20 seconds. For 100 events, allow a few minutes (Google API rate limits).

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
| Still failing | Admin → **Google Sheets diagnostic** or read `storage/logs/google-sheets.log` |
| HTTP 403 PERMISSION_DENIED on **create** | APIs not enabled on the JSON `project_id`, or billing not linked — see steps 3–4 above; wait 5 min after enabling |
| HTTP 403 SERVICE_DISABLED | Enable APIs on the **same** project as `project_id` in your JSON (not a different GCP project) |
| HTTP 0 / curl error | Hosting blocks outbound HTTPS to `googleapis.com` — ask Namecheap to allow it |
| Token error / invalid_grant | Re-upload a **new** JSON key; old key may have been deleted in Google Cloud |

---

## Security

- Keep `storage/google/service-account.json` private (not in git)
- Service account only needs access to sheets you share with it
- Full IBAN and PPS are written to the sheet (same as CSV export for payroll)
