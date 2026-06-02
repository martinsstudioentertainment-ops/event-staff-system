-- Phase 33: main security company per event (who staff work for on the shift)
ALTER TABLE events
    ADD COLUMN main_security_company VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Security contractor / employer name shown to staff'
        AFTER name;
