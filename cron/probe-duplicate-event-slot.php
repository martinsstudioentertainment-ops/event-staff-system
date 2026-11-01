<?php

declare(strict_types=1);

/**
 * Read-only analysis: compare two events (duplicate slot investigation).
 *
 *   ?key=CRON_KEY&event_ids=8,38
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $idsParam = trim((string) ($_GET['event_ids'] ?? '8,38'));
    $eventIds = array_values(array_filter(array_map('intval', explode(',', $idsParam)), static fn(int $id): bool => $id > 0));
    if (count($eventIds) < 2) {
        exit(json_encode(['ok' => false, 'error' => 'Need at least 2 event_ids'], JSON_PRETTY_PRINT));
    }

    $events = [];
    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);
        $regCount = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations WHERE event_id = ' . $eventId)->fetchColumn();
        $attCount = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE event_id = ' . $eventId)->fetchColumn();
        $invoice  = getCommissionInvoiceByEventId($pdo, $eventId);

        $staffEmails = $pdo->query(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email FROM staff_registrations WHERE event_id = {$eventId} AND TRIM(email) <> ''"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $events[$eventId] = [
            'event' => $event,
            'registrations' => $regCount,
            'attendance' => $attCount,
            'commission_invoice' => $invoice ? [
                'id' => (int) $invoice['id'],
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'staff_count' => (int) ($invoice['staff_count'] ?? 0),
                'total_amount' => (float) ($invoice['total_amount'] ?? 0),
            ] : null,
            'staff_emails' => $staffEmails,
        ];
    }

    $idA = $eventIds[0];
    $idB = $eventIds[1];
    $emailsA = array_flip($events[$idA]['staff_emails'] ?? []);
    $emailsB = array_flip($events[$idB]['staff_emails'] ?? []);
    $sharedEmails = array_values(array_intersect(array_keys($emailsA), array_keys($emailsB)));

    $sharedStaff = [];
    if ($sharedEmails !== []) {
        $placeholders = implode(',', array_fill(0, count($sharedEmails), '?'));
        $stmt = $pdo->prepare(
            "SELECT sr.event_id, sr.id AS registration_id, sr.email, sr.first_name, sr.surname, sr.status,
                    a.id AS attendance_id, a.hours_worked
             FROM staff_registrations sr
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.event_id IN ({$idA}, {$idB})
               AND LOWER(TRIM(sr.email)) IN ({$placeholders})
             ORDER BY sr.email, sr.event_id"
        );
        $stmt->execute($sharedEmails);
        $sharedStaff = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $evtA = $events[$idA]['event'] ?? [];
    $evtB = $events[$idB]['event'] ?? [];
    $sameSlot = ($evtA['event_date'] ?? '') === ($evtB['event_date'] ?? '')
        && ($evtA['start_time'] ?? '') === ($evtB['start_time'] ?? '')
        && ($evtA['end_time'] ?? '') === ($evtB['end_time'] ?? '')
        && strtolower(trim((string) ($evtA['location'] ?? ''))) === strtolower(trim((string) ($evtB['location'] ?? '')));

    $sameName = strtolower(trim((string) ($evtA['name'] ?? ''))) === strtolower(trim((string) ($evtB['name'] ?? '')));

    echo json_encode([
        'ok' => true,
        'events' => $events,
        'comparison' => [
            'same_venue_slot' => $sameSlot,
            'same_event_name' => $sameName,
            'genuinely_separate_events' => $sameSlot && !$sameName,
            'shared_staff_email_count' => count($sharedEmails),
            'shared_staff_emails' => $sharedEmails,
            'shared_staff_detail' => $sharedStaff,
        ],
        'merge_risk' => [
            'attendance_would_combine' => ($events[$idA]['attendance'] ?? 0) + ($events[$idB]['attendance'] ?? 0),
            'commission_invoices' => array_filter([
                $idA => $events[$idA]['commission_invoice'] ?? null,
                $idB => $events[$idB]['commission_invoice'] ?? null,
            ]),
            'recommendation' => $sameSlot && !$sameName
                ? 'KEEP_BOTH — same venue/time but different concerts; do not merge without client confirmation'
                : ($sameName ? 'INVESTIGATE_DUPLICATE_RECORD' : 'NO_ACTION'),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
