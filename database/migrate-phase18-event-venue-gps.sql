-- Phase 18: Event venue GPS for geofenced sign-in

ALTER TABLE events
    ADD COLUMN venue_lat DECIMAL(10, 7) NULL DEFAULT NULL AFTER location,
    ADD COLUMN venue_lng DECIMAL(10, 7) NULL DEFAULT NULL AFTER venue_lat,
    ADD COLUMN signin_radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 200 AFTER venue_lng;
