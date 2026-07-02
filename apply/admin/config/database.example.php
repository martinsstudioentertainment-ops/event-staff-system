<?php

declare(strict_types=1);

/**
 * Apply vault database — copy to database.php on the server (gitignored).
 */

$host = 'localhost';
$db   = 'olastofx_apply';
$user = 'YOUR_DB_USER';
$pass = 'YOUR_DB_PASSWORD';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[ApplyDB] Connection failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Service unavailable</title></head>';
    echo '<body style="font-family:sans-serif;padding:2rem;text-align:center;">';
    echo '<h1>Apply system temporarily unavailable</h1>';
    echo '<p>Please try again in a few minutes.</p></body></html>';
    exit;
}
