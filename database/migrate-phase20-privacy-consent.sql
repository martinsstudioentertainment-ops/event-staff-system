-- Phase 20: privacy consent audit on registration

ALTER TABLE staff_registrations
    ADD COLUMN privacy_consented_at TIMESTAMP NULL DEFAULT NULL AFTER status_token;
