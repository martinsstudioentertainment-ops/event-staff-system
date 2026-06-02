-- Phase 32: control which events show shift times on staff registration
ALTER TABLE events
    ADD COLUMN times_confirmed TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = show start/end times on registration form'
        AFTER end_time;
