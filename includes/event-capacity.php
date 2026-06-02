<?php

require_once __DIR__ . '/settings-repository.php';

/**
 * Default headcount when an event has no Staff needed value (Admin → Events).
 */
function getDefaultEventStaffNeeded(PDO $pdo): ?int
{
    $raw = trim((string) getSetting($pdo, 'default_event_staff_needed', '30'));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : null;
}

/**
 * Resolved target headcount for display and capacity (DB value, else system default).
 */
function resolveEventStaffNeeded(array $event, ?PDO $pdo = null): ?int
{
    $raw = $event['staff_needed'] ?? null;
    if ($raw !== null && $raw !== '') {
        return max(0, (int) $raw);
    }

    if ($pdo instanceof PDO) {
        return getDefaultEventStaffNeeded($pdo);
    }

    return null;
}
