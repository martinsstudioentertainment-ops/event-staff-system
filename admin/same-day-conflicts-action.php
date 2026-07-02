<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: same-day-conflicts.php');
    exit;
}

$pdo      = getDB();
$fromDate = trim((string) ($_POST['from'] ?? ''));
if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}

$result = rejectSameDayDuplicateShifts($pdo, $fromDate !== '' ? $fromDate : null);

if ($result['rejected'] > 0) {
    $msg = 'Cancelled ' . (int) $result['rejected'] . ' duplicate shift(s). Kept '
        . (int) $result['kept'] . ' first pick(s) (earliest registration per day).';
    if ($result['errors'] !== []) {
        $msg .= ' ' . count($result['errors']) . ' error(s): ' . implode('; ', array_slice($result['errors'], 0, 3));
    }
    setAdminFlash('success', $msg);
} elseif ($result['groups'] === 0) {
    setAdminFlash('success', 'No same-day double bookings to cancel.');
} else {
    setAdminFlash('warning', 'Found conflicts but nothing was rejected. '
        . implode('; ', array_slice($result['errors'], 0, 5)));
}

$redirect = 'same-day-conflicts.php';
if ($fromDate !== '') {
    $redirect .= '?from=' . rawurlencode($fromDate);
}
header('Location: ' . $redirect);
exit;
