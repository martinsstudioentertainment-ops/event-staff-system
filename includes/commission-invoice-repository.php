<?php

require_once __DIR__ . '/commission-invoice-schema.php';
require_once __DIR__ . '/work-hours-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/system-settings.php';

/** @return array<string, string> */
function getCommissionInvoiceStatusOptions(): array
{
    return [
        'draft' => 'Draft',
        'sent'  => 'Sent',
        'paid'  => 'Paid',
        'void'  => 'Void',
    ];
}

/** @return array<string, string> */
function getCommissionInvoicePrintLayoutOptions(): array
{
    return [
        'detailed' => 'Detailed — every lad name and hours (internal / audit)',
        'summary'  => 'Summary — totals only on printed client invoice',
    ];
}

function normalizeCommissionInvoicePrintLayout(string $layout): string
{
    return array_key_exists($layout, getCommissionInvoicePrintLayoutOptions()) ? $layout : 'detailed';
}

function getDefaultCommissionRate(PDO $pdo, string $role): float
{
    require_once __DIR__ . '/registration-forms.php';

    $key = commissionRateSettingKey($role);

    return max(0, round((float) getSetting($pdo, $key, '0'), 2));
}

function calculateCommissionLineAmount(float $hoursBilled, float $hourlyRate): float
{
    return round(max(0, $hoursBilled) * max(0, $hourlyRate), 2);
}

/**
 * Events for commission invoice pickers — includes past and inactive events.
 *
 * @return array<int, array<string, mixed>>
 */
function getEventsForCommissionInvoiceFilter(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name, event_date, is_active
         FROM events
         ORDER BY event_date DESC, name ASC'
    )->fetchAll() ?: [];
}

/**
 * @param list<array<string, mixed>> $lines
 * @return array{staff_count: int, total_hours_worked: float, total_hours_billed: float, subtotal_amount: float, total_amount: float}
 */
function recomputeCommissionInvoiceTotals(array $lines): array
{
    $totals = [
        'staff_count'        => count($lines),
        'total_hours_worked' => 0.0,
        'total_hours_billed' => 0.0,
        'subtotal_amount'    => 0.0,
        'total_amount'       => 0.0,
    ];

    foreach ($lines as $line) {
        $totals['total_hours_worked'] += (float) ($line['hours_worked'] ?? 0);
        $totals['total_hours_billed'] += (float) ($line['hours_billed'] ?? 0);
        $totals['subtotal_amount']    += (float) ($line['line_amount'] ?? 0);
    }

    $totals['total_hours_worked'] = round($totals['total_hours_worked'], 2);
    $totals['total_hours_billed'] = round($totals['total_hours_billed'], 2);
    $totals['subtotal_amount']    = round($totals['subtotal_amount'], 2);
    $totals['total_amount']       = $totals['subtotal_amount'];

    return $totals;
}

/**
 * @return array{0: string, 1: array<string, mixed>, 2: string} SQL where, params, month label
 */
function buildCommissionInvoiceFilters(int $eventId = 0, string $status = '', string $month = ''): array
{
    $where  = "ci.status <> 'void'";
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND ci.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($status !== '' && array_key_exists($status, getCommissionInvoiceStatusOptions())) {
        $where  = '1=1';
        $params = [];
        if ($eventId > 0) {
            $where .= ' AND ci.event_id = :event_id';
            $params['event_id'] = $eventId;
        }
        $where .= ' AND ci.status = :status';
        $params['status'] = $status;
    }

    $monthLabel = '';
    $month      = trim($month);
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $where .= ' AND ci.invoice_date >= :month_start AND ci.invoice_date <= :month_end';
        $params['month_start'] = $start;
        $params['month_end']   = $end;
        $monthLabel            = date('F Y', strtotime($start));
    }

    return [$where, $params, $monthLabel];
}

/**
 * @return array{
 *     invoice_count: int,
 *     staff_count: int,
 *     total_hours_worked: float,
 *     total_hours_billed: float,
 *     total_amount: float,
 *     month_label: string
 * }
 */
function getCommissionInvoiceAggregates(PDO $pdo, int $eventId = 0, string $status = '', string $month = ''): array
{
    ensureCommissionInvoiceSchema($pdo);

    [$where, $params, $monthLabel] = buildCommissionInvoiceFilters($eventId, $status, $month);

    $sql = "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(ci.staff_count), 0) AS staff_count,
                COALESCE(SUM(ci.total_hours_worked), 0) AS total_hours_worked,
                COALESCE(SUM(ci.total_hours_billed), 0) AS total_hours_billed,
                COALESCE(SUM(ci.total_amount), 0) AS total_amount
            FROM commission_invoices ci
            INNER JOIN events e ON e.id = ci.event_id
            WHERE {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
        'invoice_count'      => (int) ($row['invoice_count'] ?? 0),
        'staff_count'        => (int) ($row['staff_count'] ?? 0),
        'total_hours_worked' => round((float) ($row['total_hours_worked'] ?? 0), 2),
        'total_hours_billed' => round((float) ($row['total_hours_billed'] ?? 0), 2),
        'total_amount'       => round((float) ($row['total_amount'] ?? 0), 2),
        'month_label'        => $monthLabel,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function getCommissionInvoicesList(PDO $pdo, int $eventId = 0, string $status = '', string $month = ''): array
{
    ensureCommissionInvoiceSchema($pdo);

    [$where, $params] = buildCommissionInvoiceFilters($eventId, $status, $month);

    $sql = "SELECT ci.*, e.name AS event_name, e.event_date, e.location AS event_location,
                   au.username AS created_by_username
            FROM commission_invoices ci
            INNER JOIN events e ON e.id = ci.event_id
            LEFT JOIN admin_users au ON au.id = ci.created_by
            WHERE {$where}
            ORDER BY ci.invoice_date DESC, e.event_date DESC, e.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function getCommissionInvoiceById(PDO $pdo, int $invoiceId): ?array
{
    ensureCommissionInvoiceSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT ci.*, e.name AS event_name, e.event_date, e.location AS event_location,
                e.start_time, e.end_time
         FROM commission_invoices ci
         INNER JOIN events e ON e.id = ci.event_id
         WHERE ci.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $invoiceId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function getCommissionInvoiceByEventId(PDO $pdo, int $eventId): ?array
{
    ensureCommissionInvoiceSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM commission_invoices WHERE event_id = :event_id LIMIT 1');
    $stmt->execute(['event_id' => $eventId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function getCommissionInvoiceLines(PDO $pdo, int $invoiceId): array
{
    ensureCommissionInvoiceSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT * FROM commission_invoice_lines
         WHERE invoice_id = :invoice_id
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute(['invoice_id' => $invoiceId]);

    return $stmt->fetchAll();
}

/**
 * Build draft lines from event attendance / work hours.
 *
 * @return list<array<string, mixed>>
 */
function buildCommissionInvoiceLinesFromEvent(PDO $pdo, int $eventId): array
{
    $rows = getWorkHoursList($pdo, $eventId);
    $lines = [];
    $order = 0;

    foreach ($rows as $row) {
        $hoursWorked = round((float) ($row['hours_worked'] ?? 0), 2);
        $hoursBilled = round((float) ($row['hours_paid'] ?? $hoursWorked), 2);
        $rate        = getDefaultCommissionRate($pdo, (string) ($row['staff_role'] ?? ''));

        $lines[] = [
            'attendance_id'   => (int) $row['attendance_id'],
            'registration_id' => (int) $row['registration_id'],
            'staff_name'      => trim($row['first_name'] . ' ' . $row['surname']),
            'staff_role'      => (string) ($row['staff_role'] ?? ''),
            'hours_worked'    => $hoursWorked,
            'hours_billed'    => $hoursBilled,
            'hourly_rate'     => $rate,
            'line_amount'     => calculateCommissionLineAmount($hoursBilled, $rate),
            'amount_override' => 0,
            'note'            => (string) ($row['hours_note'] ?? ''),
            'sort_order'      => $order++,
        ];
    }

    return $lines;
}

function generateCommissionInvoiceNumber(PDO $pdo, string $invoiceDate): string
{
    ensureCommissionInvoiceSchema($pdo);

    $year = date('Y', strtotime($invoiceDate) ?: time());
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM commission_invoices WHERE YEAR(invoice_date) = :year'
    );
    $stmt->execute(['year' => (int) $year]);
    $next = (int) $stmt->fetchColumn() + 1;

    return sprintf('INV-%s-%04d', $year, $next);
}

/**
 * @param array<string, mixed> $header
 * @param list<array<string, mixed>> $lines
 * @return int|string Invoice ID on success, error message on failure
 */
function saveCommissionInvoice(PDO $pdo, int $eventId, array $header, array $lines, int $adminUserId, ?int $invoiceId = null): int|string
{
    ensureCommissionInvoiceSchema($pdo);

    $event = getEventById($pdo, $eventId);
    if (!$event) {
        return 'Event not found.';
    }

    if ($lines === []) {
        return 'Add at least one staff line from event sign-ins.';
    }

    $status = (string) ($header['status'] ?? 'draft');
    if (!array_key_exists($status, getCommissionInvoiceStatusOptions())) {
        return 'Invalid invoice status.';
    }

    $invoiceDate = trim((string) ($header['invoice_date'] ?? ''));
    if ($invoiceDate === '' || strtotime($invoiceDate) === false) {
        return 'Please enter a valid invoice date.';
    }

    $normalizedLines = [];
    foreach ($lines as $index => $line) {
        $hoursWorked = round(max(0, (float) ($line['hours_worked'] ?? 0)), 2);
        $hoursBilled = round(max(0, (float) ($line['hours_billed'] ?? 0)), 2);
        $hourlyRate  = round(max(0, (float) ($line['hourly_rate'] ?? 0)), 2);
        $override    = !empty($line['amount_override']);
        $lineAmount  = $override
            ? round(max(0, (float) ($line['line_amount'] ?? 0)), 2)
            : calculateCommissionLineAmount($hoursBilled, $hourlyRate);

        $registrationId = (int) ($line['registration_id'] ?? 0);
        $staffName      = trim((string) ($line['staff_name'] ?? ''));
        if ($staffName === '') {
            return 'Each line must include a staff or line description.';
        }

        $normalizedLines[] = [
            'attendance_id'   => (int) ($line['attendance_id'] ?? 0) ?: null,
            'registration_id' => $registrationId > 0 ? $registrationId : null,
            'staff_name'      => $staffName,
            'staff_role'      => trim((string) ($line['staff_role'] ?? '')),
            'hours_worked'    => $hoursWorked,
            'hours_billed'    => $hoursBilled,
            'hourly_rate'     => $hourlyRate,
            'line_amount'     => $lineAmount,
            'amount_override' => $override ? 1 : 0,
            'note'            => trim((string) ($line['note'] ?? '')),
            'sort_order'      => (int) ($line['sort_order'] ?? $index),
        ];
    }

    $totals   = recomputeCommissionInvoiceTotals($normalizedLines);
    $currency = strtoupper(trim((string) ($header['currency'] ?? getSystemCurrency($pdo))));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = getSystemCurrency($pdo);
    }

    $clientName = trim((string) ($header['client_name'] ?? ''));
    $notes      = trim((string) ($header['notes'] ?? ''));
    $invoiceNo  = trim((string) ($header['invoice_number'] ?? ''));
    $printLayout = normalizeCommissionInvoicePrintLayout(trim((string) ($header['print_layout'] ?? 'detailed')));

    try {
        $pdo->beginTransaction();

        if ($invoiceId === null || $invoiceId < 1) {
            $existing = getCommissionInvoiceByEventId($pdo, $eventId);
            if ($existing) {
                $pdo->rollBack();

                return 'An invoice already exists for this event. Open it to edit.';
            }

            if ($invoiceNo === '') {
                $invoiceNo = generateCommissionInvoiceNumber($pdo, $invoiceDate);
            }

            $insert = $pdo->prepare(
                'INSERT INTO commission_invoices (
                    event_id, client_name, invoice_number, invoice_date, status, currency,
                    staff_count, total_hours_worked, total_hours_billed, subtotal_amount, total_amount,
                    print_layout, notes, created_by
                ) VALUES (
                    :event_id, :client_name, :invoice_number, :invoice_date, :status, :currency,
                    :staff_count, :total_hours_worked, :total_hours_billed, :subtotal_amount, :total_amount,
                    :print_layout, :notes, :created_by
                )'
            );
            $insert->execute([
                'event_id'           => $eventId,
                'client_name'        => $clientName !== '' ? $clientName : null,
                'invoice_number'     => $invoiceNo,
                'invoice_date'       => date('Y-m-d', strtotime($invoiceDate)),
                'status'             => $status,
                'currency'           => $currency,
                'staff_count'        => $totals['staff_count'],
                'total_hours_worked' => $totals['total_hours_worked'],
                'total_hours_billed' => $totals['total_hours_billed'],
                'subtotal_amount'    => $totals['subtotal_amount'],
                'total_amount'       => $totals['total_amount'],
                'print_layout'       => $printLayout,
                'notes'              => $notes !== '' ? $notes : null,
                'created_by'         => $adminUserId > 0 ? $adminUserId : null,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();
        } else {
            $current = getCommissionInvoiceById($pdo, $invoiceId);
            if (!$current || (int) $current['event_id'] !== $eventId) {
                $pdo->rollBack();

                return 'Invoice not found for this event.';
            }

            if ($invoiceNo === '') {
                $invoiceNo = (string) ($current['invoice_number'] ?? generateCommissionInvoiceNumber($pdo, $invoiceDate));
            }

            $update = $pdo->prepare(
                'UPDATE commission_invoices SET
                    client_name = :client_name,
                    invoice_number = :invoice_number,
                    invoice_date = :invoice_date,
                    status = :status,
                    currency = :currency,
                    staff_count = :staff_count,
                    total_hours_worked = :total_hours_worked,
                    total_hours_billed = :total_hours_billed,
                    subtotal_amount = :subtotal_amount,
                    total_amount = :total_amount,
                    print_layout = :print_layout,
                    notes = :notes
                 WHERE id = :id'
            );
            $update->execute([
                'client_name'        => $clientName !== '' ? $clientName : null,
                'invoice_number'     => $invoiceNo,
                'invoice_date'       => date('Y-m-d', strtotime($invoiceDate)),
                'status'             => $status,
                'currency'           => $currency,
                'staff_count'        => $totals['staff_count'],
                'total_hours_worked' => $totals['total_hours_worked'],
                'total_hours_billed' => $totals['total_hours_billed'],
                'subtotal_amount'    => $totals['subtotal_amount'],
                'total_amount'       => $totals['total_amount'],
                'print_layout'       => $printLayout,
                'notes'              => $notes !== '' ? $notes : null,
                'id'                 => $invoiceId,
            ]);

            $pdo->prepare('DELETE FROM commission_invoice_lines WHERE invoice_id = :id')
                ->execute(['id' => $invoiceId]);
        }

        $lineInsert = $pdo->prepare(
            'INSERT INTO commission_invoice_lines (
                invoice_id, attendance_id, registration_id, staff_name, staff_role,
                hours_worked, hours_billed, hourly_rate, line_amount, amount_override, note, sort_order
            ) VALUES (
                :invoice_id, :attendance_id, :registration_id, :staff_name, :staff_role,
                :hours_worked, :hours_billed, :hourly_rate, :line_amount, :amount_override, :note, :sort_order
            )'
        );

        foreach ($normalizedLines as $line) {
            $lineInsert->execute([
                'invoice_id'       => $invoiceId,
                'attendance_id'    => $line['attendance_id'],
                'registration_id'  => $line['registration_id'],
                'staff_name'       => $line['staff_name'],
                'staff_role'       => $line['staff_role'] !== '' ? $line['staff_role'] : null,
                'hours_worked'     => $line['hours_worked'],
                'hours_billed'     => $line['hours_billed'],
                'hourly_rate'      => $line['hourly_rate'],
                'line_amount'      => $line['line_amount'],
                'amount_override'  => $line['amount_override'],
                'note'             => $line['note'] !== '' ? $line['note'] : null,
                'sort_order'       => $line['sort_order'],
            ]);
        }

        $pdo->commit();

        return $invoiceId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return 'Could not save invoice. ' . $e->getMessage();
    }
}

/**
 * @return list<array<string, mixed>>
 */
function parseCommissionInvoiceLinesFromPost(array $post): array
{
    $raw = $post['lines'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $lines = [];
    foreach ($raw as $line) {
        if (!is_array($line)) {
            continue;
        }
        $lines[] = $line;
    }

    return $lines;
}
