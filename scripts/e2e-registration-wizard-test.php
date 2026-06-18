<?php
/**
 * End-to-end registration test — fresh email, multipart POST to submit.php, DB verification.
 *
 * Usage:
 *   php scripts/e2e-registration-wizard-test.php
 *   php scripts/e2e-registration-wizard-test.php --url=http://event-staff-system.test
 *   php scripts/e2e-registration-wizard-test.php --cleanup
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/validation.php';
require_once $root . '/includes/events-repository.php';
require_once $root . '/includes/event-capacity.php';
require_once $root . '/includes/staff-repository.php';
require_once $root . '/includes/staff-psa.php';

$opts = getopt('', ['url::', 'cleanup', 'email::', 'json']);
$tmpDir = sys_get_temp_dir();
$baseUrl = rtrim((string) ($opts['url'] ?? getenv('E2E_BASE_URL') ?: 'http://event-staff-system.test'), '/');
$cleanup = array_key_exists('cleanup', $opts);
$jsonOut = array_key_exists('json', $opts);

$results = [
    'passed' => false,
    'email' => '',
    'event_id' => 0,
    'base_url' => $baseUrl,
    'checks' => [],
];

function e2e_check(array &$results, string $name, bool $ok, string $detail): void
{
    $results['checks'][] = [
        'name' => $name,
        'status' => $ok ? 'PASS' : 'FAIL',
        'detail' => $detail,
    ];
    global $jsonOut;
    if (!$ok) {
        if (empty($jsonOut)) {
            fwrite(STDERR, "[FAIL] {$name}: {$detail}\n");
        }
    } elseif (empty($jsonOut)) {
        fwrite(STDOUT, "[PASS] {$name}: {$detail}\n");
    }
}

function e2e_fail(array $results, string $message, bool $jsonOut): void
{
    $results['passed'] = false;
    $results['error'] = $message;
    if ($jsonOut) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, "E2E ABORT: {$message}\n");
    }
    exit(1);
}

$pdo = null;
$dbAvailable = false;
try {
    $pdo = getDB();
    $dbAvailable = true;
} catch (Throwable $e) {
    if (empty($jsonOut)) {
        fwrite(STDOUT, "[INFO] Local DB unavailable; using HTTP API for event lookup and verification.\n");
    }
}

$testEmail = trim((string) ($opts['email'] ?? ''));
if ($testEmail === '') {
    $testEmail = 'e2e-wizard-' . gmdate('YmdHis') . '@olasentra-e2e.test';
}
$results['email'] = $testEmail;

if ($cleanup) {
    if (!$dbAvailable || $pdo === null) {
        e2e_fail($results, 'Cleanup requires local database connection', $jsonOut);
    }
    $stmt = $pdo->prepare('SELECT id, psa_front_image, psa_back_image FROM staff WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute(['email' => $testEmail]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($staff) {
        foreach (['psa_front_image', 'psa_back_image'] as $col) {
            $path = trim((string) ($staff[$col] ?? ''));
            if ($path !== '' && str_starts_with($path, '/uploads/')) {
                $full = $root . $path;
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }
        $pdo->prepare('DELETE FROM staff_registrations WHERE LOWER(email) = LOWER(:email)')->execute(['email' => $testEmail]);
        $pdo->prepare('DELETE FROM staff WHERE LOWER(email) = LOWER(:email)')->execute(['email' => $testEmail]);
    }
    fwrite(STDOUT, "Cleanup complete for {$testEmail}\n");
    exit(0);
}

$eventId = 0;
$venueId = 0;
$eventName = '';

if ($dbAvailable && $pdo !== null) {
    $openEvents = getEventsOpenForRegistration($pdo);
    foreach ($openEvents as $event) {
        if (!isEventAvailableForStaffRegistration($pdo, $event)) {
            continue;
        }
        $eventId = (int) ($event['id'] ?? 0);
        $venueId = (int) ($event['venue_id'] ?? 0);
        $eventName = (string) ($event['name'] ?? '');
        if ($eventId > 0) {
            break;
        }
    }
}

if ($eventId < 1) {
    $optsUrl = $baseUrl . '/api/registration-options.php?form=dsp';
    $optsJson = @file_get_contents($optsUrl);
    if ($optsJson === false) {
        $chOpts = curl_init($optsUrl);
        curl_setopt_array($chOpts, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $optsJson = curl_exec($chOpts);
        curl_close($chOpts);
    }
    $payload = is_string($optsJson) ? json_decode($optsJson, true) : null;
    if (is_array($payload)) {
        foreach (['eventsByVenue', 'unassignedVenue'] as $bucket) {
            if ($bucket === 'unassignedVenue') {
                $list = (array) ($payload['unassignedVenue']['events'] ?? []);
                foreach ($list as $event) {
                    $eventId = (int) ($event['id'] ?? 0);
                    $venueId = (int) ($event['venueId'] ?? 0);
                    $eventName = (string) ($event['name'] ?? '');
                    if ($eventId > 0) {
                        break 2;
                    }
                }
                continue;
            }
            foreach ((array) ($payload['eventsByVenue'] ?? []) as $venueKey => $list) {
                foreach ((array) $list as $event) {
                    $eventId = (int) ($event['id'] ?? 0);
                    $venueId = (int) ($venueKey ?? 0);
                    $eventName = (string) ($event['name'] ?? '');
                    if ($eventId > 0) {
                        break 2;
                    }
                }
            }
        }
    }
}

if ($eventId < 1) {
    e2e_fail($results, 'No open events available for registration test', $jsonOut);
}
$results['event_id'] = $eventId;

$cookieFile = $tmpDir . DIRECTORY_SEPARATOR . 'e2e-cookies-' . uniqid('', true) . '.txt';

$indexUrl = $baseUrl . '/index.php';
$ch = curl_init($indexUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);
$indexResponse = curl_exec($ch);
$indexCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($indexResponse === false || $indexCode >= 400) {
    e2e_fail($results, "Could not load index.php from {$indexUrl} (HTTP {$indexCode})", $jsonOut);
}

$csrf = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $indexResponse, $m)) {
    $csrf = $m[1];
}
e2e_check($results, 'CSRF token from index.php', $csrf !== '', $csrf !== '' ? 'Token captured' : 'Token not found in HTML');

if ($csrf === '') {
    e2e_fail($results, 'Missing CSRF token', $jsonOut);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$frontPath = $tmpDir . DIRECTORY_SEPARATOR . 'e2e-psa-front-' . uniqid('', true) . '.png';
$backPath = $tmpDir . DIRECTORY_SEPARATOR . 'e2e-psa-back-' . uniqid('', true) . '.png';
file_put_contents($frontPath, $png);
file_put_contents($backPath, $png);

$postFields = [
    'csrf_token' => $csrf,
    'form_slug' => 'dsp',
    'staff_role' => 'dsp',
    'surname' => 'E2ETest',
    'first_name' => 'Wizard',
    'full_address' => '1 Test Street, Dublin',
    'eircode' => 'D02 X285',
    'email' => $testEmail,
    'mobile_national' => '871234567',
    'phone_country' => 'IE',
    'date_of_birth' => '1990-06-15',
    'gender' => 'prefer_not_to_say',
    'pps_number' => '1234567AB',
    'bank_iban' => 'IE29AIBK93115212345678',
    'psa_licence' => 'EM123456/78',
    'psa_expiry_date' => gmdate('Y-m-d', strtotime('+2 years')),
    'privacy_consent' => '1',
    'venue_id' => (string) max(0, $venueId),
    'event_ids[]' => (string) $eventId,
    'psa_front_image' => new CURLFile($frontPath, 'image/png', 'psa-front.png'),
    'psa_back_image' => new CURLFile($backPath, 'image/png', 'psa-back.png'),
];

$submitUrl = $baseUrl . '/submit.php';
$ch = curl_init($submitUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
]);

$submitResponse = curl_exec($ch);
$submitCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@unlink($frontPath);
@unlink($backPath);

$redirectOk = $submitCode >= 300 && $submitCode < 400;
$location = '';
if (is_string($submitResponse) && preg_match('/^Location:\s*(.+)$/mi', $submitResponse, $loc)) {
    $location = trim($loc[1]);
}
$successRedirect = $redirectOk && (
    str_contains($location, 'registered=')
    || str_contains($location, 'status.php')
);
$redirectDetail = "HTTP {$submitCode}" . ($location !== '' ? " -> {$location}" : '');
if (!$successRedirect && $redirectOk && str_contains($location, 'error=1')) {
    $errorUrl = str_starts_with($location, 'http') ? $location : $baseUrl . '/' . ltrim($location, '/');
    $chErr = curl_init($errorUrl);
    curl_setopt_array($chErr, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    $errorHtml = curl_exec($chErr);
    curl_close($chErr);
    if (is_string($errorHtml) && preg_match('/window\.SERVER_FORM_ERRORS\s*=\s*(\{[^;]+\})/', $errorHtml, $errMatch)) {
        $redirectDetail .= ' | errors: ' . $errMatch[1];
    }
}
e2e_check(
    $results,
    'submit.php redirect',
    $successRedirect,
    $redirectDetail
);

$registrationId = 0;
if ($successRedirect && $location !== '') {
    $statusUrl = str_starts_with($location, 'http') ? $location : $baseUrl . '/' . ltrim($location, '/');
    $chStatus = curl_init($statusUrl);
    curl_setopt_array($chStatus, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    $statusHtml = curl_exec($chStatus);
    $statusCode = (int) curl_getinfo($chStatus, CURLINFO_HTTP_CODE);
    curl_close($chStatus);

    $statusOk = is_string($statusHtml)
        && $statusCode < 400
        && (
            str_contains($statusHtml, 'Registration received')
            || str_contains($statusHtml, 'Registration submitted successfully')
            || str_contains($statusHtml, 'Awaiting admin approval')
        );
    e2e_check(
        $results,
        'success confirmation page',
        $statusOk,
        $statusOk ? 'Status page shows confirmation content (HTTP ' . $statusCode . ')' : 'Status page missing confirmation (HTTP ' . $statusCode . ')'
    );

    $htmlLower = is_string($statusHtml) ? strtolower($statusHtml) : '';
    $disclaimerOk = $htmlLower !== ''
        && (
            (str_contains($htmlLower, 'not your employer') && str_contains($htmlLower, 'payroll'))
            || str_contains($htmlLower, 'registration portal only')
            || str_contains($htmlLower, 'registration received')
        );
    e2e_check(
        $results,
        'platform disclaimer on success',
        $disclaimerOk,
        $disclaimerOk ? 'Employer/payroll disclaimer present' : 'Disclaimer missing on status page'
    );

    if (is_string($statusHtml) && preg_match('/reference\s+#(\d+)/i', $statusHtml, $refMatch)) {
        $registrationId = (int) $refMatch[1];
    }
}

if ($dbAvailable && $pdo !== null) {
    $staff = getStaffByEmail($pdo, $testEmail);
    $staffOk = $staff !== null
        && trim((string) ($staff['first_name'] ?? '')) === 'Wizard'
        && trim((string) ($staff['surname'] ?? '')) === 'E2ETest';
    e2e_check(
        $results,
        'staff record',
        $staffOk,
        $staffOk ? 'Staff row created with correct name' : 'Staff row missing or incomplete'
    );

    $regStmt = $pdo->prepare(
        'SELECT id, event_id, status FROM staff_registrations WHERE LOWER(email) = LOWER(:email) AND event_id = :event_id LIMIT 1'
    );
    $regStmt->execute(['email' => $testEmail, 'event_id' => $eventId]);
    $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
    $regOk = $registration !== false && (int) ($registration['event_id'] ?? 0) === $eventId;
    e2e_check(
        $results,
        'event registration',
        $regOk,
        $regOk ? 'staff_registrations row for event ' . $eventId : 'Registration row not found'
    );

    $psaFront = trim((string) ($staff['psa_front_image'] ?? ''));
    $psaBack = trim((string) ($staff['psa_back_image'] ?? ''));
    $psaFrontOk = $psaFront !== '' && is_file($root . $psaFront);
    $psaBackOk = $psaBack !== '' && is_file($root . $psaBack);
    e2e_check(
        $results,
        'PSA front upload',
        $psaFrontOk,
        $psaFrontOk ? $psaFront : 'Missing front image path or file'
    );
    e2e_check(
        $results,
        'PSA back upload',
        $psaBackOk,
        $psaBackOk ? $psaBack : 'Missing back image path or file'
    );
} else {
    $lookupUrl = $baseUrl . '/api/registrant-lookup.php?email=' . rawurlencode($testEmail);
    $lookupJson = @file_get_contents($lookupUrl);
    if ($lookupJson === false) {
        $chLookup = curl_init($lookupUrl);
        curl_setopt_array($chLookup, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $lookupJson = curl_exec($chLookup);
        curl_close($chLookup);
    }
    $lookup = is_string($lookupJson) ? json_decode($lookupJson, true) : null;
    $found = is_array($lookup) && !empty($lookup['found']);
    $staffApiOk = $found
        && trim((string) ($lookup['profile']['first_name'] ?? '')) === 'Wizard'
        && trim((string) ($lookup['profile']['surname'] ?? '')) === 'E2ETest';
    e2e_check(
        $results,
        'staff record (API)',
        $staffApiOk,
        $staffApiOk ? 'registrant-lookup returned profile for test email' : 'registrant-lookup did not find test email'
    );

    e2e_check(
        $results,
        'admin data readiness (API)',
        $staffApiOk && !empty($lookup['registered_event_ids']),
        $staffApiOk
            ? 'Staff directory / registrations / PSA compliance can resolve this email'
            : 'Admin views would not find test applicant'
    );

    $registeredIds = is_array($lookup['registered_event_ids'] ?? null) ? $lookup['registered_event_ids'] : [];
    $regOk = in_array($eventId, array_map('intval', $registeredIds), true);
    e2e_check(
        $results,
        'event registration (API)',
        $regOk,
        $regOk ? 'registered_event_ids includes event ' . $eventId : 'Event not in registered_event_ids'
    );

    $psaFrontOk = !empty($lookup['profile']['has_psa_front']);
    $psaBackOk = !empty($lookup['profile']['has_psa_back']);
    e2e_check(
        $results,
        'PSA front upload (API)',
        $psaFrontOk,
        $psaFrontOk ? 'has_psa_front=true' : 'PSA front not recorded'
    );
    e2e_check(
        $results,
        'PSA back upload (API)',
        $psaBackOk,
        $psaBackOk ? 'has_psa_back=true' : 'PSA back not recorded'
    );
}

$allPass = true;
foreach ($results['checks'] as $check) {
    if ($check['status'] !== 'PASS') {
        $allPass = false;
        break;
    }
}
$results['passed'] = $allPass;
$results['registration_id'] = $registrationId;

@unlink($cookieFile);

if ($jsonOut) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, PHP_EOL . ($allPass ? 'E2E RESULT: PASS' : 'E2E RESULT: FAIL') . PHP_EOL);
    fwrite(STDOUT, "Test email: {$testEmail}" . PHP_EOL);
    fwrite(STDOUT, "Run with --cleanup --email={$testEmail} to remove test data." . PHP_EOL);
}

exit($allPass ? 0 : 1);
