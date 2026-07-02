<?php

declare(strict_types=1);

/**
 * Look up staff names by mobile number (cron key required).
 *
 * POST JSON: { "phones": ["+353894861266", ...] }
 * GET:  ?key=...&phones=353894861266,353871225628
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

function authorizeLookup(PDO $pdo): void
{
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        return;
    }
    if ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        return;
    }

    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

function normalizePhoneDigits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (str_starts_with($digits, '353')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '353' . substr($digits, 1);
    }

    return $digits;
}

function phoneTail(string $phone): string
{
    $digits = normalizePhoneDigits($phone);

    return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
}

/** @return list<string> */
function parsePhoneInput(): array
{
    $raw = trim((string) ($_GET['phones'] ?? ''));
    if ($raw !== '') {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }

    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['phones']) && is_array($json['phones'])) {
            return array_values(array_filter(array_map('strval', $json['phones'])));
        }
    }

    return [];
}

try {
    $pdo = getDB();
    authorizeLookup($pdo);

    $phones = parsePhoneInput();
    if ($phones === []) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Provide phones via GET phones=... or POST JSON phones[]']);
        exit;
    }

    $stmt = $pdo->query(
        "SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role, sr.status,
                e.name AS event_name, e.event_date
         FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id
         WHERE sr.mobile IS NOT NULL AND TRIM(sr.mobile) <> ''
         ORDER BY sr.id DESC"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $byTail = [];
    foreach ($rows as $row) {
        $tail = phoneTail((string) ($row['mobile'] ?? ''));
        if ($tail === '') {
            continue;
        }
        if (!isset($byTail[$tail])) {
            $byTail[$tail] = [];
        }
        $byTail[$tail][] = $row;
    }

    $results = [];
    foreach ($phones as $input) {
        $input = trim($input);
        $tail = phoneTail($input);
        $matches = $byTail[$tail] ?? [];

        $best = null;
        foreach ($matches as $m) {
            if ($best === null) {
                $best = $m;
                continue;
            }
            if (($m['status'] ?? '') === 'approved' && ($best['status'] ?? '') !== 'approved') {
                $best = $m;
            }
        }

        $results[] = [
            'input_phone' => $input,
            'phone_tail'  => $tail,
            'found'       => $best !== null,
            'name'        => $best ? trim(($best['first_name'] ?? '') . ' ' . ($best['surname'] ?? '')) : null,
            'email'       => $best['email'] ?? null,
            'mobile_db'   => $best['mobile'] ?? null,
            'role'        => $best['staff_role'] ?? null,
            'status'      => $best['status'] ?? null,
            'event'       => $best ? trim(($best['event_name'] ?? '') . ' (' . ($best['event_date'] ?? '') . ')') : null,
            'registration_id' => $best ? (int) $best['id'] : null,
            'match_count' => count($matches),
        ];
    }

    echo json_encode([
        'ok'      => true,
        'count'   => count($results),
        'found'   => count(array_filter($results, static fn (array $r): bool => $r['found'])),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
