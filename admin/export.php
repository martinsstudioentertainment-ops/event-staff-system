<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('export');

$pdo     = getDB();
$filters = getStaffFiltersFromRequest();
$rows    = getExportRows($pdo, $filters);

$filename = 'staff-registrations-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, [
    'Surname',
    'First Name',
    'Full Address',
    'Eircode',
    'Latitude',
    'Longitude',
    'Email Address',
    'Mobile Number',
    'Date of Birth',
    'Gender',
    'NI / PPS Number',
    'Bank Account / IBAN',
    'DSP / Static / Steward',
    'Event',
    'Status',
    'Registered At',
    'Exported At',
]);

$exportedIds = [];

foreach ($rows as $row) {
    $exportedIds[] = (int) $row['id'];

    fputcsv($output, [
        $row['surname'],
        $row['first_name'],
        $row['full_address'],
        $row['eircode'],
        $row['location_lat'] ?? '',
        $row['location_lng'] ?? '',
        $row['email'],
        $row['mobile'],
        $row['date_of_birth'],
        formatGenderLabel($row['gender']),
        $row['pps_number'],
        $row['bank_iban'],
        formatRoleLabel($row['staff_role']),
        formatEventLabel($row),
        formatStatusLabel($row['status']),
        $row['created_at'],
        $row['exported_at'] ?? '',
    ]);
}

fclose($output);
markRowsExported($pdo, $exportedIds);
logAdminAudit($pdo, 'export_staff', 'export', null, count($exportedIds) . ' rows exported');
exit;
