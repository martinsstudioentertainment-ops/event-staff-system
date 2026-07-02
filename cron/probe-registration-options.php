<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/registration-options-repository.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$formSlug = strtolower(trim((string) ($_GET['form'] ?? 'steward')));

try {
    $pdo     = getDB();
    $payload = getRegistrationOptionsForForm($pdo, $formSlug);
    $events  = 0;
    foreach ($payload['eventsByVenue'] ?? [] as $list) {
        $events += is_array($list) ? count($list) : 0;
    }
    echo json_encode([
        'ok'         => true,
        'formSlug'   => $formSlug,
        'form'       => $payload['form'] ?? [],
        'venueCount' => count($payload['venues'] ?? []),
        'eventCount' => $events,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
