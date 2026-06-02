<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/website-content.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('backups');

$pdo  = getDB();
$type = trim((string) ($_GET['type'] ?? 'settings'));

$payload = [
    'exported_at' => date('c'),
    'app'         => 'event-staff-system',
    'database'    => DB_NAME,
];

if ($type === 'settings' || $type === 'full') {
    $payload['settings'] = getAllSettings($pdo);
}

if ($type === 'website' || $type === 'full') {
    $payload['website_content'] = getWebsiteContent($pdo);
}

if ($type === 'full') {
    $payload['events_count'] = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $payload['staff_count']  = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();
}

if (!in_array($type, ['settings', 'website', 'full'], true)) {
    setAdminFlash('error', 'Unknown backup type.');
    header('Location: backups.php');
    exit;
}

logAdminAudit($pdo, 'export_backup', 'system', 0, $type);

$filename = 'event-staff-' . $type . '-' . date('Y-m-d-His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
