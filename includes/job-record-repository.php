<?php

declare(strict_types=1);

require_once __DIR__ . '/job-record-schema.php';
require_once __DIR__ . '/commission-invoice-repository.php';
require_once __DIR__ . '/commission-invoice-quick.php';
require_once __DIR__ . '/system-settings.php';

/** @return array<string, string> */
function getJobRecordStatusOptions(): array
{
    return [
        'draft' => 'Draft',
        'sent'  => 'Sent',
        'paid'  => 'Paid',
        'void'  => 'Void',
    ];
}

function generateJobRecordInvoiceNumber(PDO $pdo, string $invoiceType = 'staff_commission'): string
{
    ensureJobRecordSchema($pdo);
    $prefix = normalizeJobRecordInvoiceType($invoiceType) === 'personal'
        ? 'INV-' . date('Ymd') . '-'
        : 'JOB-' . date('Ymd') . '-';

    try {
        $stmt = $pdo->prepare(
            "SELECT invoice_number FROM saved_job_records
             WHERE invoice_number LIKE :prefix
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['prefix' => $prefix . '%']);
        $last = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $last = '';
    }

    $seq = 1;
    if ($last !== '' && preg_match('/-(\d+)$/', $last, $m)) {
        $seq = (int) $m[1] + 1;
    }

    return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
}

/**
 * @param array{month?: string, status?: string, q?: string, invoice_type?: string} $filters
 * @return list<array<string, mixed>>
 */
function listJobRecords(PDO $pdo, array $filters = [], int $limit = 200, int $offset = 0): array
{
    ensureJobRecordSchema($pdo);

    $where  = ['1=1'];
    $params = [];

    $invoiceType = trim((string) ($filters['invoice_type'] ?? ''));
    if ($invoiceType !== '') {
        $where[]               = 'invoice_type = :invoice_type';
        $params['invoice_type'] = normalizeJobRecordInvoiceType($invoiceType);
    }

    $recordKind = trim((string) ($filters['record_kind'] ?? ''));
    if ($recordKind !== '') {
        $where[]             = 'record_kind = :record_kind';
        $params['record_kind'] = normalizePersonalRecordKind($recordKind);
    }

    if (!empty($filters['unbilled_work_logs'])) {
        $where[] = "record_kind = 'work_log' AND invoiced_via_id IS NULL";
    }

    $month = trim((string) ($filters['month'] ?? ''));
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where[]           = 'invoice_date >= :month_start AND invoice_date < :month_end';
        $params['month_start'] = $month . '-01';
        $params['month_end']   = date('Y-m-d', strtotime($month . '-01 +1 month'));
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && isset(getJobRecordStatusOptions()[$status])) {
        $where[]          = 'status = :status';
        $params['status'] = $status;
    } elseif ($status === '') {
        $where[] = "status <> 'void'";
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[]    = '(event_name LIKE :q OR client_name LIKE :q OR invoice_number LIKE :q OR venue LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }

    $limit  = max(1, min($limit, 500));
    $offset = max(0, $offset);
    $sql    = 'SELECT * FROM saved_job_records WHERE ' . implode(' AND ', $where)
        . ' ORDER BY invoice_date DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] listJobRecords: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return array<string, mixed>|null
 */
function getJobRecordById(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    ensureJobRecordSchema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT * FROM saved_job_records WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok: bool, errors: list<string>, totals?: array{total_hours: float, total_amount: float}}
 */
function validateJobRecordInput(array $data, bool $isUpdate = false): array
{
    $errors      = [];
    $invoiceType = normalizeJobRecordInvoiceType((string) ($data['invoice_type'] ?? 'staff_commission'));
    $isPersonal  = $invoiceType === 'personal';

    $invoiceDate = trim((string) ($data['invoice_date'] ?? ''));
    if ($invoiceDate === '' || strtotime($invoiceDate) === false) {
        $errors[] = 'Invoice date is required.';
    }

    if (trim((string) ($data['event_name'] ?? '')) === '') {
        $errors[] = $isPersonal ? 'Job description is required.' : 'Event / job name is required.';
    }

    if ($isPersonal) {
        $staffCount    = 1;
        $hoursPerStaff = round(max(0, (float) ($data['hours_worked'] ?? $data['hours_per_staff'] ?? 0)), 2);
        if ($hoursPerStaff <= 0) {
            $errors[] = 'Hours worked must be greater than 0.';
        }
    } else {
        $staffCount = (int) ($data['staff_count'] ?? 0);
        if ($staffCount < 1) {
            $errors[] = 'Staff count must be at least 1.';
        }

        $hoursPerStaff = round(max(0, (float) ($data['hours_per_staff'] ?? 0)), 2);
        if ($hoursPerStaff <= 0) {
            $errors[] = 'Hours per staff must be greater than 0.';
        }
    }

    $totalOverride = trim((string) ($data['total_amount'] ?? ''));
    $hourlyRate    = round(max(0, (float) ($data['hourly_rate'] ?? 0)), 2);
    $totalHours    = round($staffCount * $hoursPerStaff, 2);
    $totalAmount   = $totalOverride !== ''
        ? round(max(0, (float) $totalOverride), 2)
        : calculateCommissionLineAmount($totalHours, $hourlyRate);

    if ($totalAmount <= 0) {
        $errors[] = 'Total amount must be greater than 0.';
    }

    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    if (!$isUpdate && $invoiceNumber === '') {
        // auto-generated on save
    }

    return [
        'ok'     => $errors === [],
        'errors' => $errors,
        'totals' => [
            'total_hours'  => $totalHours,
            'total_amount' => $totalAmount,
            'hourly_rate'  => $hourlyRate,
        ],
    ];
}

/**
 * @return array{ok: bool, message: string, id?: int}
 */
function saveJobRecord(PDO $pdo, array $data, int $adminUserId, ?int $id = null): array
{
    $validation = validateJobRecordInput($data, $id !== null && $id > 0);
    if (!$validation['ok']) {
        return ['ok' => false, 'message' => implode(' ', $validation['errors'])];
    }

    ensureJobRecordSchema($pdo);

    if ($id !== null && $id > 0 && !isset($data['invoice_type'])) {
        $existingForType = getJobRecordById($pdo, $id);
        if ($existingForType !== null) {
            $data['invoice_type'] = (string) ($existingForType['invoice_type'] ?? 'staff_commission');
        }
    }

    $invoiceType   = normalizeJobRecordInvoiceType((string) ($data['invoice_type'] ?? 'staff_commission'));
    $isPersonal    = $invoiceType === 'personal';
    $totals        = $validation['totals'];
    $invoiceDate   = date('Y-m-d', strtotime((string) $data['invoice_date']));
    $eventDateRaw  = trim((string) ($data['event_date'] ?? ''));
    $eventDate     = $eventDateRaw !== '' && strtotime($eventDateRaw) !== false
        ? date('Y-m-d', strtotime($eventDateRaw))
        : null;
    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    if ($invoiceNumber === '') {
        $invoiceNumber = generateJobRecordInvoiceNumber($pdo, $invoiceType);
    }

    if ($isPersonal) {
        $staffCount = 1;
        $hoursPer   = round((float) ($data['hours_worked'] ?? $data['hours_per_staff'] ?? 0), 2);
    } else {
        $staffCount = (int) $data['staff_count'];
        $hoursPer   = round((float) $data['hours_per_staff'], 2);
    }

    $lineText = trim((string) ($data['line_description'] ?? ''));
    if ($lineText === '') {
        if ($isPersonal) {
            $lineText = sprintf(
                'Professional services — %s h',
                rtrim(rtrim(number_format($hoursPer, 2), '0'), '.')
            );
        } else {
            $lineText = sprintf(
                'Event staffing — %d staff × %s h',
                $staffCount,
                rtrim(rtrim(number_format($hoursPer, 2), '0'), '.')
            );
        }
    }

    $status = trim((string) ($data['status'] ?? 'draft'));
    if (!isset(getJobRecordStatusOptions()[$status])) {
        $status = 'draft';
    }

    $payload = [
        'invoice_type'     => $invoiceType,
        'invoice_number'   => $invoiceNumber,
        'invoice_date'     => $invoiceDate,
        'client_name'      => trim((string) ($data['client_name'] ?? '')),
        'event_name'       => trim((string) $data['event_name']),
        'event_date'       => $eventDate,
        'venue'            => trim((string) ($data['venue'] ?? '')),
        'staff_count'      => $staffCount,
        'hours_per_staff'  => $hoursPer,
        'hourly_rate'      => (float) $totals['hourly_rate'],
        'total_hours'      => (float) $totals['total_hours'],
        'total_amount'     => (float) $totals['total_amount'],
        'line_description' => $lineText,
        'notes'            => trim((string) ($data['notes'] ?? '')),
        'status'           => $status,
        'currency'         => getSystemCurrency($pdo),
        'event_id'         => (int) ($data['event_id'] ?? 0) > 0 ? (int) $data['event_id'] : null,
        'created_by'       => $adminUserId > 0 ? $adminUserId : null,
    ];

    try {
        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE saved_job_records SET
                    invoice_type = :invoice_type,
                    invoice_number = :invoice_number,
                    invoice_date = :invoice_date,
                    client_name = :client_name,
                    event_name = :event_name,
                    event_date = :event_date,
                    venue = :venue,
                    staff_count = :staff_count,
                    hours_per_staff = :hours_per_staff,
                    hourly_rate = :hourly_rate,
                    total_hours = :total_hours,
                    total_amount = :total_amount,
                    line_description = :line_description,
                    notes = :notes,
                    status = :status,
                    currency = :currency,
                    event_id = :event_id
                 WHERE id = :id'
            );
            $payload['id'] = $id;
            $stmt->execute($payload);

            return [
                'ok'      => true,
                'message' => $isPersonal ? 'Personal invoice updated.' : 'Job record updated.',
                'id'      => $id,
            ];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO saved_job_records (
                invoice_type, invoice_number, invoice_date, client_name, event_name, event_date, venue,
                staff_count, hours_per_staff, hourly_rate, total_hours, total_amount,
                line_description, notes, status, currency, event_id, created_by
             ) VALUES (
                :invoice_type, :invoice_number, :invoice_date, :client_name, :event_name, :event_date, :venue,
                :staff_count, :hours_per_staff, :hourly_rate, :total_hours, :total_amount,
                :line_description, :notes, :status, :currency, :event_id, :created_by
             )'
        );
        $stmt->execute($payload);
        $newId = (int) $pdo->lastInsertId();

        return [
            'ok'      => true,
            'message' => $isPersonal ? 'Personal invoice saved.' : 'Job record saved.',
            'id'      => $newId,
        ];
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_saved_job_invoice_number')) {
            return ['ok' => false, 'message' => 'That invoice reference is already used — choose another.'];
        }

        error_log('[EventStaff] saveJobRecord: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save job record.'];
    }
}

/**
 * Build print payload compatible with quick invoice template.
 *
 * @param array<string, mixed> $record
 * @return array{invoice: array<string, mixed>, summary_lines: list<array<string, mixed>>}
 */
function jobRecordToPrintPayload(array $record): array
{
    $isPersonal  = isPersonalJobRecord($record);
    $totalHours  = (float) ($record['total_hours'] ?? 0);
    $totalAmount = (float) ($record['total_amount'] ?? 0);
    $staffCount  = (int) ($record['staff_count'] ?? 0);

    $invoice = [
        'invoice_type'       => $isPersonal ? 'personal' : 'staff_commission',
        'invoice_number'     => (string) ($record['invoice_number'] ?? ''),
        'invoice_date'       => (string) ($record['invoice_date'] ?? ''),
        'client_name'        => (string) ($record['client_name'] ?? ''),
        'event_name'         => (string) ($record['event_name'] ?? ''),
        'event_date'         => (string) ($record['event_date'] ?? ''),
        'venue'              => (string) ($record['venue'] ?? ''),
        'staff_count'        => $staffCount,
        'total_hours_worked' => $totalHours,
        'total_hours_billed' => $totalHours,
        'total_amount'       => $totalAmount,
        'notes'              => (string) ($record['notes'] ?? ''),
    ];

    if ($isPersonal && isPersonalInvoiceRecord($record)) {
        $pdo   = getDB();
        $lines = getPersonalInvoiceLines($pdo, (int) ($record['id'] ?? 0));
        if ($lines !== []) {
            $summaryLines = [];
            foreach ($lines as $line) {
                $summaryLines[] = [
                    'description' => (string) $line['description'],
                    'quantity'    => 1,
                    'hours'       => (float) $line['hours'],
                    'rate'        => (float) $line['hourly_rate'],
                    'amount'      => (float) $line['line_amount'],
                    'job_date'    => (string) ($line['job_date'] ?? ''),
                    'venue'       => (string) ($line['venue'] ?? ''),
                    'is_personal' => true,
                ];
            }

            return ['invoice' => $invoice, 'summary_lines' => $summaryLines, 'is_personal' => true];
        }
    }

    $rate = (float) ($record['hourly_rate'] ?? 0);
    if ($rate <= 0 && $totalHours > 0) {
        $rate = round($totalAmount / $totalHours, 2);
    }

    $summaryLines = [[
        'description' => (string) ($record['line_description'] ?? ($isPersonal ? 'Professional services' : 'Event staffing')),
        'quantity'    => $isPersonal ? 1 : $staffCount,
        'hours'       => $totalHours,
        'rate'        => $rate,
        'amount'      => $totalAmount,
        'is_personal' => $isPersonal,
    ]];

    return ['invoice' => $invoice, 'summary_lines' => $summaryLines, 'is_personal' => $isPersonal];
}

/**
 * @return list<array<string, mixed>>
 */
function getPersonalInvoiceLines(PDO $pdo, int $invoiceId): array
{
    if ($invoiceId < 1) {
        return [];
    }

    ensureJobRecordSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM personal_invoice_lines
             WHERE invoice_id = :invoice_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['invoice_id' => $invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Build line rows from a legacy single-job personal invoice.
 *
 * @param array<string, mixed> $record
 * @return list<array<string, mixed>>
 */
function personalInvoiceLinesFromRecord(array $record): array
{
    $hours  = (float) ($record['hours_per_staff'] ?? $record['total_hours'] ?? 0);
    $rate   = (float) ($record['hourly_rate'] ?? 0);
    $amount = (float) ($record['total_amount'] ?? 0);
    if ($amount <= 0 && $hours > 0 && $rate > 0) {
        $amount = calculateCommissionLineAmount($hours, $rate);
    }

    return [[
        'description'        => (string) ($record['line_description'] ?: $record['event_name'] ?: 'Professional services'),
        'job_date'           => (string) ($record['event_date'] ?? ''),
        'venue'              => (string) ($record['venue'] ?? ''),
        'hours'              => $hours,
        'hourly_rate'        => $rate,
        'line_amount'        => $amount,
        'source_work_log_id' => null,
    ]];
}

/**
 * @param array<string, mixed> $post
 * @return list<array<string, mixed>>
 */
function parsePersonalInvoiceLinesFromPost(array $post): array
{
    $rawLines = $post['lines'] ?? [];
    if (!is_array($rawLines)) {
        return [];
    }

    $lines = [];
    foreach ($rawLines as $row) {
        if (!is_array($row)) {
            continue;
        }

        $description = trim((string) ($row['description'] ?? ''));
        $hours       = round(max(0, (float) ($row['hours'] ?? 0)), 2);
        $rate        = round(max(0, (float) ($row['hourly_rate'] ?? 0)), 2);
        $amountRaw   = trim((string) ($row['line_amount'] ?? ''));
        $amount      = $amountRaw !== ''
            ? round(max(0, (float) $amountRaw), 2)
            : calculateCommissionLineAmount($hours, $rate);

        if ($description === '' && $hours <= 0 && $amount <= 0) {
            continue;
        }

        $jobDateRaw = trim((string) ($row['job_date'] ?? ''));
        $jobDate    = $jobDateRaw !== '' && strtotime($jobDateRaw) !== false
            ? date('Y-m-d', strtotime($jobDateRaw))
            : null;

        $lines[] = [
            'description'        => $description !== '' ? $description : 'Professional services',
            'job_date'           => $jobDate,
            'venue'              => trim((string) ($row['venue'] ?? '')),
            'hours'              => $hours,
            'hourly_rate'        => $rate,
            'line_amount'        => $amount,
            'source_work_log_id' => (int) ($row['source_work_log_id'] ?? 0) > 0
                ? (int) $row['source_work_log_id']
                : null,
        ];
    }

    return $lines;
}

/**
 * @param list<int> $workLogIds
 * @return list<array<string, mixed>>
 */
function personalInvoiceLinesFromWorkLogs(PDO $pdo, array $workLogIds): array
{
    $lines = [];
    foreach ($workLogIds as $workLogId) {
        $workLogId = (int) $workLogId;
        if ($workLogId < 1) {
            continue;
        }

        $record = getJobRecordById($pdo, $workLogId);
        if ($record === null || !isPersonalWorkLog($record) || !empty($record['invoiced_via_id'])) {
            continue;
        }

        $hours  = (float) ($record['hours_per_staff'] ?? $record['total_hours'] ?? 0);
        $rate   = (float) ($record['hourly_rate'] ?? 0);
        $amount = (float) ($record['total_amount'] ?? 0);
        if ($amount <= 0) {
            $amount = calculateCommissionLineAmount($hours, $rate);
        }

        $lines[] = [
            'description'        => (string) ($record['event_name'] ?? 'Professional services'),
            'job_date'           => !empty($record['event_date']) ? (string) $record['event_date'] : null,
            'venue'              => (string) ($record['venue'] ?? ''),
            'hours'              => $hours,
            'hourly_rate'        => $rate,
            'line_amount'        => $amount,
            'source_work_log_id' => $workLogId,
        ];
    }

    return $lines;
}

/**
 * @param list<array<string, mixed>> $lines
 */
function buildPersonalInvoiceSummaryTitle(array $lines): string
{
    if ($lines === []) {
        return 'Professional services';
    }

    if (count($lines) === 1) {
        return (string) ($lines[0]['description'] ?? 'Professional services');
    }

    return sprintf('Multiple jobs (%d)', count($lines));
}

/**
 * @param list<array<string, mixed>> $lines
 * @return array{total_hours: float, total_amount: float}
 */
function sumPersonalInvoiceLines(array $lines): array
{
    $totalHours  = 0.0;
    $totalAmount = 0.0;
    foreach ($lines as $line) {
        $totalHours  += (float) ($line['hours'] ?? 0);
        $totalAmount += (float) ($line['line_amount'] ?? 0);
    }

    return [
        'total_hours'  => round($totalHours, 2),
        'total_amount' => round($totalAmount, 2),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function listPersonalWorkLogs(PDO $pdo, int $limit = 200): array
{
    return listJobRecords($pdo, [
        'invoice_type'        => 'personal',
        'record_kind'         => 'work_log',
        'unbilled_work_logs'  => true,
        'status'              => 'draft',
    ], $limit);
}

/**
 * @param array<string, mixed> $data
 * @return array{ok: bool, message: string, id?: int}
 */
function savePersonalWorkLog(PDO $pdo, array $data, int $adminUserId, ?int $id = null): array
{
    $description = trim((string) ($data['event_name'] ?? $data['description'] ?? ''));
    $hours       = round(max(0, (float) ($data['hours_worked'] ?? $data['hours'] ?? 0)), 2);
    $rate        = round(max(0, (float) ($data['hourly_rate'] ?? 0)), 2);
    $amount      = calculateCommissionLineAmount($hours, $rate);

    if ($description === '') {
        return ['ok' => false, 'message' => 'Job description is required.'];
    }
    if ($hours <= 0) {
        return ['ok' => false, 'message' => 'Hours worked must be greater than 0.'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Total amount must be greater than 0.'];
    }

    ensureJobRecordSchema($pdo);

    $eventDateRaw = trim((string) ($data['event_date'] ?? $data['job_date'] ?? ''));
    $eventDate    = $eventDateRaw !== '' && strtotime($eventDateRaw) !== false
        ? date('Y-m-d', strtotime($eventDateRaw))
        : null;

    $payload = [
        'invoice_type'     => 'personal',
        'record_kind'      => 'work_log',
        'invoice_number'   => 'WL-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'invoice_date'     => $eventDate ?? date('Y-m-d'),
        'client_name'      => trim((string) ($data['client_name'] ?? '')),
        'event_name'       => $description,
        'event_date'       => $eventDate,
        'venue'            => trim((string) ($data['venue'] ?? '')),
        'staff_count'      => 1,
        'hours_per_staff'  => $hours,
        'hourly_rate'      => $rate,
        'total_hours'      => $hours,
        'total_amount'     => $amount,
        'line_description' => $description,
        'notes'            => trim((string) ($data['notes'] ?? '')),
        'status'           => 'draft',
        'currency'         => getSystemCurrency($pdo),
        'event_id'         => null,
        'created_by'       => $adminUserId > 0 ? $adminUserId : null,
    ];

    try {
        if ($id !== null && $id > 0) {
            $existing = getJobRecordById($pdo, $id);
            if ($existing === null || !isPersonalWorkLog($existing) || !empty($existing['invoiced_via_id'])) {
                return ['ok' => false, 'message' => 'Work log not found or already invoiced.'];
            }

            $stmt = $pdo->prepare(
                'UPDATE saved_job_records SET
                    client_name = :client_name,
                    event_name = :event_name,
                    event_date = :event_date,
                    venue = :venue,
                    hours_per_staff = :hours_per_staff,
                    hourly_rate = :hourly_rate,
                    total_hours = :total_hours,
                    total_amount = :total_amount,
                    line_description = :line_description,
                    notes = :notes
                 WHERE id = :id'
            );
            $payload['id'] = $id;
            $stmt->execute($payload);

            return ['ok' => true, 'message' => 'Job saved.', 'id' => $id];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO saved_job_records (
                invoice_type, record_kind, invoice_number, invoice_date, client_name, event_name, event_date, venue,
                staff_count, hours_per_staff, hourly_rate, total_hours, total_amount,
                line_description, notes, status, currency, event_id, created_by
             ) VALUES (
                :invoice_type, :record_kind, :invoice_number, :invoice_date, :client_name, :event_name, :event_date, :venue,
                :staff_count, :hours_per_staff, :hourly_rate, :total_hours, :total_amount,
                :line_description, :notes, :status, :currency, :event_id, :created_by
             )'
        );
        $stmt->execute($payload);
        $newId = (int) $pdo->lastInsertId();

        return ['ok' => true, 'message' => 'Job logged for later invoicing.', 'id' => $newId];
    } catch (PDOException $e) {
        error_log('[EventStaff] savePersonalWorkLog: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save job.'];
    }
}

/**
 * @param array<string, mixed> $data
 * @return array{ok: bool, message: string, id?: int}
 */
function savePersonalInvoiceWithLines(PDO $pdo, array $data, int $adminUserId, ?int $id = null): array
{
    ensureJobRecordSchema($pdo);

    $invoiceDate = trim((string) ($data['invoice_date'] ?? ''));
    if ($invoiceDate === '' || strtotime($invoiceDate) === false) {
        return ['ok' => false, 'message' => 'Invoice date is required.'];
    }

    $lines = parsePersonalInvoiceLinesFromPost($data);
    $pickedIds = [];
    foreach ((array) ($data['work_log_ids'] ?? []) as $pickedId) {
        $pickedId = (int) $pickedId;
        if ($pickedId > 0) {
            $pickedIds[] = $pickedId;
        }
    }
    if ($pickedIds !== []) {
        $linkedIds = [];
        foreach ($lines as $line) {
            if (!empty($line['source_work_log_id'])) {
                $linkedIds[] = (int) $line['source_work_log_id'];
            }
        }
        $pickedIds = array_values(array_diff($pickedIds, $linkedIds));
        if ($pickedIds !== []) {
            $lines = array_merge($lines, personalInvoiceLinesFromWorkLogs($pdo, $pickedIds));
        }
    }

    if ($lines === []) {
        return ['ok' => false, 'message' => 'Add at least one job line or select saved jobs.'];
    }

    $totals        = sumPersonalInvoiceLines($lines);
    $summaryTitle  = buildPersonalInvoiceSummaryTitle($lines);
    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
    if ($invoiceNumber === '') {
        $invoiceNumber = generateJobRecordInvoiceNumber($pdo, 'personal');
    }

    $status = trim((string) ($data['status'] ?? 'draft'));
    if (!isset(getJobRecordStatusOptions()[$status])) {
        $status = 'draft';
    }

    $firstLine = $lines[0];
    $payload   = [
        'invoice_type'     => 'personal',
        'record_kind'      => 'invoice',
        'invoice_number'   => $invoiceNumber,
        'invoice_date'     => date('Y-m-d', strtotime($invoiceDate)),
        'client_name'      => trim((string) ($data['client_name'] ?? '')),
        'event_name'       => $summaryTitle,
        'event_date'       => !empty($firstLine['job_date']) ? $firstLine['job_date'] : null,
        'venue'            => count($lines) === 1 ? (string) ($firstLine['venue'] ?? '') : '',
        'staff_count'      => 1,
        'hours_per_staff'  => $totals['total_hours'],
        'hourly_rate'      => $totals['total_hours'] > 0
            ? round($totals['total_amount'] / $totals['total_hours'], 2)
            : 0.0,
        'total_hours'      => $totals['total_hours'],
        'total_amount'     => $totals['total_amount'],
        'line_description' => $summaryTitle,
        'notes'            => trim((string) ($data['notes'] ?? '')),
        'status'           => $status,
        'currency'         => getSystemCurrency($pdo),
        'event_id'         => null,
        'created_by'       => $adminUserId > 0 ? $adminUserId : null,
    ];

    try {
        $pdo->beginTransaction();

        if ($id !== null && $id > 0) {
            $existing = getJobRecordById($pdo, $id);
            if ($existing === null || !isPersonalJobRecord($existing)) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Invoice not found.'];
            }

            releasePersonalInvoiceWorkLogs($pdo, $id);

            $stmt = $pdo->prepare(
                'UPDATE saved_job_records SET
                    invoice_type = :invoice_type,
                    record_kind = :record_kind,
                    invoice_number = :invoice_number,
                    invoice_date = :invoice_date,
                    client_name = :client_name,
                    event_name = :event_name,
                    event_date = :event_date,
                    venue = :venue,
                    staff_count = :staff_count,
                    hours_per_staff = :hours_per_staff,
                    hourly_rate = :hourly_rate,
                    total_hours = :total_hours,
                    total_amount = :total_amount,
                    line_description = :line_description,
                    notes = :notes,
                    status = :status,
                    currency = :currency,
                    event_id = :event_id
                 WHERE id = :id'
            );
            $stmt->execute([
                'invoice_type'     => $payload['invoice_type'],
                'record_kind'      => $payload['record_kind'],
                'invoice_number'   => $payload['invoice_number'],
                'invoice_date'     => $payload['invoice_date'],
                'client_name'      => $payload['client_name'],
                'event_name'       => $payload['event_name'],
                'event_date'       => $payload['event_date'],
                'venue'            => $payload['venue'],
                'staff_count'      => $payload['staff_count'],
                'hours_per_staff'  => $payload['hours_per_staff'],
                'hourly_rate'      => $payload['hourly_rate'],
                'total_hours'      => $payload['total_hours'],
                'total_amount'     => $payload['total_amount'],
                'line_description' => $payload['line_description'],
                'notes'            => $payload['notes'],
                'status'           => $payload['status'],
                'currency'         => $payload['currency'],
                'event_id'         => $payload['event_id'],
                'id'               => $id,
            ]);
            $invoiceId = $id;

            $pdo->prepare('DELETE FROM personal_invoice_lines WHERE invoice_id = :id')
                ->execute(['id' => $invoiceId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO saved_job_records (
                    invoice_type, record_kind, invoice_number, invoice_date, client_name, event_name, event_date, venue,
                    staff_count, hours_per_staff, hourly_rate, total_hours, total_amount,
                    line_description, notes, status, currency, event_id, created_by
                 ) VALUES (
                    :invoice_type, :record_kind, :invoice_number, :invoice_date, :client_name, :event_name, :event_date, :venue,
                    :staff_count, :hours_per_staff, :hourly_rate, :total_hours, :total_amount,
                    :line_description, :notes, :status, :currency, :event_id, :created_by
                 )'
            );
            $stmt->execute($payload);
            $invoiceId = (int) $pdo->lastInsertId();
        }

        savePersonalInvoiceLines($pdo, $invoiceId, $lines);
        markPersonalWorkLogsInvoiced($pdo, $invoiceId, $lines);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Personal invoice saved.', 'id' => $invoiceId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'uq_saved_job_invoice_number')) {
            return ['ok' => false, 'message' => 'That invoice reference is already used — choose another.'];
        }
        error_log('[EventStaff] savePersonalInvoiceWithLines: ' . $e->getMessage());

        $hint = str_contains($e->getMessage(), 'personal_invoice_lines')
            ? ' Database update required — reload the page and try again.'
            : '';

        return ['ok' => false, 'message' => 'Could not save invoice.' . $hint];
    }
}

/**
 * @param list<array<string, mixed>> $lines
 */
function savePersonalInvoiceLines(PDO $pdo, int $invoiceId, array $lines): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO personal_invoice_lines (
            invoice_id, sort_order, description, job_date, venue, hours, hourly_rate, line_amount, source_work_log_id
         ) VALUES (
            :invoice_id, :sort_order, :description, :job_date, :venue, :hours, :hourly_rate, :line_amount, :source_work_log_id
         )'
    );

    foreach ($lines as $index => $line) {
        $stmt->execute([
            'invoice_id'          => $invoiceId,
            'sort_order'          => $index,
            'description'         => (string) ($line['description'] ?? 'Professional services'),
            'job_date'            => !empty($line['job_date']) ? $line['job_date'] : null,
            'venue'               => (string) ($line['venue'] ?? ''),
            'hours'               => (float) ($line['hours'] ?? 0),
            'hourly_rate'         => (float) ($line['hourly_rate'] ?? 0),
            'line_amount'         => (float) ($line['line_amount'] ?? 0),
            'source_work_log_id'  => !empty($line['source_work_log_id']) ? (int) $line['source_work_log_id'] : null,
        ]);
    }
}

/**
 * @param list<array<string, mixed>> $lines
 */
function markPersonalWorkLogsInvoiced(PDO $pdo, int $invoiceId, array $lines): void
{
    $stmt = $pdo->prepare(
        'UPDATE saved_job_records SET invoiced_via_id = :invoice_id WHERE id = :id AND record_kind = \'work_log\''
    );
    foreach ($lines as $line) {
        $sourceId = (int) ($line['source_work_log_id'] ?? 0);
        if ($sourceId > 0) {
            $stmt->execute(['invoice_id' => $invoiceId, 'id' => $sourceId]);
        }
    }
}

function releasePersonalInvoiceWorkLogs(PDO $pdo, int $invoiceId): void
{
    $pdo->prepare(
        'UPDATE saved_job_records SET invoiced_via_id = NULL
         WHERE invoiced_via_id = :invoice_id AND record_kind = \'work_log\''
    )->execute(['invoice_id' => $invoiceId]);
}

/**
 * @return array{ok: bool, message: string, id?: int}
 */
function combinePersonalWorkLogsIntoInvoice(PDO $pdo, array $workLogIds, array $header, int $adminUserId): array
{
    $data = array_merge($header, [
        'work_log_ids' => $workLogIds,
        'lines'        => [],
    ]);

    return savePersonalInvoiceWithLines($pdo, $data, $adminUserId, null);
}

/**
 * @return array{invoice_count: int, staff_count: int, total_hours: float, total_amount: float}
 */
function getJobRecordMonthTotals(PDO $pdo, string $month, string $status = '', string $invoiceType = '', string $recordKind = ''): array
{
    ensureJobRecordSchema($pdo);

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $where  = 'invoice_date >= :start AND invoice_date < :end';
    $params = [
        'start' => $month . '-01',
        'end'   => date('Y-m-d', strtotime($month . '-01 +1 month')),
    ];

    if ($invoiceType !== '') {
        $where                    .= ' AND invoice_type = :invoice_type';
        $params['invoice_type']    = normalizeJobRecordInvoiceType($invoiceType);
    }

    if ($recordKind !== '') {
        $where                  .= ' AND record_kind = :record_kind';
        $params['record_kind']  = normalizePersonalRecordKind($recordKind);
    }

    if ($status !== '' && isset(getJobRecordStatusOptions()[$status])) {
        $where           .= ' AND status = :status';
        $params['status'] = $status;
    } else {
        $where .= " AND status <> 'void'";
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(staff_count), 0) AS staff_count,
                    COALESCE(SUM(total_hours), 0) AS total_hours,
                    COALESCE(SUM(total_amount), 0) AS total_amount
             FROM saved_job_records WHERE {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'invoice_count' => (int) ($row['invoice_count'] ?? 0),
            'staff_count'   => (int) ($row['staff_count'] ?? 0),
            'total_hours'   => (float) ($row['total_hours'] ?? 0),
            'total_amount'  => (float) ($row['total_amount'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['invoice_count' => 0, 'staff_count' => 0, 'total_hours' => 0.0, 'total_amount' => 0.0];
    }
}
