<?php
/**
 * Demo seed: Aviva Stadium event + 30 lads × 6 h + commission invoice (28 May 2026).
 *
 * Usage:
 *   php database/seed-aviva-invoice.php
 *   php database/seed-aviva-invoice.php --rate=18.50 --client="Promoter Ltd"
 *
 * Requires commission tables (run database/run-phase28.php if needed).
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/work-hours-schema.php';

$pdo = getDB();
ensureWorkHoursSchema($pdo);
ensureCommissionInvoiceSchema($pdo);

$eventDate   = '2026-05-28';
$eventName   = 'Aviva Stadium';
$location    = 'Aviva Stadium, Dublin';
$shiftHours  = 6.0;
$ladCount    = 30;
$startTime   = '14:00:00';
$endTime     = '20:00:00';
$invoiceDate = '2026-05-28';
$clientName  = '';
$hourlyRate  = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--rate=')) {
        $hourlyRate = max(0, round((float) substr($arg, 7), 2));
    } elseif (str_starts_with($arg, '--client=')) {
        $clientName = trim(substr($arg, 9));
    }
}

if ($hourlyRate === null) {
    $hourlyRate = getDefaultCommissionRate($pdo, 'steward');
}
if ($hourlyRate <= 0) {
    $hourlyRate = 18.50;
    echo "No commission rate in settings — using €{$hourlyRate}/hr for demo. Set rates in ERP → System.\n";
}

$stmt = $pdo->prepare(
    'SELECT id FROM events WHERE name = :name AND event_date = :event_date LIMIT 1'
);
$stmt->execute(['name' => $eventName, 'event_date' => $eventDate]);
$eventId = (int) ($stmt->fetchColumn() ?: 0);

if ($eventId < 1) {
    $eventId = createEvent($pdo, [
        'name'       => $eventName,
        'event_date' => $eventDate,
        'location'   => $location,
        'start_time' => $startTime,
        'end_time'   => $endTime,
        'staff_needed' => $ladCount,
        'is_active'  => 1,
    ]);
    echo "Created event #{$eventId}: {$eventName} on {$eventDate}\n";
} else {
    echo "Using existing event #{$eventId}: {$eventName} on {$eventDate}\n";
}

$existingInvoice = getCommissionInvoiceByEventId($pdo, $eventId);
if ($existingInvoice) {
    echo "Invoice already exists: {$existingInvoice['invoice_number']} (id {$existingInvoice['id']})\n";
    echo "Open: admin/invoice-form.php?id={$existingInvoice['id']}\n";
    exit(0);
}

$checkedInAt = $eventDate . ' 14:00:00';
$workEndAt   = $eventDate . ' 20:00:00';

$regInsert = $pdo->prepare(
    'INSERT INTO staff_registrations (
        surname, first_name, full_address, eircode, email, mobile, date_of_birth, gender,
        pps_number, bank_iban, staff_role, event_id, status, privacy_consented_at
    ) VALUES (
        :surname, :first_name, :full_address, :eircode, :email, :mobile, :date_of_birth, :gender,
        :pps_number, :bank_iban, :staff_role, :event_id, :status, NOW()
    )'
);

$attInsert = $pdo->prepare(
    'INSERT INTO attendance (
        registration_id, event_id, checked_in_at, checked_in_method,
        work_end_at, scheduled_hours, hours_worked, hours_paid
    ) VALUES (
        :registration_id, :event_id, :checked_in_at, :checked_in_method,
        :work_end_at, :scheduled_hours, :hours_worked, :hours_paid
    )'
);

$created = 0;
for ($i = 1; $i <= $ladCount; $i++) {
    $email = sprintf('aviva-demo-%03d@example.local', $i);
    $check = $pdo->prepare('SELECT id FROM staff_registrations WHERE email = :email AND event_id = :event_id LIMIT 1');
    $check->execute(['email' => $email, 'event_id' => $eventId]);
    $regId = (int) ($check->fetchColumn() ?: 0);

    if ($regId < 1) {
        $regInsert->execute([
            'surname'      => 'Lad' . $i,
            'first_name'   => 'Demo',
            'full_address' => '1 Demo Street, Dublin',
            'eircode'      => 'D01X000',
            'email'        => $email,
            'mobile'       => '+353870000' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'date_of_birth'=> '1995-01-15',
            'gender'       => 'prefer_not_to_say',
            'pps_number'   => sprintf('123456%02dA', $i % 100),
            'bank_iban'    => 'IE00DEMO00000000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'staff_role'   => 'steward',
            'event_id'     => $eventId,
            'status'       => 'approved',
        ]);
        $regId = (int) $pdo->lastInsertId();
    }

    $attCheck = $pdo->prepare('SELECT id FROM attendance WHERE registration_id = :id LIMIT 1');
    $attCheck->execute(['id' => $regId]);
    if (!$attCheck->fetchColumn()) {
        $attInsert->execute([
            'registration_id'   => $regId,
            'event_id'          => $eventId,
            'checked_in_at'     => $checkedInAt,
            'checked_in_method' => 'admin',
            'work_end_at'       => $workEndAt,
            'scheduled_hours'   => $shiftHours,
            'hours_worked'      => $shiftHours,
            'hours_paid'        => $shiftHours,
        ]);
        $created++;
    }
}

echo "Ensured {$ladCount} staff with {$shiftHours}h sign-ins ({$created} new attendance rows).\n";

$lines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
foreach ($lines as &$line) {
    $line['hourly_rate'] = $hourlyRate;
    $line['line_amount'] = calculateCommissionLineAmount($shiftHours, $hourlyRate);
}
unset($line);

$totals = recomputeCommissionInvoiceTotals($lines);
$result = saveCommissionInvoice($pdo, $eventId, [
    'invoice_date'   => $invoiceDate,
    'client_name'    => $clientName,
    'status'         => 'draft',
    'notes'          => sprintf('%d lads × %.1f hour shift at %s', $ladCount, $shiftHours, $location),
], $lines, 1);

if (!is_int($result)) {
    echo "Error saving invoice: {$result}\n";
    exit(1);
}

$invoice = getCommissionInvoiceById($pdo, $result);
echo "\nCommission invoice created:\n";
echo "  Number:   {$invoice['invoice_number']}\n";
echo "  Date:     {$invoiceDate}\n";
echo "  Event:    {$eventName}\n";
echo "  Lads:     {$totals['staff_count']}\n";
echo "  Hours:    {$totals['total_hours_billed']} billed\n";
echo "  Total:    €" . number_format($totals['total_amount'], 2) . " @ €{$hourlyRate}/hr\n";
echo "\nAdmin: invoice-form.php?id={$result}\n";
echo "Print: print-invoice.php?id={$result}\n";
echo "May 2026 list: invoices.php?month=2026-05\n";
