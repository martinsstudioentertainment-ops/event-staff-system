<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';
guardDevOnlyEndpoint('Probe disabled in production.');
initSecureSession();

$steps = [];

try {
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/validation.php';
    require_once dirname(__DIR__) . '/includes/registration-forms.php';
    require_once dirname(__DIR__) . '/includes/staff-repository.php';
    require_once dirname(__DIR__) . '/includes/staff-registration-schema.php';
    require_once dirname(__DIR__) . '/includes/staff-psa.php';
    require_once dirname(__DIR__) . '/includes/staff-allocation.php';
    require_once dirname(__DIR__) . '/includes/staff-blacklist.php';
    require_once dirname(__DIR__) . '/includes/registration-post-save.php';
    require_once dirname(__DIR__) . '/includes/status-repository.php';
    require_once dirname(__DIR__) . '/includes/financial-field-validation.php';

    $pdo = getDB();
    $email = 'probe-save-' . time() . '@olasentra-e2e.test';
    $data = [
        'form_slug' => 'dsp',
        'staff_role' => 'dsp',
        'surname' => 'Probe',
        'first_name' => 'Save',
        'full_address' => '1 Main St',
        'eircode' => 'D01X000',
        'email' => $email,
        'mobile' => '0871234567',
        'date_of_birth' => '1990-01-01',
        'gender' => 'male',
        'pps_number' => '1234567T',
        'bank_iban' => 'IE29AIBK93115212345678',
        'psa_licence' => 'EM231671/55',
        'psa_expiry_date' => '2026-11-29',
        'privacy_consent' => '1',
    ];
    $data = normalizeFinancialStaffFields($data);

    $tmp = tempnam(sys_get_temp_dir(), 'psa');
    file_put_contents($tmp, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBEQCEAD8AVID/2Q=='));
    $files = [
        'psa_front_image' => [
            'name' => 'front.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: 1,
        ],
        'psa_back_image' => [
            'name' => 'back.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp) ?: 1,
        ],
    ];

    $ids = saveRegistrations($pdo, $data, [4], $files);
    $steps[] = 'saved:' . json_encode($ids);

    echo json_encode(['ok' => true, 'steps' => $steps], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[EventStaff] probe-reg-save: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Probe failed',
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE);
}
