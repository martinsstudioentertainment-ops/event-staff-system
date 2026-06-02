<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('export');

$pdo      = getDB();
$eventId  = (int) ($_GET['event_id'] ?? 0);
$workDate = trim((string) ($_GET['work_date'] ?? ''));
$list     = getWorkHoursList($pdo, $eventId, $workDate);
$totals   = getWorkHoursTotals($pdo, $eventId, $workDate);

$filename = 'work-hours-' . date('Y-m-d-His') . '.csv';

logAdminAudit($pdo, 'export_work_hours', 'event', $eventId > 0 ? $eventId : null, count($list) . ' rows');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($output, [
    'Name', 'Gender', 'Email', 'Role', 'Event', 'Event date', 'Signed in', 'Shift end',
    'Scheduled hours', 'Hours worked', 'Hours payable', 'Adjustment note',
]);

foreach ($list as $row) {
    fputcsv($output, [
        trim($row['first_name'] . ' ' . $row['surname']),
        formatGenderLabel((string) $row['gender']),
        $row['email'],
        formatRoleLabel($row['staff_role']),
        formatEventLabel($row),
        $row['event_date'],
        $row['checked_in_at'],
        $row['work_end_at'] ?? '',
        $row['scheduled_hours'],
        $row['hours_worked'],
        $row['hours_paid'],
        $row['hours_note'] ?? '',
    ]);
}

fputcsv($output, []);
fputcsv($output, ['TOTALS', '', '', '', '', '', '', '', $totals['scheduled_hours'], $totals['hours_worked'], $totals['hours_paid'], '']);

fclose($output);
exit;
