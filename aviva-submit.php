<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
initSecureSession();
require_once __DIR__ . '/includes/google-form-intake.php';
require_once __DIR__ . '/includes/phone-numbers.php';
require_once __DIR__ . '/includes/staff-psa.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /aviva-stadium.php');
    exit;
}

function avivaSubmitUploadB64(string $field): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['error' => 'Could not read that photo. Try a screenshot if it will not attach.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Photo upload failed. Try again.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 100 * 1024 * 1024) {
        return ['error' => 'Each PSA photo must be 100 MB or smaller.'];
    }
    $binary = file_get_contents($tmp);
    if (!is_string($binary) || $binary === '') {
        return ['error' => 'Photo upload was empty. Try a screenshot.'];
    }

    return [
        'b64'  => base64_encode($binary),
        'mime' => (string) ($file['type'] ?? 'image/jpeg'),
        'name' => (string) ($file['name'] ?? ''),
    ];
}

$front = avivaSubmitUploadB64('psa_front_image');
$back  = avivaSubmitUploadB64('psa_back_image');
$errors = [];
if (isset($front['error'])) {
    $errors[] = 'PSA badge front: ' . $front['error'];
}
if (isset($back['error'])) {
    $errors[] = 'PSA badge back: ' . $back['error'];
}

$payload = [
    'first_name'   => trim((string) ($_POST['first_name'] ?? '')),
    'surname'      => trim((string) ($_POST['surname'] ?? '')),
    'email'        => trim((string) ($_POST['email'] ?? '')),
    'mobile'       => trim((string) ($_POST['mobile'] ?? '')),
    'full_address' => trim((string) ($_POST['full_address'] ?? '')),
    'eircode'      => trim((string) ($_POST['eircode'] ?? '')),
    'gender'       => trim((string) ($_POST['gender'] ?? '')),
    'bank_iban'    => trim((string) ($_POST['bank_iban'] ?? '')),
    'staff_role'   => trim((string) ($_POST['staff_role'] ?? '')),
    'psa_licence'  => trim((string) ($_POST['psa_licence'] ?? '')),
];

if (!empty($front['b64'])) {
    $payload['psa_front_image_b64'] = $front['b64'];
    $payload['psa_front_mime'] = $front['mime'] ?? 'image/jpeg';
    $payload['psa_front_name'] = $front['name'] ?? '';
}
if (!empty($back['b64'])) {
    $payload['psa_back_image_b64'] = $back['b64'];
    $payload['psa_back_mime'] = $back['mime'] ?? 'image/jpeg';
    $payload['psa_back_name'] = $back['name'] ?? '';
}

$_SESSION['aviva_old'] = $payload;
unset($_SESSION['aviva_old']['psa_front_image_b64'], $_SESSION['aviva_old']['psa_back_image_b64']);

if ($errors !== []) {
    $_SESSION['aviva_errors'] = $errors;
    header('Location: /aviva-stadium.php');
    exit;
}

try {
    $pdo = getDB();
    $result = processGoogleFormStaffIntake($pdo, $payload);
} catch (Throwable $e) {
    error_log('[EventStaff] Aviva website submit: ' . $e->getMessage());
    $_SESSION['aviva_errors'] = ['Could not save your registration. Please try again.'];
    header('Location: /aviva-stadium.php');
    exit;
}

if (empty($result['ok'])) {
    $_SESSION['aviva_errors'] = [trim((string) ($result['error'] ?? 'Please check your details and try again.'))];
    header('Location: /aviva-stadium.php');
    exit;
}

unset($_SESSION['aviva_old'], $_SESSION['aviva_errors']);
header('Location: /aviva-stadium.php?ok=1');
exit;
