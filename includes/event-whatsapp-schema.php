<?php

declare(strict_types=1);

/** Ensure per-event WhatsApp group invite link column exists. */
function ensureEventWhatsappSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!in_array('whatsapp_group_url', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE events ADD COLUMN whatsapp_group_url VARCHAR(512) NULL DEFAULT NULL');
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready[$key] = true;
}
