<?php

declare(strict_types=1);

/**
 * Verify attendance BIB + billable status for Nikhil on June events.
 * GET: ?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $stmt = $pdo->prepare(
        "SELECT e.id AS event_id, e.name, e.event_date,
                sr.id AS registration_id, sr.email, sr.assigned_bib_number,
                a.id AS attendance_id, a.bib_number, a.checked_in_at, a.checked_in_method,
                a.hours_worked, a.hours_paid, a.attendance_status,
                ci.id AS invoice_id, ci.invoice_number
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         LEFT JOIN commission_invoices ci ON ci.event_id = e.id
         WHERE sr.email = 'nicks.ks88@gmail.com'
           AND e.id IN (6, 7)
         ORDER BY e.event_date ASC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = [];
    foreach ($rows as $row) {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $line = null;
        if ($invoiceId > 0 && (int) ($row['attendance_id'] ?? 0) > 0) {
            $lineStmt = $pdo->prepare(
                'SELECT staff_name, hours_billed, line_amount FROM commission_invoice_lines
                 WHERE invoice_id = :invoice_id AND attendance_id = :attendance_id LIMIT 1'
            );
            $lineStmt->execute([
                'invoice_id'    => $invoiceId,
                'attendance_id' => (int) $row['attendance_id'],
            ]);
            $line = $lineStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $lines[] = [
            'event'           => (string) ($row['name'] ?? ''),
            'event_date'      => (string) ($row['event_date'] ?? ''),
            'registration_id' => (int) ($row['registration_id'] ?? 0),
            'attendance_id'   => (int) ($row['attendance_id'] ?? 0),
            'attendance_bib'  => (string) ($row['bib_number'] ?? ''),
            'assigned_bib'    => (string) ($row['assigned_bib_number'] ?? ''),
            'checked_in'      => trim((string) ($row['checked_in_at'] ?? '')) !== '',
            'sign_in_method'  => (string) ($row['checked_in_method'] ?? ''),
            'hours_paid'      => $row['hours_paid'],
            'invoice_number'  => (string) ($row['invoice_number'] ?? ''),
            'on_invoice'      => $line !== null,
            'invoice_hours'   => $line['hours_billed'] ?? null,
        ];
    }

    echo json_encode(['ok' => true, 'nikhil_june_events' => $lines], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
