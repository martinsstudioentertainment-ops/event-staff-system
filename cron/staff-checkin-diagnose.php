<?php

declare(strict_types=1);

/**
 * Diagnose staff-app check-in POST chain on production (read-only by default).
 * GET: ?key=email-encoding-verify-20260606&date=2026-06-24&registration_id=632
 *      &apply=0 (default) simulates only; apply=1 runs recordCheckin inside rolled-back transaction
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-venue-checkin.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-pages.php';
require_once dirname(__DIR__) . '/includes/maps.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) === 1
    ? (string) $_GET['date']
    : date('Y-m-d');
$regId = (int) ($_GET['registration_id'] ?? 0);
$apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

$steps = [];

try {
    $steps[] = ['step' => 'includes', 'ok' => true, 'functions' => [
        'processStaffAppVenueCheckin' => function_exists('processStaffAppVenueCheckin'),
        'renderStaffV3BibBanner'      => function_exists('renderStaffV3BibBanner'),
        'resolveCheckinBibForRegistration' => function_exists('resolveCheckinBibForRegistration'),
        'assertSelfCheckinWithinLateCutoff' => function_exists('assertSelfCheckinWithinLateCutoff'),
    ]];
} catch (Throwable $e) {
    $steps[] = ['step' => 'includes', 'ok' => false, 'error' => $e->getMessage()];
}

if ($regId < 1) {
    $stmt = $pdo->prepare(
        "SELECT sr.id
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = 'approved'
           AND e.event_date = :d
           AND (a.id IS NULL OR a.checked_in_at IS NULL OR a.checked_in_at = '')
         ORDER BY sr.id DESC
         LIMIT 1"
    );
    $stmt->execute(['d' => $date]);
    $regId = (int) ($stmt->fetchColumn() ?: 0);
}

if ($regId < 1) {
    echo json_encode(['ok' => false, 'error' => 'No unchecked approved registration for ' . $date, 'steps' => $steps], JSON_PRETTY_PRINT);
    exit;
}

$row = getStaffRegistrationById($pdo, $regId);
if (!is_array($row)) {
    echo json_encode(['ok' => false, 'error' => 'Registration not found', 'registration_id' => $regId, 'steps' => $steps], JSON_PRETTY_PRINT);
    exit;
}

$row = mergeRegistrationWithEvent($pdo, mergeRegistrationWithStaff($pdo, $row));
$venue = getEventVenueCoordinates($row);

$gps = null;
if (is_array($venue)) {
    $gps = ['lat' => (float) $venue['lat'], 'lng' => (float) $venue['lng'], 'accuracy_m' => 15];
} else {
    $gps = ['lat' => 53.4509, 'lng' => -6.1501, 'accuracy_m' => 15];
}

$post = [
    'staff_app_checkin' => '1',
    'bib_number'        => 'DIAG-' . date('His'),
    'sign_lat'          => (string) $gps['lat'],
    'sign_lng'          => (string) $gps['lng'],
    'sign_accuracy_m'   => '15',
];

$portalStaff = [
    'id'    => (int) ($row['staff_id'] ?? 0),
    'email' => strtolower(trim((string) ($row['email'] ?? ''))),
];

try {
    $window = getEventCheckinWindow($row);
    $steps[] = [
        'step'  => 'checkin_window',
        'ok'    => true,
        'open'  => (bool) ($window['is_open'] ?? false),
        'opens' => $window['opens_at']->format('Y-m-d H:i:s'),
        'start' => $window['event_start']->format('Y-m-d H:i:s'),
    ];
} catch (Throwable $e) {
    $steps[] = ['step' => 'checkin_window', 'ok' => false, 'error' => $e->getMessage()];
}

try {
    $bibParsed = resolveCheckinBibForRegistration($row, (string) $post['bib_number'], true);
    $steps[] = ['step' => 'bib_parse', 'ok' => (bool) $bibParsed['ok'], 'detail' => $bibParsed];
} catch (Throwable $e) {
    $steps[] = ['step' => 'bib_parse', 'ok' => false, 'error' => $e->getMessage()];
}

try {
    $gpsError = assertSelfCheckinVenueGps($pdo, $row, $gps, 'self');
    $steps[] = ['step' => 'venue_gps', 'ok' => $gpsError === null, 'message' => $gpsError];
} catch (Throwable $e) {
    $steps[] = ['step' => 'venue_gps', 'ok' => false, 'error' => $e->getMessage()];
}

try {
    ob_start();
    renderStaffV3BibBanner('TEST-123');
    $bannerHtml = (string) ob_get_clean();
    $steps[] = ['step' => 'render_bib_banner', 'ok' => str_contains($bannerHtml, 'TEST-123')];
} catch (Throwable $e) {
    ob_end_clean();
    $steps[] = ['step' => 'render_bib_banner', 'ok' => false, 'error' => $e->getMessage()];
}

$result = null;
$stepName = $apply ? 'process_checkin_live' : 'process_checkin_simulated';
try {
    $pdo->beginTransaction();
    $result = processStaffAppVenueCheckin($pdo, $portalStaff, $post, $row);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $steps[] = [
        'step'        => $stepName,
        'ok'          => true,
        'result'      => $result,
        'rolled_back' => true,
        'live_write'  => $apply,
    ];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $steps[] = [
        'step'  => $stepName,
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ];
}

$failed = array_values(array_filter($steps, static fn (array $s): bool => ($s['ok'] ?? false) !== true));

echo json_encode([
    'ok'              => $failed === [],
    'date'            => $date,
    'registration_id' => $regId,
    'event'           => (string) ($row['event_name'] ?? ''),
    'email'           => (string) ($row['email'] ?? ''),
    'venue_configured'=> $venue !== null,
    'apply'           => $apply,
    'failed_steps'    => $failed,
    'steps'           => $steps,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
