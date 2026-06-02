<?php

require_once __DIR__ . '/commission-invoice-repository.php';
require_once __DIR__ . '/events-repository.php';

/**
 * @return list<string>
 */
function getCommissionInvoiceImportTemplateHeaders(string $mode): array
{
    if ($mode === 'summary') {
        return [
            'event_name',
            'event_date',
            'venue',
            'invoice_date',
            'client_name',
            'staff_count',
            'hours_per_lad',
            'hourly_rate',
            'notes',
        ];
    }

    return [
        'event_name',
        'event_date',
        'venue',
        'invoice_date',
        'client_name',
        'staff_name',
        'role',
        'hours_worked',
        'hours_billed',
        'hourly_rate',
        'line_amount',
        'note',
    ];
}

function parseImportDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    $ts = strtotime($value);

    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * @return array{headers: list<string>, rows: list<array<string, string>>}|null
 */
function readCommissionInvoiceCsvFile(string $path): ?array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);

        return null;
    }

    $headers = array_map(static fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $headers);
    $rows    = [];

    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }

        $assoc = [];
        $empty = true;
        foreach ($headers as $index => $key) {
            if ($key === '') {
                continue;
            }
            $val = trim((string) ($row[$index] ?? ''));
            if ($val !== '') {
                $empty = false;
            }
            $assoc[$key] = $val;
        }

        if (!$empty) {
            $rows[] = $assoc;
        }
    }

    fclose($handle);

    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * @param array{headers: list<string>, rows: list<array<string, string>>} $csv
 * @return array{
 *     ok: bool,
 *     errors: list<string>,
 *     mode: string,
 *     header: array<string, mixed>,
 *     lines: list<array<string, mixed>>
 * }
 */
function parseCommissionInvoiceImportCsv(array $csv): array
{
    $errors = [];
    $headers = $csv['headers'];
    $rows    = $csv['rows'];

    if ($rows === []) {
        return ['ok' => false, 'errors' => ['Spreadsheet is empty.'], 'mode' => '', 'header' => [], 'lines' => []];
    }

    $isSummary = in_array('staff_count', $headers, true) && in_array('hours_per_lad', $headers, true);
    $isDetailed = in_array('staff_name', $headers, true);

    if (!$isSummary && !$isDetailed) {
        return [
            'ok'     => false,
            'errors' => ['Unrecognised columns. Download a template (summary or detailed).'],
            'mode'   => '',
            'header' => [],
            'lines'  => [],
        ];
    }

    $mode = $isSummary ? 'summary' : 'detailed';
    $first = $rows[0];

    $eventName = trim((string) ($first['event_name'] ?? ''));
    $eventDate = parseImportDate((string) ($first['event_date'] ?? ''));
    $invoiceDate = parseImportDate((string) ($first['invoice_date'] ?? $first['event_date'] ?? ''));

    if ($eventName === '') {
        $errors[] = 'event_name is required.';
    }
    if ($eventDate === null) {
        $errors[] = 'event_date is required (YYYY-MM-DD or DD/MM/YYYY).';
    }
    if ($invoiceDate === null) {
        $errors[] = 'invoice_date is required.';
    }

    $header = [
        'event_name'    => $eventName,
        'event_date'    => $eventDate ?? '',
        'venue'         => trim((string) ($first['venue'] ?? '')),
        'invoice_date'  => $invoiceDate ?? '',
        'client_name'   => trim((string) ($first['client_name'] ?? '')),
        'notes'         => trim((string) ($first['notes'] ?? '')),
        'print_layout'  => $mode === 'summary' ? 'summary' : 'detailed',
    ];

    $lines = [];

    if ($mode === 'summary') {
        $staffCount = (int) ($first['staff_count'] ?? 0);
        $hoursPerLad = round(max(0, (float) ($first['hours_per_lad'] ?? 0)), 2);
        $rate = round(max(0, (float) ($first['hourly_rate'] ?? 0)), 2);

        if ($staffCount < 1) {
            $errors[] = 'staff_count must be at least 1.';
        }
        if ($hoursPerLad <= 0) {
            $errors[] = 'hours_per_lad must be greater than 0.';
        }

        $totalHours = round($staffCount * $hoursPerLad, 2);
        $lineAmount = calculateCommissionLineAmount($totalHours, $rate);

        $lines[] = [
            'registration_id' => null,
            'attendance_id'   => null,
            'staff_name'      => sprintf('Event staffing — %d lads × %s h', $staffCount, rtrim(rtrim(number_format($hoursPerLad, 2), '0'), '.')),
            'staff_role'      => 'steward',
            'hours_worked'    => $totalHours,
            'hours_billed'    => $totalHours,
            'hourly_rate'     => $rate,
            'line_amount'     => $lineAmount,
            'amount_override' => 0,
            'note'            => '',
            'sort_order'      => 0,
        ];
    } else {
        foreach ($rows as $index => $row) {
            $name = trim((string) ($row['staff_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $hoursWorked = round(max(0, (float) ($row['hours_worked'] ?? 0)), 2);
            $hoursBilled = round(max(0, (float) ($row['hours_billed'] ?? $hoursWorked)), 2);
            $rate        = round(max(0, (float) ($row['hourly_rate'] ?? 0)), 2);
            $lineAmount  = trim((string) ($row['line_amount'] ?? '')) !== ''
                ? round(max(0, (float) $row['line_amount']), 2)
                : calculateCommissionLineAmount($hoursBilled, $rate);

            $lines[] = [
                'registration_id' => null,
                'attendance_id'   => null,
                'staff_name'      => $name,
                'staff_role'      => strtolower(trim((string) ($row['role'] ?? 'steward'))),
                'hours_worked'    => $hoursWorked,
                'hours_billed'    => $hoursBilled,
                'hourly_rate'     => $rate,
                'line_amount'     => $lineAmount,
                'amount_override' => trim((string) ($row['line_amount'] ?? '')) !== '' ? 1 : 0,
                'note'            => trim((string) ($row['note'] ?? '')),
                'sort_order'      => $index,
            ];
        }

        if ($lines === []) {
            $errors[] = 'No staff rows found — add staff_name on each line.';
        }
    }

    return [
        'ok'     => $errors === [] && $lines !== [],
        'errors' => $errors,
        'mode'   => $mode,
        'header' => $header,
        'lines'  => $lines,
    ];
}

function findOrCreateEventForInvoiceImport(PDO $pdo, array $header): int|string
{
    $name = trim((string) ($header['event_name'] ?? ''));
    $date = (string) ($header['event_date'] ?? '');

    $stmt = $pdo->prepare('SELECT id FROM events WHERE name = :name AND event_date = :event_date LIMIT 1');
    $stmt->execute(['name' => $name, 'event_date' => $date]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        return $id;
    }

    return createEvent($pdo, [
        'name'       => $name,
        'event_date' => $date,
        'location'   => trim((string) ($header['venue'] ?? '')),
        'is_active'  => 1,
    ]);
}

/**
 * @param array<string, mixed> $parsed
 * @return int|string Invoice ID or error message
 */
function importCommissionInvoiceFromSpreadsheet(PDO $pdo, array $parsed, int $adminUserId): int|string
{
    ensureCommissionInvoiceSchema($pdo);

    if (empty($parsed['ok'])) {
        return implode(' ', $parsed['errors'] ?? ['Invalid import data.']);
    }

    $eventResult = findOrCreateEventForInvoiceImport($pdo, $parsed['header']);
    if (!is_int($eventResult)) {
        return (string) $eventResult;
    }

    $eventId = $eventResult;
    if (getCommissionInvoiceByEventId($pdo, $eventId)) {
        return 'An invoice already exists for this event. Edit or delete it before importing again.';
    }

    $header = [
        'invoice_date'  => (string) $parsed['header']['invoice_date'],
        'client_name'   => (string) ($parsed['header']['client_name'] ?? ''),
        'notes'         => (string) ($parsed['header']['notes'] ?? ''),
        'status'        => 'draft',
        'print_layout'  => (string) ($parsed['header']['print_layout'] ?? 'detailed'),
    ];

    return saveCommissionInvoice($pdo, $eventId, $header, $parsed['lines'], $adminUserId);
}

/**
 * Build one summary line description for print view from invoice totals.
 *
 * @return list<array<string, mixed>>
 */
function buildCommissionInvoiceSummaryPrintLines(array $invoice): array
{
    $staff   = (int) ($invoice['staff_count'] ?? 0);
    $hours   = (float) ($invoice['total_hours_billed'] ?? 0);
    $amount  = (float) ($invoice['total_amount'] ?? 0);
    $perLad  = $staff > 0 ? round($hours / $staff, 2) : $hours;
    $rate    = $hours > 0 ? round($amount / $hours, 2) : 0.0;

    return [[
        'description' => sprintf(
            'Event staffing commission — %d staff × %s hours',
            $staff,
            rtrim(rtrim(number_format($perLad, 2), '0'), '.')
        ),
        'quantity'    => $staff,
        'hours'       => $hours,
        'rate'        => $rate,
        'amount'      => $amount,
    ]];
}
