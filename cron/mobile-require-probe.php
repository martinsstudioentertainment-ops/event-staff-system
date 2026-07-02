<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$paths = [
    'staff-repository.php',
    'staff-onboarding.php',
    'staff-profile-gate.php',
    'staff-portal-dashboard.php',
    'automation/staff-portal.php',
    'sensitive-data.php',
    'validation.php',
    'mobile/mobile-request.php',
    'staff-app-v3-data.php',
    'staff-portal-shift.php',
    'staff-venue-checkin.php',
    'staff-messages.php',
    'notification-center.php',
    'status-repository.php',
    'events-repository.php',
    'attendance-repository.php',
    'company.php',
    'automation/staff-self-service.php',
    'automation/automation-schema.php',
    'workforce/staff-availability.php',
    'mobile/mappers/MobileShiftMapper.php',
    'mobile/mappers/MobileAvailabilityMapper.php',
    'attendance-gps-phase15.php',
    'attendance-gps-signout.php',
    'maps.php',
];

$steps = [];
$root = dirname(__DIR__) . '/includes/';
foreach ($paths as $rel) {
    $full = $root . $rel;
    if (!is_file($full)) {
        $steps[] = ['file' => $rel, 'ok' => false, 'error' => 'missing on disk'];
        continue;
    }
    try {
        require_once $full;
        $steps[] = ['file' => $rel, 'ok' => true];
    } catch (Throwable $e) {
        $steps[] = [
            'file'  => $rel,
            'ok'    => false,
            'error' => $e->getMessage(),
            'path'  => $e->getFile(),
            'line'  => $e->getLine(),
        ];
    }
}

restore_error_handler();
echo json_encode(['ok' => true, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
