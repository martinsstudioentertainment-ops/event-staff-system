<?php

require_once __DIR__ . '/commission-invoice-repository.php';
require_once __DIR__ . '/events-repository.php';

/**
 * @return array{
 *     ok: bool,
 *     errors: list<string>,
 *     invoice: array<string, mixed>,
 *     summary_lines: list<array<string, mixed>>
 * }
 */
function parseQuickCommissionInvoiceFromPost(array $post): array
{
    $errors = [];

    $invoiceDate = trim((string) ($post['invoice_date'] ?? ''));
    if ($invoiceDate === '' || strtotime($invoiceDate) === false) {
        $errors[] = 'Please enter a valid invoice date.';
    } else {
        $invoiceDate = date('Y-m-d', strtotime($invoiceDate));
    }

    $eventDateRaw = trim((string) ($post['event_date'] ?? ''));
    $eventDate    = $eventDateRaw !== '' && strtotime($eventDateRaw) !== false
        ? date('Y-m-d', strtotime($eventDateRaw))
        : '';

    $clientName   = trim((string) ($post['client_name'] ?? ''));
    $eventName    = trim((string) ($post['event_name'] ?? ''));
    $venue        = trim((string) ($post['venue'] ?? ''));
    $notes        = trim((string) ($post['notes'] ?? ''));
    $lineText     = trim((string) ($post['line_description'] ?? ''));
    $invoiceNo    = trim((string) ($post['invoice_number'] ?? ''));

    $staffCount   = max(0, (int) ($post['staff_count'] ?? 0));
    $hoursPerLad  = round(max(0, (float) ($post['hours_per_lad'] ?? 0)), 2);
    $hourlyRate   = round(max(0, (float) ($post['hourly_rate'] ?? 0)), 2);
    $totalOverride = trim((string) ($post['total_amount'] ?? ''));

    if ($eventName === '') {
        $errors[] = 'Event or job description is required.';
    }
    if ($staffCount < 1) {
        $errors[] = 'Staff count must be at least 1.';
    }
    if ($hoursPerLad <= 0) {
        $errors[] = 'Hours per staff must be greater than 0.';
    }

    $totalHours = round($staffCount * $hoursPerLad, 2);
    $totalAmount = $totalOverride !== ''
        ? round(max(0, (float) $totalOverride), 2)
        : calculateCommissionLineAmount($totalHours, $hourlyRate);

    if ($totalAmount <= 0 && $totalOverride === '') {
        $errors[] = 'Total amount must be greater than 0 (check staff count, hours, and rate).';
    }

    $effectiveRate = $totalHours > 0 ? round($totalAmount / $totalHours, 2) : $hourlyRate;

    if ($lineText === '') {
        $lineText = sprintf(
            'Event staffing — %d staff × %s h',
            $staffCount,
            rtrim(rtrim(number_format($hoursPerLad, 2), '0'), '.')
        );
    }

    if ($invoiceNo === '') {
        $invoiceNo = 'QUICK-' . date('Ymd-His');
    }

    $invoice = [
        'invoice_number'     => $invoiceNo,
        'invoice_date'       => $invoiceDate,
        'client_name'        => $clientName,
        'event_name'         => $eventName,
        'event_date'         => $eventDate,
        'venue'              => $venue,
        'staff_count'        => $staffCount,
        'total_hours_worked' => $totalHours,
        'total_hours_billed' => $totalHours,
        'total_amount'       => $totalAmount,
        'hourly_rate'        => $effectiveRate,
        'hours_per_lad'      => $hoursPerLad,
        'notes'              => $notes,
        'is_quick_print'     => true,
    ];

    $summaryLines = [[
        'description' => $lineText,
        'quantity'    => $staffCount,
        'hours'       => $totalHours,
        'rate'        => $effectiveRate,
        'amount'      => $totalAmount,
    ]];

    return [
        'ok'            => $errors === [],
        'errors'        => $errors,
        'invoice'       => $invoice,
        'summary_lines' => $summaryLines,
    ];
}

/**
 * @param array<string, mixed> $invoice
 */
function formatQuickInvoiceEventLabel(array $invoice): string
{
    $parts = [trim((string) ($invoice['event_name'] ?? ''))];

    if (!empty($invoice['event_date'])) {
        $parts[] = formatEventDateLabel((string) $invoice['event_date']);
    }

    return implode(' · ', array_filter($parts));
}
