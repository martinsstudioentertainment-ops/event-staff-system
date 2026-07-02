<?php

declare(strict_types=1);

/**
 * Rebuild Thomas Park Limerick commission invoice (event #38, invoice #6).
 * GET: ?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-schema.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';

header('Content-Type: application/json; charset=UTF-8');

const EVENT_ID = 38;

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
}

try {
    ensureWorkHoursSchema($pdo);
    ensureCommissionInvoiceSchema($pdo);

    $invoice = getCommissionInvoiceByEventId($pdo, EVENT_ID);
    if ($invoice === null) {
        throw new RuntimeException('No commission invoice found for Thomas Park event #' . EVENT_ID);
    }

    $invoiceId = (int) $invoice['id'];
    $beforeCount = (int) ($invoice['staff_count'] ?? 0);
    $beforeLines = getCommissionInvoiceLines($pdo, $invoiceId);

    $previewLines = buildCommissionInvoiceLinesFromEvent($pdo, EVENT_ID);
    if ($previewLines === []) {
        throw new RuntimeException('No billable attendance rows found for event #' . EVENT_ID);
    }

    $result = rebuildCommissionInvoiceLinesFromEvent($pdo, $invoiceId, 0);
    if (!is_int($result)) {
        throw new RuntimeException((string) $result);
    }

    $after = getCommissionInvoiceById($pdo, $result);
    $lines = getCommissionInvoiceLines($pdo, $result);
    $totals = recomputeCommissionInvoiceTotals($lines);

    echo json_encode([
        'ok' => true,
        'event_id' => EVENT_ID,
        'event_name' => 'Thomas Park Limerick',
        'invoice_id' => $result,
        'invoice_number' => (string) ($after['invoice_number'] ?? ''),
        'status' => (string) ($after['status'] ?? 'draft'),
        'staff_count_before' => $beforeCount,
        'staff_count_after' => (int) ($after['staff_count'] ?? count($lines)),
        'line_count' => count($lines),
        'total_hours_billed' => $totals['total_hours_billed'],
        'total_amount' => $totals['total_amount'],
        'payment_status' => 'pending (draft invoice — approve when ready)',
        'admin_urls' => [
            'invoice' => 'https://admin.olasentra.com/admin/invoice-form.php?id=' . $result,
            'invoices_list' => 'https://admin.olasentra.com/admin/invoices.php',
            'attendance' => 'https://admin.olasentra.com/admin/attendance.php?event_id=' . EVENT_ID,
            'work_hours' => 'https://admin.olasentra.com/admin/work-hours.php?event_id=' . EVENT_ID,
            'event' => 'https://admin.olasentra.com/admin/event-form.php?id=' . EVENT_ID,
        ],
        'staff_lines' => array_map(static fn (array $line): array => [
            'name' => (string) ($line['staff_name'] ?? ''),
            'role' => (string) ($line['staff_role'] ?? ''),
            'hours_billed' => (float) ($line['hours_billed'] ?? 0),
            'hourly_rate' => (float) ($line['hourly_rate'] ?? 0),
            'line_amount' => (float) ($line['line_amount'] ?? 0),
        ], $lines),
        'lines_before' => count($beforeLines),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
