<?php

declare(strict_types=1);

/**
 * Verify BIB capture on web (schema, validation, deployed files, live HTML).
 *
 * Web: /cron/bib-web-verify.php?key=email-encoding-verify-20260606
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/site-urls.php';
require_once dirname(__DIR__) . '/includes/sensitive-data.php';

header('Content-Type: application/json; charset=UTF-8');

function authorizeBibVerifyCron(PDO $pdo): void
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

function runValidationTests(): array
{
    $tests = [];

    $requiredEmpty = parseCheckinBibNumber('', true);
    $tests[] = [
        'name'   => 'required_empty_rejected',
        'pass'   => !$requiredEmpty['ok'] && $requiredEmpty['error'] !== '',
        'detail' => $requiredEmpty,
    ];

    $optionalEmpty = parseCheckinBibNumber('', false);
    $tests[] = [
        'name'   => 'optional_empty_allowed',
        'pass'   => $optionalEmpty['ok'] && $optionalEmpty['bib'] === null,
        'detail' => $optionalEmpty,
    ];

    $valid = parseCheckinBibNumber('  abc-123  ', true);
    $tests[] = [
        'name'   => 'valid_bib_normalized',
        'pass'   => $valid['ok'] && $valid['bib'] === 'ABC-123',
        'detail' => $valid,
    ];

    $invalid = parseCheckinBibNumber('bad bib!', true);
    $tests[] = [
        'name'   => 'invalid_chars_rejected',
        'pass'   => !$invalid['ok'],
        'detail' => $invalid,
    ];

    $methods = [
        'self'  => isBibRequiredForCheckinMethod('self'),
        'scan'  => isBibRequiredForCheckinMethod('scan'),
        'admin' => isBibRequiredForCheckinMethod('admin'),
    ];
    $tests[] = [
        'name'   => 'method_requirements',
        'pass'   => $methods['self'] === true && $methods['scan'] === true && $methods['admin'] === false,
        'detail' => $methods,
    ];

    return $tests;
}

function fileContains(string $path, string $needle): bool
{
    if (!is_readable($path)) {
        return false;
    }

    return str_contains((string) file_get_contents($path), $needle);
}

function runDeployedFileTests(string $root): array
{
    $checks = [
        ['includes/checkin-bib.php', 'parseCheckinBibNumber'],
        ['includes/event-sign-flow.php', 'name="bib_number"'],
        ['includes/attendance-repository.php', 'bib_number'],
        ['admin/scan-checkin.php', 'id="scan-bib-number"'],
        ['admin/scan-checkin-action.php', 'bib_number'],
        ['assets/js/admin-scan-checkin.js', "body.append('bib_number'"],
        ['admin/attendance.php', '>BIB<'],
        ['admin/export-attendance.php', 'BIB Number'],
        ['includes/event-signin-export.php', 'BIB number'],
        ['admin/print-roster.php', '>BIB<'],
    ];

    $out = [];
    foreach ($checks as [$rel, $needle]) {
        $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $out[] = [
            'file'   => $rel,
            'pass'   => fileContains($path, $needle),
            'exists' => is_readable($path),
        ];
    }

    return $out;
}

function fetchUrl(string $url): array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 15,
            'ignore_errors' => true,
            'user_agent'    => 'OlasentraBibVerify/1.0',
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'url'    => $url,
        'status' => $status,
        'body'   => is_string($body) ? $body : '',
    ];
}

function httpPostForm(string $url, array $fields): array
{
    $body = http_build_query($fields);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 20,
            'ignore_errors' => true,
            'user_agent'    => 'OlasentraBibVerify/1.0',
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $html = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'url'    => $url,
        'status' => $status,
        'body'   => is_string($html) ? $html : '',
    ];
}

function runInternalSigninRenderTests(PDO $pdo): array
{
    require_once dirname(__DIR__) . '/includes/event-sign-flow.php';

    $stmt = $pdo->query(
        "SELECT sr.id, e.signin_token
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.status = 'approved'
           AND e.signin_token IS NOT NULL AND TRIM(e.signin_token) <> ''
         ORDER BY sr.id DESC
         LIMIT 1"
    );
    $meta = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    if (!is_array($meta)) {
        return [[
            'name'    => 'internal_checkin_panel_bib',
            'pass'    => true,
            'skipped' => true,
            'detail'  => 'No approved registration for render test',
        ]];
    }

    $row = getStaffRegistrationById($pdo, (int) $meta['id']);
    $event = getEventBySigninToken($pdo, (string) $meta['signin_token']);

    if (!is_array($row) || !is_array($event)) {
        return [[
            'name' => 'internal_checkin_panel_bib',
            'pass' => false,
            'detail' => 'Could not load registration/event for render test',
        ]];
    }

    $token = (string) $meta['signin_token'];
    $siteName = getSiteName($pdo);
    $baseState = [
        'message'          => '',
        'type'             => '',
        'event'            => $event,
        'row'              => $row,
        'checkedIn'        => false,
        'window'           => getEventCheckinWindow($event),
        'showEmailForm'    => false,
        'showStaffPanel'   => true,
        'showCheckinPanel' => true,
        'eligibility'      => ['allowed' => true, 'message' => ''],
        'formEmail'        => (string) ($row['email'] ?? ''),
        'formPpsLast4'     => '',
        'formBibNumber'    => '',
    ];

    ob_start();
    renderEventSigninPage($baseState, $token, false, $siteName);
    $panelHtml = (string) ob_get_clean();

    $errorState = $baseState;
    $errorState['message'] = 'Enter the bib number you were given today.';
    $errorState['type'] = 'error';

    ob_start();
    renderEventSigninPage($errorState, $token, false, $siteName);
    $errorHtml = (string) ob_get_clean();

    return [
        [
            'name'          => 'internal_checkin_panel_bib',
            'pass'          => str_contains($panelHtml, 'name="bib_number"')
                && str_contains($panelHtml, 'id="bib_number"')
                && str_contains($panelHtml, 'BIB number'),
            'registration_id' => (int) $row['id'],
        ],
        [
            'name' => 'internal_bib_error_keeps_form',
            'pass' => str_contains($errorHtml, 'Enter the bib number you were given today.')
                && str_contains($errorHtml, 'name="bib_number"')
                && str_contains($errorHtml, 'signin-checkin-panel'),
            'registration_id' => (int) $row['id'],
        ],
    ];
}

function findRegistrationOnOpenCheckinWindow(PDO $pdo): ?array
{
    $events = $pdo->query(
        "SELECT *
         FROM events
         WHERE signin_token IS NOT NULL AND TRIM(signin_token) <> ''
         ORDER BY event_date ASC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($events as $event) {
        $window = getEventCheckinWindow($event);
        if (!($window['is_open'] ?? false)) {
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT sr.id, sr.email, sr.pps_number
             FROM staff_registrations sr
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.event_id = :event_id
               AND sr.status = 'approved'
               AND (a.id IS NULL OR a.checked_in_at IS NULL OR a.checked_in_at = '')
               AND (a.attendance_status IS NULL OR a.attendance_status <> 'no_show')
             ORDER BY sr.id DESC
             LIMIT 1"
        );
        $stmt->execute(['event_id' => (int) $event['id']]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($reg)) {
            return [
                'event' => $event,
                'registration' => $reg,
            ];
        }
    }

    return null;
}

function runSigninPanelHtmlTest(PDO $pdo): array
{
    $ctx = findRegistrationOnOpenCheckinWindow($pdo);

    if ($ctx === null) {
        return [
            'pass'    => true,
            'skipped' => true,
            'detail'  => 'No open check-in window event with unchecked staff (expected between events)',
        ];
    }

    $event = $ctx['event'];
    $row = $ctx['registration'];
    $token = trim((string) $event['signin_token']);
    $email = trim((string) $row['email']);
    $ppsLast4 = getPpsLastFour((string) ($row['pps_number'] ?? ''));
    $base = rtrim(getRegistrationSiteUrl($pdo), '/');
    $signUrl = $base . '/sign-in.php?e=' . rawurlencode($token);

    $post = httpPostForm($signUrl, [
        'e'         => $token,
        'email'     => $email,
        'pps_last4' => $ppsLast4,
    ]);

    $hasBibField = str_contains($post['body'], 'name="bib_number"')
        && str_contains($post['body'], 'id="bib_number"')
        && str_contains($post['body'], 'BIB number');

    return [
        'pass'            => $post['status'] === 200 && $hasBibField,
        'status'          => $post['status'],
        'url'             => $signUrl,
        'registration_id' => (int) $row['id'],
        'event'           => (string) ($event['name'] ?? ''),
        'has_bib_field'   => $hasBibField,
    ];
}

function runLivePageTests(PDO $pdo): array
{
    $out = [];

    $js = fetchUrl('https://admin.olasentra.com/assets/js/admin-scan-checkin.js');
    $out[] = [
        'name'   => 'scan_js_deployed',
        'pass'   => $js['status'] === 200 && str_contains($js['body'], "body.append('bib_number'"),
        'status' => $js['status'],
    ];

    $signinPanel = runSigninPanelHtmlTest($pdo);
    $out[] = array_merge(['name' => 'signin_checkin_panel_bib_field'], $signinPanel);

    return $out;
}

function runSchemaTest(PDO $pdo): array
{
    ensureAttendanceBibSchema($pdo);
    $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return [
        'pass'   => in_array('bib_number', $cols, true),
        'column' => 'bib_number',
        'type'   => null,
    ];
}

function runCheckinPostRejectionTest(PDO $pdo): array
{
    $ctx = findRegistrationOnOpenCheckinWindow($pdo);

    if ($ctx === null) {
        return [
            'pass'    => true,
            'skipped' => true,
            'detail'  => 'No open check-in window — server-side recordCheckin rejection test covers enforcement',
        ];
    }

    $event = $ctx['event'];
    $row = $ctx['registration'];
    $token = trim((string) $event['signin_token']);
    $email = trim((string) $row['email']);
    $ppsLast4 = getPpsLastFour((string) ($row['pps_number'] ?? ''));
    $base = rtrim(getRegistrationSiteUrl($pdo), '/');
    $signUrl = $base . '/sign-in.php?e=' . rawurlencode($token);

    $post = httpPostForm($signUrl, [
        'e'               => $token,
        'action'          => 'checkin',
        'registration_id' => (int) $row['id'],
        'email'           => $email,
        'pps_last4'       => $ppsLast4,
        'bib_number'      => '',
    ]);

    $rejected = str_contains($post['body'], 'Enter the bib number you were given today.');

    return [
        'pass'            => $post['status'] === 200 && $rejected,
        'registration_id' => (int) $row['id'],
        'event'           => (string) ($event['name'] ?? ''),
        'rejected'        => $rejected,
    ];
}

function runAttendanceListBibFieldTest(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT sr.id
         FROM staff_registrations sr
         INNER JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = 'approved'
         ORDER BY a.id DESC
         LIMIT 1"
    );
    $regId = $stmt ? (int) ($stmt->fetchColumn() ?: 0) : 0;

    if ($regId < 1) {
        return [
            'pass'    => true,
            'skipped' => true,
            'detail'  => 'No attendance rows to test list query',
        ];
    }

    $regStmt = $pdo->prepare('SELECT event_id FROM staff_registrations WHERE id = :id LIMIT 1');
    $regStmt->execute(['id' => $regId]);
    $eventId = (int) ($regStmt->fetchColumn() ?: 0);
    $list = getAttendanceList($pdo, $eventId);
    $match = null;
    foreach ($list as $item) {
        if ((int) ($item['id'] ?? 0) === $regId) {
            $match = $item;
            break;
        }
    }

    return [
        'pass'            => is_array($match) && array_key_exists('bib_number', $match),
        'registration_id' => $regId,
        'has_bib_key'     => is_array($match) && array_key_exists('bib_number', $match),
    ];
}

function runRecordCheckinRejectionTest(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT sr.id
         FROM staff_registrations sr
         INNER JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = 'approved'
           AND (a.checked_in_at IS NULL OR a.checked_in_at = '')
           AND (a.attendance_status IS NULL OR a.attendance_status NOT IN ('no_show'))
         ORDER BY sr.id DESC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    if (!is_array($row)) {
        $stmt2 = $pdo->query(
            "SELECT sr.id
             FROM staff_registrations sr
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.status = 'approved'
               AND (a.id IS NULL OR a.checked_in_at IS NULL OR a.checked_in_at = '')
             ORDER BY sr.id DESC
             LIMIT 1"
        );
        $row = $stmt2 ? $stmt2->fetch(PDO::FETCH_ASSOC) : false;
    }

    if (!is_array($row)) {
        return [
            'pass'   => true,
            'skipped'=> true,
            'detail' => 'No unchecked-in approved registration available; validation-only tests used',
        ];
    }

    $regId = (int) $row['id'];
    $result = recordCheckin($pdo, $regId, 'self', null, null);

    return [
        'pass'            => is_string($result) && str_contains(strtolower($result), 'bib'),
        'registration_id' => $regId,
        'result'          => $result,
    ];
}

function runBibSaveRollbackTest(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT sr.id
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = 'approved'
           AND (a.id IS NULL OR (a.checked_in_at IS NULL OR a.checked_in_at = ''))
         ORDER BY sr.id DESC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    if (!is_array($row)) {
        return [
            'pass'    => true,
            'skipped' => true,
            'detail'  => 'No registration available for rollback save test',
        ];
    }

    $regId = (int) $row['id'];
    $testBib = 'VERIFY-' . date('His');

    try {
        $pdo->beginTransaction();

        $exists = $pdo->prepare('SELECT id FROM attendance WHERE registration_id = :id LIMIT 1');
        $exists->execute(['id' => $regId]);
        $attId = (int) ($exists->fetchColumn() ?: 0);

        if ($attId < 1) {
            $eventStmt = $pdo->prepare('SELECT event_id FROM staff_registrations WHERE id = :id LIMIT 1');
            $eventStmt->execute(['id' => $regId]);
            $eventId = (int) ($eventStmt->fetchColumn() ?: 0);
            if ($eventId < 1) {
                $pdo->rollBack();

                return ['pass' => false, 'error' => 'Registration has no event_id'];
            }

            $insert = $pdo->prepare(
                'INSERT INTO attendance (registration_id, event_id, checked_in_method) VALUES (:registration_id, :event_id, :method)'
            );
            $insert->execute([
                'registration_id' => $regId,
                'event_id'        => $eventId,
                'method'          => 'admin',
            ]);
        }

        saveAttendanceBibNumber($pdo, $regId, $testBib);
        $check = $pdo->prepare('SELECT bib_number FROM attendance WHERE registration_id = :id LIMIT 1');
        $check->execute(['id' => $regId]);
        $saved = (string) ($check->fetchColumn() ?: '');
        $pdo->rollBack();

        return [
            'pass'            => $saved === normalizeCheckinBibNumber($testBib),
            'registration_id' => $regId,
            'saved'           => $saved,
            'expected'        => normalizeCheckinBibNumber($testBib),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'pass'  => false,
            'error' => $e->getMessage(),
        ];
    }
}

try {
    $pdo = getDB();
    authorizeBibVerifyCron($pdo);

    $root = dirname(__DIR__);
    $validation = runValidationTests();
    $files = runDeployedFileTests($root);
    $schema = runSchemaTest($pdo);
    $live = runLivePageTests($pdo);
    $render = runInternalSigninRenderTests($pdo);
    $reject = runRecordCheckinRejectionTest($pdo);
    $postReject = runCheckinPostRejectionTest($pdo);
    $listBib = runAttendanceListBibFieldTest($pdo);
    $save = runBibSaveRollbackTest($pdo);

    $sections = [
        'validation' => $validation,
        'files'      => $files,
        'schema'     => [$schema],
        'live'       => $live,
        'render'     => $render,
        'reject'     => [$reject, $postReject],
        'list'       => [$listBib],
        'save'       => [$save],
    ];

    $allPass = true;
    foreach ($sections as $items) {
        foreach ($items as $item) {
            if (!empty($item['skipped'])) {
                continue;
            }
            if (($item['pass'] ?? false) !== true) {
                $allPass = false;
                break 2;
            }
        }
    }

    echo json_encode([
        'ok'        => $allPass,
        'verified_at' => date('c'),
        'all_pass'  => $allPass,
        'sections'  => $sections,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
