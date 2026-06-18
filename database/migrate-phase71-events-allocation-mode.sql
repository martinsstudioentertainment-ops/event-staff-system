-- Add allocation_mode to events if missing (Phase 1 foundation).

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS allocation_mode ENUM('first_come', 'manager_approval', 'auto_availability')
        NOT NULL DEFAULT 'first_come'
        AFTER is_active;
