<?php

declare(strict_types=1);

require_once __DIR__ . '/job-record-schema.php';
require_once __DIR__ . '/job-record-repository.php';
require_once __DIR__ . '/staff-roster-download.php';
require_once __DIR__ . '/system-settings.php';

/**
 * @return list<array<string, mixed>>
 */
function listPersonalShiftExportRows(PDO $pdo, array $filters = []): array
{
    ensureJobRecordSchema($pdo);

    $month = trim((string) ($filters['month'] ?? ''));
    $invoiceId = (int) ($filters['invoice_id'] ?? 0);

    $rows = [];

    if ($invoiceId > 0) {
        $record = getJobRecordById($pdo, $invoiceId);
        if ($record !== null && isPersonalJobRecord($record)) {
            $rows = array_merge($rows, personalShiftRowsFromRecord($pdo, $record));
        }

        return $rows;
    }

    $personalFilters = ['invoice_type' => 'personal'];
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $personalFilters['month'] = $month;
    }

    $records = listJobRecords($pdo, $personalFilters, 500);
    foreach ($records as $record) {
        $rows = array_merge($rows, personalShiftRowsFromRecord($pdo, $record));
    }

    usort($rows, static function (array $a, array $b): int {
        $dateCmp = strcmp((string) ($a['job_date_sort'] ?? ''), (string) ($b['job_date_sort'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }

        return strcmp((string) ($a['invoice_number'] ?? ''), (string) ($b['invoice_number'] ?? ''));
    });

    return $rows;
}

/**
 * @param array<string, mixed> $record
 * @return list<array<string, mixed>>
 */
function personalShiftRowsFromRecord(PDO $pdo, array $record): array
{
    $rows = [];
    $invoiceNumber = (string) ($record['invoice_number'] ?? '');
    $status        = (string) ($record['status'] ?? '');
    $recordKind    = (string) ($record['record_kind'] ?? 'invoice');
    $clientName    = (string) ($record['client_name'] ?? '');

    if (isPersonalInvoiceRecord($record)) {
        $lines = getPersonalInvoiceLines($pdo, (int) $record['id']);
        if ($lines === []) {
            $lines = personalInvoiceLinesFromRecord($record);
        }

        foreach ($lines as $line) {
            $rows[] = personalShiftExportRowFromLine($line, $invoiceNumber, $status, $clientName, 'invoiced');
        }

        return $rows;
    }

    if (isPersonalWorkLog($record)) {
        $rows[] = personalShiftExportRowFromLine([
            'description' => (string) ($record['event_name'] ?? ''),
            'job_date'    => (string) ($record['event_date'] ?? ''),
            'venue'       => (string) ($record['venue'] ?? ''),
            'hours'       => (float) ($record['hours_per_staff'] ?? $record['total_hours'] ?? 0),
            'hourly_rate' => (float) ($record['hourly_rate'] ?? 0),
            'line_amount' => (float) ($record['total_amount'] ?? 0),
        ], $invoiceNumber !== '' ? $invoiceNumber : '—', $status, $clientName, 'saved job');

        return $rows;
    }

    return $rows;
}

/**
 * @param array<string, mixed> $line
 * @return array<string, mixed>
 */
function personalShiftExportRowFromLine(
    array $line,
    string $invoiceNumber,
    string $status,
    string $clientName,
    string $recordType
): array {
    $jobDate = trim((string) ($line['job_date'] ?? ''));

    return [
        'job_date'       => $jobDate,
        'job_date_sort'  => $jobDate !== '' ? $jobDate : '9999-12-31',
        'role'           => trim((string) ($line['description'] ?? '')),
        'location'       => trim((string) ($line['venue'] ?? '')),
        'hours'          => round((float) ($line['hours'] ?? 0), 2),
        'hourly_rate'    => round((float) ($line['hourly_rate'] ?? 0), 2),
        'line_amount'    => round((float) ($line['line_amount'] ?? 0), 2),
        'invoice_number' => $invoiceNumber,
        'client_name'    => $clientName,
        'status'         => $status,
        'record_type'    => $recordType,
    ];
}

/**
 * @param list<array<string, mixed>> $shiftRows
 * @return list<list<string>>
 */
function personalShiftRowsToSheetMatrix(PDO $pdo, array $shiftRows): array
{
    $matrix = [];
    $n = 1;

    foreach ($shiftRows as $row) {
        $jobDate = (string) ($row['job_date'] ?? '');
        $matrix[] = [
            (string) $n,
            $jobDate !== '' ? formatSystemDate($jobDate, $pdo) : '—',
            (string) ($row['role'] ?? ''),
            (string) ($row['location'] ?? ''),
            number_format((float) ($row['hours'] ?? 0), 2, '.', ''),
            number_format((float) ($row['hourly_rate'] ?? 0), 2, '.', ''),
            number_format((float) ($row['line_amount'] ?? 0), 2, '.', ''),
            (string) ($row['invoice_number'] ?? ''),
            (string) ($row['client_name'] ?? ''),
            (string) ($row['status'] ?? ''),
            (string) ($row['record_type'] ?? ''),
        ];
        $n++;
    }

    return $matrix;
}

/**
 * @param list<array<string, mixed>> $shiftRows
 */
function sendPersonalShiftsXlsxDownload(PDO $pdo, array $shiftRows, string $basename): void
{
    $headers = [
        '#',
        'Date',
        'Role',
        'Location',
        'Hours',
        'Rate / hour',
        'Amount',
        'Invoice #',
        'Client',
        'Status',
        'Type',
    ];

    $matrix = personalShiftRowsToSheetMatrix($pdo, $shiftRows);
    staffRosterSendXlsxDownload($headers, $matrix, $basename);
}
