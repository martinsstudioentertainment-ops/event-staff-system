-- Phase 19: Event venue Eircode + 100m sign-in radius

ALTER TABLE events
    ADD COLUMN venue_eircode VARCHAR(8) NULL DEFAULT NULL AFTER location;

ALTER TABLE events
    MODIFY COLUMN signin_radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 100;

UPDATE events SET signin_radius_m = 100 WHERE signin_radius_m > 100 OR signin_radius_m < 100;
