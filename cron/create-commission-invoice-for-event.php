<?php

declare(strict_types=1);

/**
 * Create or rebuild commission invoice for events with billable attendance.
 *
 * Creates draft invoice only when none exists; rebuilds lines when invoice exists.
 * Does NOT modify attendance, payroll, registrations, or identity.
 *
 *   ?key=CRON_KEY&event_id=8&dry_run=1
 *   ?key=CRON_KEY&event_id=8&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';

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

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';
    $event  = getEventById($pdo, $eventId);
    if ($event === null) {
        exit(json_encode(['ok' => false, 'error' => 'Event not found'], JSON_PRETTY_PRINT));
    }

    $lines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
    if ($lines === []) {
        exit(json_encode([
            'ok' => false,
            'error' => 'No billable attendance for event',
            'event_id' => $eventId,
        ], JSON_PRETTY_PRINT));
    }

    $existing = getCommissionInvoiceByEventId($pdo, $eventId);
    $totals   = recomputeCommissionInvoiceTotals($lines);
    $eventDate = (string) ($event['event_date'] ?? date('Y-m-d'));

    $preview = [
        'event_id'    => $eventId,
        'event_name'  => (string) ($event['name'] ?? ''),
        'action'      => $existing ? 'rebuild_existing' : 'create_draft',
        'existing_invoice_id' => $existing ? (int) $existing['id'] : null,
        'line_count'  => count($lines),
        'totals'      => $totals,
        'staff_names' => array_map(static fn(array $l): string => (string) ($l['staff_name'] ?? ''), $lines),
        'attendance_ids' => array_map(static fn(array $l): int => (int) ($l['attendance_id'] ?? 0), $lines),
    ];

    if (!$apply) {
        echo json_encode(['ok' => true, 'dry_run' => true, 'preview' => $preview], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing) {
        $result = rebuildCommissionInvoiceLinesFromEvent($pdo, (int) $existing['id'], 0);
        if (!is_int($result)) {
            exit(json_encode(['ok' => false, 'error' => (string) $result, 'preview' => $preview], JSON_PRETTY_PRINT));
        }
        $invoiceId = $result;
        $action    = 'rebuilt';
    } else {
        $save = saveCommissionInvoice($pdo, $eventId, [
            'invoice_date' => $eventDate,
            'client_name'  => (string) ($event['name'] ?? ''),
            'status'       => 'draft',
            'notes'        => sprintf(
                'Auto-created from billable attendance — %s (%s)',
                (string) ($event['name'] ?? ''),
                $eventDate
            ),
        ], $lines, 0, null);

        if (!is_int($save)) {
            exit(json_encode(['ok' => false, 'error' => (string) $save, 'preview' => $preview], JSON_PRETTY_PRINT));
        }
        $invoiceId = $save;
        $action    = 'created';
    }

    $invoice = getCommissionInvoiceById($pdo, $invoiceId);
    $saved   = getCommissionInvoiceLines($pdo, $invoiceId);
    $savedTotals = recomputeCommissionInvoiceTotals($saved);

    $attIds = array_map(static fn(array $l): int => (int) ($l['attendance_id'] ?? 0), $saved);
    $dupCheck = count($attIds) !== count(array_unique(array_filter($attIds)));

    echo json_encode([
        'ok' => true,
        'dry_run' => false,
        'action' => $action,
        'event_id' => $eventId,
        'invoice_id' => $invoiceId,
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'status' => (string) ($invoice['status'] ?? ''),
        'line_count' => count($saved),
        'totals' => $savedTotals,
        'duplicate_attendance_lines' => $dupCheck,
        'staff_lines' => array_map(static fn(array $l): array => [
            'attendance_id' => (int) ($l['attendance_id'] ?? 0),
            'staff_name'    => (string) ($l['staff_name'] ?? ''),
            'hours_billed'  => (float) ($l['hours_billed'] ?? 0),
            'line_amount'   => (float) ($l['line_amount'] ?? 0),
        ], $saved),
        'admin_url' => 'https://admin.olasentra.com/admin/invoice-form.php?id=' . $invoiceId,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
