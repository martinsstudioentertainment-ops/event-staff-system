-- Phase 31: Google Sheets URL per event (live registration sync)
-- Safe re-run: schema also applied via ensureGoogleSheetsSchema()

ALTER TABLE events ADD COLUMN google_sheet_url VARCHAR(512) NULL DEFAULT NULL;
ALTER TABLE events ADD COLUMN google_sheet_tab VARCHAR(100) NULL DEFAULT NULL;
