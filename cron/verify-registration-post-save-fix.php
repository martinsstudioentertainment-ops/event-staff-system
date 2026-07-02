<?php

declare(strict_types=1);

/**
 * Verify Fix 1 — registration post-save runs once and creates admin notifications.
 *
 *   ?key=CRON_KEY&run=1
 *   ?key=CRON_KEY&cleanup=1&run_id=YYYYMMDDhhmmss
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/registration-forms.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/registration-post-save.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/financial-field-validation.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileEventsService.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';

header('Content-Type: application/json; charset=UTF-8');

function vpsUniquePps(string $runId, string $salt): string
{
    $digits = preg_replace('/\D/', '', hash('sha256', $runId . '|' . $salt . '|' . bin2hex(random_bytes(4))));

    return substr(str_pad($digits, 7, '0', STR_PAD_LEFT), 0, 7) . 'T';
}

function vpsUniqueMobile(string $runId, string $salt): string
{
    $n = abs(crc32($runId . $salt . bin2hex(random_bytes(2)))) % 10000000;

    return '087' . str_pad((string) $n, 7, '0', STR_PAD_LEFT);
}

/**
 * @return array{count: int, ids: list<int>}
 */
function vpsAdminNotifForReg(PDO $pdo, int $registrationId): array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM app_notifications
         WHERE audience = 'admin' AND type = 'registration' AND related_id = :rid
         ORDER BY id ASC"
    );
    $stmt->execute(['rid' => $registrationId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    return ['count' => count($ids), 'ids' => $ids];
}

/**
 * @return array<string, mixed>|null
 */
function vpsFindOpenEvent(PDO $pdo): ?array
{
    $events = getEventsOpenForRegistration($pdo);
    foreach ($events as $event) {
        if ((int) ($event['id'] ?? 0) > 0) {
            return $event;
        }
    }

    $row = $pdo->query(
        "SELECT * FROM events WHERE is_active = 1 AND event_date >= CURDATE() ORDER BY event_date ASC, id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array{ok: bool, registration_id: int, staff_role: string, evidence: array<string, mixed>}
 */
function vpsRunPathTest(
    PDO $pdo,
    string $label,
    string $email,
    array $data,
    int $eventId,
    string $path
): array {
    $event = getEventById($pdo, $eventId);
    $role  = $event !== null
        ? resolveStaffRoleForEventRegistration((string) ($data['staff_role'] ?? ''), $event)
        : normalizeStaffRole((string) ($data['staff_role'] ?? ''));

    try {
        $regId = saveRegistration($pdo, $data, $eventId, $role);
    } catch (Throwable $e) {
        return [
            'ok'              => false,
            'registration_id' => 0,
            'staff_role'      => (string) ($data['staff_role'] ?? ''),
            'evidence'        => ['label' => $label, 'path' => $path, 'save_error' => $e->getMessage()],
        ];
    }

    $ids = [$regId];

    if ($path === 'web_post_save') {
        runRegistrationPostSaveSafely($pdo, $data, $ids, [$eventId], $email);
        $beforeDup = vpsAdminNotifForReg($pdo, $regId);
        runRegistrationPostSaveSafely($pdo, $data, $ids, [$eventId], $email);
        $afterDup = vpsAdminNotifForReg($pdo, $regId);
    } elseif ($path === 'mobile_service') {
        $staff = getStaffByEmail($pdo, $email);
        if ($staff === null) {
            return ['ok' => false, 'registration_id' => $regId, 'staff_role' => '', 'evidence' => ['error' => 'staff_missing_for_mobile']];
        }
        // Registration already created — exercise mobile post-save hook via jobs directly.
        runRegistrationPostSaveSafely($pdo, $data, $ids, [$eventId], $email);
        $beforeDup = vpsAdminNotifForReg($pdo, $regId);
        runRegistrationPostSaveSafely($pdo, $data, $ids, [$eventId], $email);
        $afterDup = vpsAdminNotifForReg($pdo, $regId);
    } else {
        return ['ok' => false, 'registration_id' => $regId, 'staff_role' => '', 'evidence' => ['error' => 'unknown_path']];
    }

    $ok = $regId > 0
        && $beforeDup['count'] === 1
        && $afterDup['count'] === 1;

    return [
        'ok'              => $ok,
        'registration_id' => $regId,
        'staff_role'      => (string) ($data['staff_role'] ?? ''),
        'evidence'        => [
            'label'              => $label,
            'path'               => $path,
            'admin_notif_before' => $beforeDup,
            'admin_notif_after'  => $afterDup,
            'idempotent'         => $beforeDup['count'] === 1 && $afterDup['count'] === 1,
        ],
    ];
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $runId = trim((string) ($_GET['run_id'] ?? gmdate('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 4)));

    if (isset($_GET['cleanup']) && (string) $_GET['cleanup'] === '1') {
        $emails = [
            'vps-steward-' . $runId . '@olasentra-e2e.test',
            'vps-psa-' . $runId . '@olasentra-e2e.test',
            'vps-mobile-' . $runId . '@olasentra-e2e.test',
        ];
        $purged = [];
        foreach ($emails as $email) {
            $purged[$email] = purgeRegistrantCompletely($pdo, $email, false);
        }

        echo json_encode(['ok' => true, 'run_id' => $runId, 'purged' => $purged], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!isset($_GET['run']) || (string) $_GET['run'] !== '1') {
        echo json_encode([
            'ok'      => true,
            'message' => 'Pass run=1 to execute verification. Pass cleanup=1&run_id=… to remove test data.',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $openEvents = getEventsOpenForRegistration($pdo);
    $openEventIds = [];
    foreach ($openEvents as $ev) {
        $eid = (int) ($ev['id'] ?? 0);
        if ($eid > 0) {
            $openEventIds[] = $eid;
        }
    }
    if (count($openEventIds) < 2) {
        $fallback = $pdo->query(
            "SELECT id FROM events WHERE is_active = 1 AND event_date >= CURDATE() ORDER BY event_date ASC, id ASC LIMIT 6"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($fallback as $fid) {
            $fid = (int) $fid;
            if ($fid > 0 && !in_array($fid, $openEventIds, true)) {
                $openEventIds[] = $fid;
            }
        }
    }
    if ($openEventIds === []) {
        exit(json_encode(['ok' => false, 'error' => 'No open event for verification'], JSON_PRETTY_PRINT));
    }
    $eventId        = $openEventIds[0];
    $psaEventId     = $openEventIds[1] ?? $openEventIds[0];
    $mobileSeedId   = $openEventIds[0];
    $mobileSecondId = 0;
    foreach ($openEventIds as $candidateId) {
        if ($candidateId !== $mobileSeedId) {
            $mobileSecondId = $candidateId;
            break;
        }
    }
    $base    = [
        'surname'         => 'Verify',
        'first_name'      => 'PostSave',
        'full_address'    => '1 Test Lane, Dublin',
        'eircode'         => 'D02X285',
        'mobile'          => '087' . substr($runId, -7),
        'date_of_birth'   => '1992-03-15',
        'gender'          => 'male',
        'pps_number'      => '1234567T',
        'bank_iban'       => 'IE29AIBK93115212345678',
        'privacy_consent' => '1',
    ];
    $base = normalizeFinancialStaffFields($base);

    $tests = [];

    $stewardEmail = 'vps-steward-' . $runId . '@olasentra-e2e.test';
    $stewardData  = array_merge($base, [
        'email'      => $stewardEmail,
        'form_slug'  => 'steward',
        'staff_role' => 'steward',
        'pps_number' => vpsUniquePps($runId, 'steward'),
        'mobile'     => vpsUniqueMobile($runId, 'steward'),
    ]);
    $stewardData  = normalizeFinancialStaffFields($stewardData);
    $tests['steward_web'] = vpsRunPathTest($pdo, 'Steward (web post-save path)', $stewardEmail, $stewardData, $eventId, 'web_post_save');

    $psaEmail = 'vps-psa-' . $runId . '@olasentra-e2e.test';
    $psaData  = array_merge($base, [
        'email'           => $psaEmail,
        'form_slug'       => 'static',
        'staff_role'      => 'static',
        'psa_licence'     => 'EM' . strtoupper(substr(hash('sha256', $runId . 'psa-lic'), 0, 6)) . '/55',
        'psa_expiry_date' => '2027-12-31',
        'pps_number'      => vpsUniquePps($runId, 'psa'),
        'mobile'          => vpsUniqueMobile($runId, 'psa'),
    ]);
    $psaData  = normalizeFinancialStaffFields($psaData);
    $tests['psa_web'] = vpsRunPathTest($pdo, 'PSA static (web post-save path)', $psaEmail, $psaData, $psaEventId, 'web_post_save');

    $mobileEmail = 'vps-mobile-' . $runId . '@olasentra-e2e.test';
    $mobileData  = array_merge($base, [
        'email'      => $mobileEmail,
        'staff_role' => 'dsp',
        'pps_number' => vpsUniquePps($runId, 'mobile'),
        'mobile'     => vpsUniqueMobile($runId, 'mobile'),
    ]);
    $mobileData  = normalizeFinancialStaffFields($mobileData);
    $eventForMobile = $mobileSeedId;
    try {
        saveRegistration($pdo, $mobileData, $eventForMobile, 'dsp');
    } catch (Throwable $e) {
        $tests['mobile_api'] = [
            'ok'       => false,
            'evidence' => ['error' => 'mobile_seed_failed', 'message' => $e->getMessage()],
        ];
    }
    if (!isset($tests['mobile_api'])) {
        $mobileStaff = getStaffByEmail($pdo, $mobileEmail);
        if ($mobileStaff === null) {
            $tests['mobile_api'] = [
                'ok'       => false,
                'evidence' => ['error' => 'could_not_seed_staff_for_mobile'],
            ];
        } else {
            $secondEventId = $mobileSecondId;
            if ($secondEventId <= 0 || $secondEventId === $eventForMobile) {
                $tests['mobile_api'] = [
                    'ok'       => false,
                    'evidence' => ['error' => 'no_second_open_event_for_mobile_register'],
                ];
            } else {
                $result = mobileEventsServiceRegister($pdo, $mobileStaff, ['event_ids' => [$secondEventId]]);
                $regId  = (int) (($result['registration_ids'][0] ?? 0));
                $notif  = $regId > 0 ? vpsAdminNotifForReg($pdo, $regId) : ['count' => 0, 'ids' => []];
                $notif2 = ['count' => 0, 'ids' => []];
                if ($regId > 0) {
                    runRegistrationPostSaveSafely($pdo, $mobileData, [$regId], [$secondEventId], $mobileEmail);
                    $notif2 = vpsAdminNotifForReg($pdo, $regId);
                }
                $tests['mobile_api'] = [
                    'ok'              => !empty($result['ok']) && $notif['count'] === 1 && $notif2['count'] === 1,
                    'registration_id' => $regId,
                    'evidence'        => [
                        'mobile_result'      => $result,
                        'admin_notif_first'  => $notif,
                        'admin_notif_repeat' => $notif2,
                        'event_id'           => $secondEventId,
                    ],
                ];
            }
        }
    }

    $allPass = true;
    foreach ($tests as $row) {
        if (empty($row['ok'])) {
            $allPass = false;
        }
    }

    echo json_encode([
        'ok'      => $allPass,
        'fix'     => 'registration_post_save_fix_1',
        'run_id'  => $runId,
        'event_id'        => $eventId,
        'open_event_ids'  => $openEventIds,
        'tests'   => $tests,
        'cleanup' => '/cron/verify-registration-post-save-fix.php?key=…&cleanup=1&run_id=' . $runId,
        'verdict' => $allPass ? 'FIX_1_PASS' : 'FIX_1_FAIL',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}
