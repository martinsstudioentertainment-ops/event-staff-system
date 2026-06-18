<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workforce/staff-preferences.php';

requireAdminCapability('export');

$pdo = getDB();
ensureStaffPreferencesFoundationSchema($pdo);

$filters = [
    'q'                => trim((string) ($_GET['q'] ?? '')),
    'shift_type'       => trim((string) ($_GET['shift_type'] ?? '')),
    'location'         => trim((string) ($_GET['location'] ?? '')),
    'role'             => trim((string) ($_GET['role'] ?? '')),
    'availability_day' => trim((string) ($_GET['availability_day'] ?? '')),
    'cert_type'        => trim((string) ($_GET['cert_type'] ?? '')),
];

$rows = staffPreferencesAdminList($pdo, $filters);

$filename = 'staff-preferences-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}

fputcsv($out, [
    'staff_id',
    'first_name',
    'surname',
    'email',
    'mobile',
    'staff_role',
    'preferred_shift_types',
    'preferred_locations',
    'preferred_roles',
    'availability_days',
    'availability_hours',
    'updated_at',
]);

foreach ($rows as $row) {
    $prefs = staffPreferencesRowToPayload($row);
    fputcsv($out, [
        (int) ($row['staff_id'] ?? 0),
        (string) ($row['first_name'] ?? ''),
        (string) ($row['surname'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['mobile'] ?? ''),
        (string) ($row['staff_role'] ?? ''),
        implode('|', $prefs['preferred_shift_types']),
        implode('|', $prefs['preferred_locations']),
        implode('|', $prefs['preferred_roles']),
        implode('|', $prefs['availability_days']),
        implode('|', $prefs['availability_hours']),
        (string) ($row['updated_at'] ?? ''),
    ]);
}

fclose($out);
exit;
