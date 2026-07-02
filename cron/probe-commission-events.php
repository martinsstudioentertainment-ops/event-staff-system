<?php

declare(strict_types=1);

/**
 * Read-only commission analysis for specific events.
 *
 *   ?key=CRON_KEY&event_ids=8,10
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $idsParam = trim((string) ($_GET['event_ids'] ?? '8,10'));
    $eventIds = array_values(array_filter(array_map('intval', explode(',', $idsParam)), static fn(int $id): bool => $id > 0));
    if ($eventIds === []) {
        exit(json_encode(['ok' => false, 'error' => 'event_ids required'], JSON_PRETTY_PRINT));
    }

    $reports = [];

    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);

        $regStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid');
        $regStmt->execute(['eid' => $eventId]);
        $regCount = (int) $regStmt->fetchColumn();

        $attRows = $pdo->query(
            "SELECT a.id AS attendance_id, a.registration_id, a.hours_worked, a.hours_paid,
                    a.checked_in_at, a.checked_in_method, a.attendance_status, a.activated_at,
                    sr.first_name, sr.surname, sr.email, sr.status AS reg_status, sr.staff_id
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE a.event_id = {$eventId}
             ORDER BY a.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $workHours = getWorkHoursList($pdo, $eventId);
        $billable  = [];
        $nonBillable = [];
        foreach ($workHours as $row) {
            $entry = [
                'attendance_id'   => (int) ($row['attendance_id'] ?? 0),
                'registration_id' => (int) ($row['registration_id'] ?? 0),
                'staff'           => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'hours_worked'    => (float) ($row['hours_worked'] ?? 0),
                'hours_paid'      => (float) ($row['hours_paid'] ?? 0),
                'attendance_status' => (string) ($row['attendance_status'] ?? ''),
                'checked_in_at'   => (string) ($row['checked_in_at'] ?? ''),
                'checked_in_method' => (string) ($row['checked_in_method'] ?? ''),
            ];
            if (attendanceRowBillableForCommissionInvoice($row)) {
                $billable[] = $entry;
            } else {
                $nonBillable[] = $entry;
            }
        }

        $previewLines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
        $invoice      = getCommissionInvoiceByEventId($pdo, $eventId);
        $savedLines   = $invoice ? getCommissionInvoiceLines($pdo, (int) $invoice['id']) : [];

        $savedAttIds = array_map(
            static fn(array $l): int => (int) ($l['attendance_id'] ?? 0),
            $savedLines
        );

        $missingFromInvoice = [];
        foreach ($billable as $b) {
            $attId = (int) $b['attendance_id'];
            if ($invoice === null || !in_array($attId, $savedAttIds, true)) {
                $missingFromInvoice[] = $b;
            }
        }

        $totalsPreview = $previewLines !== [] ? recomputeCommissionInvoiceTotals($previewLines) : null;

        $reports[] = [
            'event_id' => $eventId,
            'event' => $event ? [
                'name'       => (string) ($event['name'] ?? ''),
                'event_date' => (string) ($event['event_date'] ?? ''),
                'start_time' => (string) ($event['start_time'] ?? ''),
                'end_time'   => (string) ($event['end_time'] ?? ''),
                'location'   => (string) ($event['location'] ?? ''),
            ] : null,
            'registrations_count' => $regCount,
            'attendance_count'    => count($attRows),
            'attendance_rows'     => $attRows,
            'billable_count'      => count($billable),
            'billable_rows'       => $billable,
            'non_billable_count'  => count($nonBillable),
            'non_billable_rows'   => $nonBillable,
            'commission_invoice'  => $invoice ? [
                'id'             => (int) $invoice['id'],
                'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
                'status'         => (string) ($invoice['status'] ?? ''),
                'staff_count'    => (int) ($invoice['staff_count'] ?? 0),
                'total_hours_billed' => (float) ($invoice['total_hours_billed'] ?? 0),
                'total_amount'   => (float) ($invoice['total_amount'] ?? 0),
                'line_count'     => count($savedLines),
            ] : null,
            'diagnosis' => $invoice === null
                ? 'no_commission_invoice_for_event'
                : (count($missingFromInvoice) > 0 ? 'invoice_exists_missing_lines' : 'invoice_complete'),
            'missing_from_invoice' => $missingFromInvoice,
            'preview_lines' => array_map(static fn(array $l): array => [
                'attendance_id'   => (int) ($l['attendance_id'] ?? 0),
                'registration_id' => (int) ($l['registration_id'] ?? 0),
                'staff_name'      => (string) ($l['staff_name'] ?? ''),
                'hours_billed'    => (float) ($l['hours_billed'] ?? 0),
                'hourly_rate'     => (float) ($l['hourly_rate'] ?? 0),
                'line_amount'     => (float) ($l['line_amount'] ?? 0),
            ], $previewLines),
            'preview_totals' => $totalsPreview,
            'recommended_action' => match (true) {
                $previewLines === [] => 'none_no_billable_attendance',
                $invoice === null   => 'create_draft_invoice_via_saveCommissionInvoice',
                count($missingFromInvoice) > 0 => 'rebuild_existing_invoice_lines',
                default => 'none_invoice_already_complete',
            },
        ];
    }

    echo json_encode([
        'ok'      => true,
        'events'  => $reports,
        'protected_modules_touched' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
