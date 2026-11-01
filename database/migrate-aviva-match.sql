-- Additive only. Run once (or let avivaMatchEnsureSchema() create the table).
CREATE TABLE IF NOT EXISTS aviva_match_signups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  mobile VARCHAR(40) NOT NULL DEFAULT '',
  full_name VARCHAR(190) NOT NULL DEFAULT '',
  form_response_id VARCHAR(120) NOT NULL DEFAULT '',
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aviva_match_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Settings (set values in admin or via SQL; do NOT commit the key):
-- aviva_match_date            = 'YYYY-MM-DD'   (placeholder until confirmed)
-- google_form_aviva_match_url = 'https://docs.google.com/forms/d/e/.../viewform'
-- aviva_match_intake_key      = '<random 32+ chars, also pasted into Apps Script property>'
-- Test cleanup: DELETE FROM aviva_match_signups WHERE is_test = 1;
