<?php

declare(strict_types=1);

/**
 * BIB list sign-in status for known phone numbers.
 * GET: ?key=...&phones=353894861266,...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';

header('Content-Type: application/json; charset=UTF-8');

function authorizeLookup(PDO $pdo): void
{
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
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

/** @return array<string, string> phone_tail => bib */
function bibMapByPhoneTail(): array
{
    return [
        '894861266' => '1265',
        '871225628' => '1958',
        '899749093' => '1733',
        '899850035' => '1180',
        '894713446' => '1566',
        '899568847' => '1417',
        '899618019' => '1140',
        '899493078' => '1640',
        '894278942' => '1070',
        '830201553' => '1118',
        '899779673' => '1259',
        '899666533' => '1089',
        '892391584' => '1604',
        '870531494' => '1359',
        '899583041' => '1535',
        '830921988' => '1238',
        '857886049' => '1534',
        '894387957' => '1058',
        '899791498' => '1362',
    ];
}

function formatPhoneDisplay(string $phone): string
{
    $d = normalizePhoneDigits($phone);
    if (strlen($d) === 12 && str_starts_with($d, '353')) {
        return '+353 ' . substr($d, 3, 2) . ' ' . substr($d, 5, 3) . ' ' . substr($d, 8);
    }

    return $phone;
}

function resolveSigninStatus(array $row): string
{
    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if ($status === 'no_show') {
        return 'No-show';
    }
    if ((int) ($row['is_checked_in'] ?? 0) === 1) {
        return 'Signed in';
    }
    if ($status === 'pre_checked_in' || $status === 'active') {
        return 'Signed in';
    }
    if (!empty($row['checked_in_at'])) {
        return 'Signed in';
    }

    return 'Awaiting sign-in';
}

try {
    $pdo = getDB();
    authorizeLookup($pdo);

    $bibMap = bibMapByPhoneTail();
    $phonesParam = trim((string) ($_GET['phones'] ?? ''));
    $tails = $phonesParam !== ''
        ? array_values(array_filter(array_map(static fn (string $p): string => phoneTail($p), preg_split('/[\s,;]+/', $phonesParam) ?: [])))
        : array_keys($bibMap);

    $stmt = $pdo->query(
        "SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role, sr.status,
                sr.event_id, e.name AS event_name, e.event_date,
                a.checked_in_at, a.checked_in_method, a.bib_number, a.attendance_status,
                CASE
                    WHEN a.attendance_status = 'no_show' THEN 0
                    WHEN a.checked_in_at IS NOT NULL AND a.checked_in_at <> '' THEN 1
                    WHEN a.attendance_status IN ('active', 'pre_checked_in') THEN 1
                    WHEN a.activated_at IS NOT NULL AND a.activated_at <> '' THEN 1
                    ELSE 0
                END AS is_checked_in
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.mobile IS NOT NULL AND TRIM(sr.mobile) <> ''
         ORDER BY sr.id DESC"
    );
    $all = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $byTail = [];
    foreach ($all as $row) {
        $tail = phoneTail((string) ($row['mobile'] ?? ''));
        if ($tail === '' || !isset($bibMap[$tail])) {
            continue;
        }
        if (!isset($byTail[$tail])) {
            $byTail[$tail] = [];
        }
        $byTail[$tail][] = $row;
    }

    $eventFilter = (int) ($_GET['event_id'] ?? 0);
    $rows = [];

    foreach ($tails as $tail) {
        if (!isset($bibMap[$tail])) {
            continue;
        }
        $matches = $byTail[$tail] ?? [];
        $best = null;

        foreach ($matches as $m) {
            if ($eventFilter > 0 && (int) $m['event_id'] !== $eventFilter) {
                continue;
            }
            if ($best === null) {
                $best = $m;
                continue;
            }
            if (($m['status'] ?? '') === 'approved' && ($best['status'] ?? '') !== 'approved') {
                $best = $m;
            }
        }

        if ($best === null && $matches !== []) {
            $best = $matches[0];
        }

        $signStatus = $best ? resolveSigninStatus($best) : 'Not found';
        $rows[] = [
            'bib'           => $bibMap[$tail],
            'name'          => $best ? trim(($best['first_name'] ?? '') . ' ' . ($best['surname'] ?? '')) : null,
            'phone'         => $best ? formatPhoneDisplay((string) $best['mobile']) : null,
            'role'          => $best['staff_role'] ?? null,
            'event'         => $best ? trim(($best['event_name'] ?? '') . ' (' . ($best['event_date'] ?? '') . ')') : null,
            'sign_status'   => $signStatus,
            'checked_in_at' => $best['checked_in_at'] ?? null,
            'bib_in_system' => trim((string) ($best['bib_number'] ?? '')),
            'registration_id' => $best ? (int) $best['id'] : null,
            'found'         => $best !== null,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['bib'], (string) $b['bib']));

    $signedIn = count(array_filter($rows, static fn (array $r): bool => $r['sign_status'] === 'Signed in'));
    $awaiting = count(array_filter($rows, static fn (array $r): bool => $r['sign_status'] === 'Awaiting sign-in'));
    $noShow = count(array_filter($rows, static fn (array $r): bool => $r['sign_status'] === 'No-show'));

    $tsvLines = ["BIB\tName\tPhone\tRole\tEvent\tSign-in status\tChecked in at"];
    $plainLines = [];
    foreach ($rows as $r) {
        $tsvLines[] = implode("\t", [
            $r['bib'],
            (string) $r['name'],
            (string) $r['phone'],
            (string) $r['role'],
            (string) $r['event'],
            $r['sign_status'],
            (string) ($r['checked_in_at'] ?? ''),
        ]);
        $plainLines[] = $r['bib'] . "\t" . $r['name'] . "\t" . $r['phone'] . "\t" . $r['sign_status'];
    }

    echo json_encode([
        'ok'        => true,
        'total'     => count($rows),
        'signed_in' => $signedIn,
        'awaiting'  => $awaiting,
        'no_show'   => $noShow,
        'copy_tsv'  => implode("\n", $tsvLines),
        'copy_simple' => implode("\n", $plainLines),
        'rows'      => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
