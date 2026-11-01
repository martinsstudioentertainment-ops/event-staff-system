-- Optional: tables/columns are also auto-created on first cron run. Additive only.
CREATE TABLE IF NOT EXISTS aviva_match_sheet_rows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sheet_row INT UNSIGNED NOT NULL,
  submitted_at VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  mobile VARCHAR(40) NOT NULL DEFAULT '',
  full_name VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  staff_id INT UNSIGNED NULL,
  written_back TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aviva_match_sheet_row (sheet_row)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO system_settings (setting_key, setting_value) VALUES
  ('google_form_aviva_match_url', 'https://docs.google.com/forms/d/e/1FAIpQLSdE_ZTal9HKrAdVh2ZY3H2R5Hd-L4NFK1VhAzCWpUK3cbkmFg/viewform'),
  ('aviva_match_sheet_id', '1WQpUucBP2PVwLX1x3dLNI_qrpM0wiMcsJwesq3ev9ok'),
  ('aviva_match_sheet_rows', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
-- Test cleanup (replace with your test email):
-- DELETE FROM aviva_match_signups   WHERE email = 'TEST_EMAIL' OR form_email = 'TEST_EMAIL';
-- DELETE FROM aviva_match_sheet_rows WHERE email = 'TEST_EMAIL';
-- (keep aviva_match_sheet_rows setting as-is so old sheet rows are not re-imported; also delete the test rows in the Google Sheet only if you then reset the counter)
