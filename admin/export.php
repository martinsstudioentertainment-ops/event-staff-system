<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-employee-export.php';
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

fputcsv($output, getEmployeeSpreadsheetHeaders());

$exportedIds = [];

foreach ($rows as $row) {
    $exportedIds[] = (int) $row['id'];
    fputcsv($output, buildEmployeeSpreadsheetRow($row));
}

fclose($output);
markRowsExported($pdo, $exportedIds);
logAdminAudit($pdo, 'export_staff', 'export', null, count($exportedIds) . ' rows exported');
exit;
