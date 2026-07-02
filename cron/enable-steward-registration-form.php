<?php

declare(strict_types=1);

/**
 * One-time: enable Steward registration form in system_settings.
 * DELETE after use or restrict to cron key.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/registration-forms.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pdo   = getDB();
    $forms = getRegistrationForms($pdo);

    if (!isset($forms['steward'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Steward form not found in registration forms.']);
        exit;
    }

    $forms['steward']['enabled'] = true;
    saveRegistrationForms($pdo, $forms);

    $enabled = getRegistrationForm($pdo, 'steward');

    echo json_encode([
        'ok'      => $enabled !== null,
        'enabled' => $enabled !== null,
        'url'     => getRegistrationFormUrl($pdo, 'steward'),
        'label'   => (string) ($forms['steward']['label'] ?? 'Steward'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] enable-steward-registration-form: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not enable steward form.']);
}
