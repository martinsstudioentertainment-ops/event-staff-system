<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
guardDevOnlyEndpoint('Probe disabled in production.');
initSecureSession();

$steps = [];

try {
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/validation.php';
    require_once dirname(__DIR__) . '/includes/registration-forms.php';
    require_once dirname(__DIR__) . '/includes/registration-options-repository.php';
    require_once dirname(__DIR__) . '/includes/staff-repository.php';
    require_once dirname(__DIR__) . '/includes/notifications.php';
    require_once dirname(__DIR__) . '/includes/system-settings.php';
    require_once dirname(__DIR__) . '/includes/staff-blacklist.php';
    require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
    require_once dirname(__DIR__) . '/includes/staff-registration-schema.php';
    require_once dirname(__DIR__) . '/includes/status-repository.php';
    require_once dirname(__DIR__) . '/includes/staff-psa.php';
    require_once dirname(__DIR__) . '/includes/staff-profile-gate.php';
    require_once dirname(__DIR__) . '/includes/registration-post-save.php';
    require_once dirname(__DIR__) . '/includes/staff-allocation.php';
    require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';
    $steps[] = 'includes_ok';

    $steps[] = function_exists('registrationFormReadyForShiftSelection') ? 'gate_fn_ok' : 'gate_fn_missing';

    $pdo = getDB();
    $steps[] = 'db_ok';

    $data = [
        'surname' => 'Probe',
        'first_name' => 'Test',
        'full_address' => '1 Main St',
        'eircode' => 'D01X000',
        'email' => 'probe-' . time() . '@olasentra-e2e.test',
        'mobile' => '0871234567',
        'date_of_birth' => '1990-01-01',
        'gender' => 'male',
        'pps_number' => '1234567T',
        'bank_iban' => 'IE36IPBS99062736380088',
        'psa_licence' => 'EM231671/55',
        'psa_expiry_date' => '2026-11-29',
        'staff_role' => 'dsp',
        'form_slug' => 'dsp',
        'privacy_consent' => '1',
        'event_ids' => [4],
    ];
    $data['date_of_birth'] = normalizeDateOfBirthForDb((string) $data['date_of_birth']);
    $data['psa_expiry_date'] = normalizeDateOfBirthForDb((string) $data['psa_expiry_date']);

    $errors = validateRegistration($data);
    $steps[] = 'validate_registration:' . json_encode($errors);

    $files = [
        'psa_front_image' => [
            'name' => 'front.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ],
        'psa_back_image' => [
            'name' => 'back.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ],
    ];

    $psaErrors = validateRegistrationPsa($data, null, $files);
    $steps[] = 'validate_psa:' . json_encode($psaErrors);

    $ready = registrationFormReadyForShiftSelection($data, $files, null);
    $steps[] = 'gate_ready:' . ($ready ? 'yes' : 'no');

    echo json_encode(['ok' => true, 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('[EventStaff] probe-reg-submit: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Probe failed',
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
