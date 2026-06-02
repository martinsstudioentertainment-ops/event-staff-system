-- Phase 21: staff capacity per event
ALTER TABLE events
    ADD COLUMN staff_needed INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Target headcount for this event/location; NULL = no limit'
        AFTER signin_radius_m;
