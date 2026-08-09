-- Per-event WhatsApp group invite link (https://chat.whatsapp.com/…)
ALTER TABLE events
    ADD COLUMN whatsapp_group_url VARCHAR(512) NULL DEFAULT NULL;
