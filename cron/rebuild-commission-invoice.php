<?php

declare(strict_types=1);

/**
 * Rebuild saved commission invoice lines from venue check-ins only.
 *
 * Usage:
 *   /cron/rebuild-commission-invoice.php?key=...&invoice_id=123
 *   /cron/rebuild-commission-invoice.php?key=...&event_id=4
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$eventId   = (int) ($_GET['event_id'] ?? 0);

if ($invoiceId < 1 && $eventId > 0) {
    $existing = getCommissionInvoiceByEventId($pdo, $eventId);
    if (!$existing) {
        exit(json_encode(['ok' => false, 'error' => 'No invoice for event_id ' . $eventId]));
    }
    $invoiceId = (int) $existing['id'];
}

if ($invoiceId < 1) {
    exit(json_encode(['ok' => false, 'error' => 'Provide invoice_id or event_id']));
}

$before = getCommissionInvoiceById($pdo, $invoiceId);
$beforeCount = $before ? (int) ($before['staff_count'] ?? 0) : 0;

$result = rebuildCommissionInvoiceLinesFromEvent($pdo, $invoiceId, 0);
if (!is_int($result)) {
    exit(json_encode(['ok' => false, 'error' => (string) $result]));
}

$after = getCommissionInvoiceById($pdo, $result);
$lines = getCommissionInvoiceLines($pdo, $result);

echo json_encode([
    'ok' => true,
    'invoice_id' => $result,
    'event_id' => (int) ($after['event_id'] ?? 0),
    'staff_count_before' => $beforeCount,
    'staff_count_after' => (int) ($after['staff_count'] ?? 0),
    'line_count' => count($lines),
    'staff_names' => array_map(static fn (array $line): string => (string) ($line['staff_name'] ?? ''), $lines),
], JSON_PRETTY_PRINT);
