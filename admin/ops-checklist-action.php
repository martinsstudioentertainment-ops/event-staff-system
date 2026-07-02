<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ops-checklist.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('dashboard');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: ops-checklist.php');
    exit;
}

$pdo = getDB();

if (!empty($_POST['reset'])) {
    setSetting($pdo, 'ops_manual_checks', '{}');
    setAdminFlash('success', 'Ops checklist reset.');
    header('Location: ops-checklist.php');
    exit;
}

$manual = $_POST['manual'] ?? [];
if (!is_array($manual)) {
    $manual = [];
}

foreach (getOpsManualChecklistItems() as $item) {
    $key  = (string) ($item['key'] ?? '');
    $done = !empty($manual[$key]);
    setOpsManualCheck($pdo, $key, $done);
}

setAdminFlash('success', 'Ops checklist saved.');
header('Location: ops-checklist.php');
exit;
