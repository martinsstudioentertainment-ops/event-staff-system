-- Phase 30: Allow staff_role values beyond ENUM (e.g. both form → stored as dsp/static per event)

ALTER TABLE staff_registrations
    MODIFY staff_role VARCHAR(32) NOT NULL DEFAULT 'dsp';
