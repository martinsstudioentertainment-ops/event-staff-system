<?php

declare(strict_types=1);

/**
 * Lookup returning staff for apply registration prefill (email + mobile must both match).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/apply-csrf.php';

$csrf = (string) ($_GET['csrf_token'] ?? '');
if (!verifyApplyCsrf($csrf !== '' ? $csrf : null)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session token. Refresh the page and try again.',
    ]);
    exit;
}

$email  = strtolower(trim((string) ($_GET['email'] ?? '')));
$mobile = trim((string) ($_GET['mobile'] ?? ''));

if ($email === '' || $mobile === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Email and mobile are both required.',
    ]);
    exit;
}

$pdo = getMainAdminPdo();
if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Staff lookup is temporarily unavailable.',
    ]);
    exit;
}

if (!function_exists('normalizeMobileNumber')) {
    require_once __DIR__ . '/../includes/phone-numbers.php';
}

$mobileNorm = normalizeMobileNumber($mobile);
if ($mobileNorm === '') {
    echo json_encode([
        'success' => true,
        'exists'  => false,
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT surname, first_name, full_address, eircode, email, mobile,
               date_of_birth, gender, pps_number, bank_iban
        FROM staff_registrations
        WHERE LOWER(TRIM(email)) = :email
          AND mobile = :mobile
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        'email'  => $email,
        'mobile' => $mobileNorm,
    ]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ApplyAPI] check-staff: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Staff lookup failed.',
    ]);
    exit;
}

if (!is_array($staff)) {
    echo json_encode([
        'success' => true,
        'exists'  => false,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'exists'  => true,
    'staff'   => $staff,
]);
