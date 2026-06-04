<?php

declare(strict_types=1);

/**
 * Main Event Staff database (admin_users for shared login).
 * Copy to eventstaff-database.php and set credentials.
 */

$host = 'localhost';
$db   = 'olastofx_eventstaff';
$user = 'YOUR_DB_USER';
$pass = 'YOUR_DB_PASSWORD';

try {
    $eventPdo = new PDO(
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
    die('Event Database connection failed: ' . $e->getMessage());
}
