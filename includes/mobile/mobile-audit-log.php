<?php

declare(strict_types=1);

require_once __DIR__ . '/schema/mobile-api-schema.php';
require_once __DIR__ . '/mobile-request.php';

function mobileAuditLog(
    PDO $pdo,
    string $endpoint,
    string $method,
    int $statusCode,
    ?int $staffId = null
): void {
    try {
        ensureMobileApiSchema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO mobile_api_audit (staff_id, endpoint, method, ip_address, status_code)
             VALUES (:staff_id, :endpoint, :method, :ip, :status)'
        );
        $stmt->execute([
            'staff_id' => $staffId,
            'endpoint' => substr($endpoint, 0, 128),
            'method'   => strtoupper(substr($method, 0, 8)),
            'ip'       => mobileClientIp(),
            'status'   => max(0, min($statusCode, 999)),
        ]);
    } catch (Throwable $e) {
        error_log('[MobileAPI] audit: ' . $e->getMessage());
    }
}
