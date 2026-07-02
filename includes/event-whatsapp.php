<?php

declare(strict_types=1);

require_once __DIR__ . '/company.php';
require_once __DIR__ . '/event-whatsapp-schema.php';

function normalizeWhatsappGroupUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https://chat\.whatsapp\.com/[A-Za-z0-9_-]+(?:\?.*)?$#', $url)) {
        return '';
    }

    return preg_replace('#\?.*$#', '', $url);
}

function isValidWhatsappGroupUrl(string $url): bool
{
    return normalizeWhatsappGroupUrl($url) !== '';
}

function getEventWhatsappGroup(PDO $pdo, int $eventId): string
{
    if ($eventId < 1) {
        return getCompanyWhatsappGroup($pdo);
    }

    require_once __DIR__ . '/events-repository.php';
    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return getCompanyWhatsappGroup($pdo);
    }

    $url = normalizeWhatsappGroupUrl((string) ($event['whatsapp_group_url'] ?? ''));

    return $url !== '' ? $url : getCompanyWhatsappGroup($pdo);
}

/**
 * Approved upcoming shifts that have an event-specific WhatsApp group link.
 *
 * @return list<array{event_id: int, event_name: string, event_date: string, whatsapp_group_url: string}>
 */
function getStaffApprovedEventWhatsappGroups(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    ensureEventWhatsappSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT e.id AS event_id, e.name AS event_name, e.event_date, e.whatsapp_group_url
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(TRIM(sr.email)) = :email
               AND sr.status = 'approved'
               AND e.whatsapp_group_url IS NOT NULL
               AND TRIM(e.whatsapp_group_url) <> ''
               AND e.event_date >= CURDATE()
             ORDER BY e.event_date ASC, e.name ASC"
        );
        $stmt->execute(['email' => $email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffApprovedEventWhatsappGroups: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $url = normalizeWhatsappGroupUrl((string) ($row['whatsapp_group_url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $out[] = [
            'event_id'           => (int) ($row['event_id'] ?? 0),
            'event_name'         => (string) ($row['event_name'] ?? 'Event'),
            'event_date'         => (string) ($row['event_date'] ?? ''),
            'whatsapp_group_url' => $url,
        ];
    }

    return $out;
}
