<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

$result = run_apply_google_sheets_sync($pdo);

if (!empty($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

header('Location: ../admin/sync-sheets.php?' . ($result['ok'] ? 'ok=1' : 'error=1'));
exit;
