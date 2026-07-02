<?php

declare(strict_types=1);

/**
 * Compare saved commission invoice lines vs billable check-ins for an event.
 * GET: ?key=...&event_id=38
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $eventId = (int) ($_GET['event_id'] ?? 0);
    if ($eventId < 1) {
        exit(json_encode(['ok' => false, 'error' => 'event_id required'], JSON_PRETTY_PRINT));
    }

    $event   = getEventById($pdo, $eventId);
    $invoice = getCommissionInvoiceByEventId($pdo, $eventId);
    $stats   = getAttendanceStats($pdo, $eventId);

    $savedLines    = $invoice ? getCommissionInvoiceLines($pdo, (int) $invoice['id']) : [];
    $billableLines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
    $billableRows  = getWorkHoursList($pdo, $eventId);

    $checkedIn = [];
    $noShow    = [];
    foreach ($billableRows as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
        $entry = [
            'name'          => $name,
            'attendance_id' => (int) ($row['attendance_id'] ?? 0),
            'status'        => $status,
            'checked_in_at' => (string) ($row['checked_in_at'] ?? ''),
            'bib'           => (string) ($row['bib_number'] ?? ''),
        ];
        if ($status === 'no_show') {
            $noShow[] = $entry;
        } elseif (trim((string) ($row['checked_in_at'] ?? '')) !== '' || trim((string) ($row['activated_at'] ?? '')) !== '') {
            $checkedIn[] = $entry;
        }
    }

    $savedNames    = array_map(static fn(array $l): string => (string) ($l['staff_name'] ?? ''), $savedLines);
    $billableNames = array_map(static fn(array $l): string => (string) ($l['staff_name'] ?? ''), $billableLines);

    $onInvoiceNotBillable = array_values(array_diff($savedNames, $billableNames));
    $billableNotOnInvoice = array_values(array_diff($billableNames, $savedNames));

    echo json_encode([
        'ok' => true,
        'event' => $event ? [
            'id'         => (int) $event['id'],
            'name'       => (string) ($event['name'] ?? ''),
            'event_date' => (string) ($event['event_date'] ?? ''),
        ] : null,
        'attendance_stats' => $stats,
        'invoice' => $invoice ? [
            'id'             => (int) $invoice['id'],
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'staff_count_field' => (int) ($invoice['staff_count'] ?? 0),
            'saved_line_count'  => count($savedLines),
        ] : null,
        'counts' => [
            'roster_approved'    => (int) ($stats['approved'] ?? 0),
            'checked_in_display' => (int) ($stats['checked_in'] ?? 0),
            'no_show'            => (int) ($stats['no_show'] ?? 0),
            'billable_for_invoice' => count($billableLines),
            'saved_invoice_lines'  => count($savedLines),
        ],
        'saved_invoice_lines' => array_map(static fn(array $l): array => [
            'staff_name'    => (string) ($l['staff_name'] ?? ''),
            'attendance_id' => (int) ($l['attendance_id'] ?? 0),
            'hours_billed'  => (float) ($l['hours_billed'] ?? 0),
        ], $savedLines),
        'billable_check_ins' => array_map(static fn(array $l): array => [
            'staff_name'    => (string) ($l['staff_name'] ?? ''),
            'attendance_id' => (int) ($l['attendance_id'] ?? 0),
            'hours_billed'  => (float) ($l['hours_billed'] ?? 0),
        ], $billableLines),
        'no_shows' => $noShow,
        'on_invoice_not_billable' => $onInvoiceNotBillable,
        'billable_not_on_invoice' => $billableNotOnInvoice,
        'diagnosis' => match (true) {
            count($savedLines) > count($billableLines) => 'invoice_has_extra_lines_reload_recommended',
            count($savedLines) < count($billableLines) => 'invoice_missing_lines_reload_recommended',
            default => 'invoice_matches_check_ins',
        },
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
