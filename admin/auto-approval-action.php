<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/auto-approval-engine.php';

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: auto-approval.php');
    exit;
}

$pdo    = getDB();
$action = trim((string) ($_POST['action'] ?? ''));

if ($action === 'save_settings') {
    saveAutoApprovalSettings($pdo, $_POST);
    setAdminFlash('success', 'Auto approval settings saved.');
} elseif ($action === 'evaluate_pending') {
    $dryRun = !empty($_POST['dry_run']);
    $stats  = evaluatePendingQueueBatch($pdo, $dryRun);
    $mode   = $dryRun || getAutoApprovalMode($pdo) !== 2 ? 'shadow' : 'live';
    setAdminFlash(
        'success',
        'Pending queue evaluated (' . $mode . '): '
        . (int) $stats['processed'] . ' processed, '
        . (int) $stats['approved'] . ' approved, '
        . (int) $stats['rejected'] . ' rejected, '
        . (int) $stats['shadow'] . ' shadow, '
        . (int) $stats['skipped'] . ' skipped.'
    );
}

header('Location: auto-approval.php');
exit;
