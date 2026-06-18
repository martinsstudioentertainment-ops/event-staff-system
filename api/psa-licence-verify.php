<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
initSecureSession();
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/psa-licence-verify.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session expired. Refresh and try again.']);
    exit;
}

$licence = (string) ($_POST['psa_licence'] ?? '');
$name    = trim((string) ($_POST['holder_name'] ?? ''));

$result = verifyPsaLicenceBasic($licence, $name !== '' ? $name : null);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
