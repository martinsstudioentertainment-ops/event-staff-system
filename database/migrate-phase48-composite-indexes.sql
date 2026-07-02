-- Phase 48: Composite indexes for ops queries (Command Center, staff queue, event calendar)
-- Idempotent: skips if index already exists (runner treats duplicate as skip)

-- staff_registrations: filter by event + status (staff.php, dashboard, event hub)
ALTER TABLE staff_registrations
    ADD INDEX idx_staff_reg_event_status (event_id, status);

-- events: active upcoming events (getUpcomingEventsSummary, event timeline)
ALTER TABLE events
    ADD INDEX idx_events_active_date (is_active, event_date);

-- staff: directory email lookups (avoid LOWER(email) full scan when combined with app normalization)
ALTER TABLE staff
    ADD INDEX idx_staff_email_lower (email);

-- app_notifications: inbox filters by audience + read state + time
ALTER TABLE app_notifications
    ADD INDEX idx_notif_admin_feed (audience, is_read, created_at);
