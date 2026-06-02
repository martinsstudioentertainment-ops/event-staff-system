-- Optional: set Drive folder for auto-created sheets (run in phpMyAdmin if Settings save fails).
-- Replace the value with your folder ID from Google Drive URL (.../folders/THIS_ID).

INSERT INTO system_settings (setting_key, setting_value) VALUES
('google_sheets_drive_folder_id', '1yMRBJoz4nA7MVIopBbiv9qcpeB1zBjqW')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
