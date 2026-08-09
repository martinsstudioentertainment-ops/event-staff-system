<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/registration-options-repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$formSlug = strtolower(trim((string) ($_GET['form'] ?? '')));

if ($formSlug === '') {
    echo json_encode([
        'ok'      => false,
        'error'   => 'form_required',
        'message' => 'Pass form=dsp, form=static, or form=both.',
        'forms'   => ['dsp', 'static', 'both', 'steward'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo     = getDB();
    $payload = getRegistrationOptionsForForm($pdo, $formSlug);

    if ($payload['form'] === []) {
        http_response_code(404);
        echo json_encode(['error' => 'Registration form not found or disabled.']);
        exit;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] registration-options: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load registration options.']);
}
