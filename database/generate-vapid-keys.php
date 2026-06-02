<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pwa-push.php';
require_once __DIR__ . '/../includes/pwa-schema.php';

$pdo = getDB();
ensurePwaSchema($pdo);

if (ensureVapidKeys($pdo)) {
    echo "VAPID keys ready.\n";
    echo "Public:  " . getVapidPublicKey($pdo) . "\n";
} else {
    echo "Failed — enable OpenSSL in PHP.\n";
    exit(1);
}
