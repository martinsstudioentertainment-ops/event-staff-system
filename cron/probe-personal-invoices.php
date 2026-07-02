<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/job-record-repository.php';
require_once dirname(__DIR__) . '/includes/job-record-schema.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    ensureJobRecordSchema($pdo);

    $personal = listJobRecords($pdo, ['invoice_type' => 'personal'], 100);
    $commission = listJobRecords($pdo, ['invoice_type' => 'staff_commission'], 20);

    $lines = [];
    foreach ($personal as $row) {
        $invoiceLines = [];
        if (isPersonalInvoiceRecord($row)) {
            $invoiceLines = getPersonalInvoiceLines($pdo, (int) $row['id']);
        }
        $lines[] = [
            'id'             => (int) $row['id'],
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'record_kind'    => (string) ($row['record_kind'] ?? ''),
            'event_name'     => (string) ($row['event_name'] ?? ''),
            'event_date'     => (string) ($row['event_date'] ?? ''),
            'venue'          => (string) ($row['venue'] ?? ''),
            'hours'          => (float) ($row['total_hours'] ?? 0),
            'status'         => (string) ($row['status'] ?? ''),
            'line_count'     => count($invoiceLines),
            'lines'          => $invoiceLines,
        ];
    }

    echo json_encode([
        'ok'                => true,
        'personal_count'    => count($personal),
        'staff_job_count'   => count($commission),
        'personal_records'  => $lines,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
