-- Rollback Operations Automation Batch 2 (Modules 11–20)
-- Non-destructive to core ERP data. Drops Batch 2 tables only.

ALTER TABLE staff_registrations DROP COLUMN IF EXISTS shift_response;

ALTER TABLE events DROP COLUMN IF EXISTS client_id;

DROP TABLE IF EXISTS ops_automation_log;
DROP TABLE IF EXISTS client_contracts;
DROP TABLE IF EXISTS staff_contracts;
DROP TABLE IF EXISTS client_contacts;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS staff_incidents;
DROP TABLE IF EXISTS comms_campaigns;
DROP TABLE IF EXISTS staff_rate_cards;
DROP TABLE IF EXISTS staff_training_records;
DROP TABLE IF EXISTS recruitment_pipeline;
DROP TABLE IF EXISTS event_roster_assignments;
DROP TABLE IF EXISTS event_roster_slots;
