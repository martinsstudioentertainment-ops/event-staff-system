<?php
declare(strict_types=1);
/** POST JSON {key, email, mobile, response_id?, dry_run?, status_only?}. Key = setting aviva_match_intake_key. */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/aviva-match.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = getDB();
$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) { $body = $_POST; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { // public counter, no PII
    $c = avivaMatchCount($pdo);
    echo json_encode(['ok' => true, 'count' => $c, 'capacity' => AVIVA_MATCH_CAPACITY, 'full' => $c >= AVIVA_MATCH_CAPACITY]);
    exit;
}
$expected = trim((string) getSetting($pdo, 'aviva_match_intake_key', ''));
if ($expected === '' || !hash_equals($expected, trim((string) ($body['key'] ?? '')))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden.']);
    exit;
}
try {
    $r = avivaMatchSignup($pdo, $body, !empty($body['dry_run']));
    if (empty($r['ok'])) { http_response_code(422); }
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] aviva-match-intake: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error.']);
}
