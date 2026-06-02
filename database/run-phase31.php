<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/google-sheets-schema.php';

$pdo = getDB();
ensureGoogleSheetsSchema($pdo);
echo "Phase 31 Google Sheets columns ready.\n";
