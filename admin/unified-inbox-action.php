<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/unified-inbox.php';

requireAdminCapability('dashboard');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: unified-inbox.php');
    exit;
}

$pdo        = getDB();
$sourceType = trim((string) ($_POST['source_type'] ?? ''));
$sourceId   = (int) ($_POST['source_id'] ?? 0);
$action     = trim((string) ($_POST['action'] ?? ''));

if ($sourceId < 1 || $sourceType === '') {
    setAdminFlash('error', 'Invalid inbox item.');
    header('Location: unified-inbox.php');
    exit;
}

match ($action) {
    'read'      => markUnifiedInboxItemRead($pdo, $sourceType, $sourceId),
    'archive'   => archiveUnifiedInboxItem($pdo, $sourceType, $sourceId, (int) (getAdminUser()['id'] ?? 0)),
    'unarchive' => unarchiveUnifiedInboxItem($pdo, $sourceType, $sourceId),
    default     => null,
};

setAdminFlash('success', 'Inbox updated.');
header('Location: unified-inbox.php');
exit;
