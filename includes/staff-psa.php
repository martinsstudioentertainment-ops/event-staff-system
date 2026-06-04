<?php

/**
 * PSA licence fields — uploads and validation (registration + status page).
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-onboarding.php';

/** @return array<string, string> */
function getStaffPsaFieldLabels(): array
{
    return [
        'psa_licence'     => 'PSA licence number',
        'psa_expiry_date' => 'PSA expiry date',
        'psa_front_image' => 'PSA front photo',
        'psa_back_image'  => 'PSA back photo',
    ];
}

/**
 * @param array<string, mixed>|null $staff
 * @return list<string> Missing PSA field labels
 */
function getStaffPsaMissingFields(?array $staff): array
{
    if ($staff === null) {
        return array_values(getStaffPsaFieldLabels());
    }

    $missing = [];
    foreach (getStaffPsaFieldLabels() as $field => $label) {
        if (trim((string) ($staff[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    return $missing;
}

function isStaffPsaComplete(?array $staff): bool
{
    return getStaffPsaMissingFields($staff) === [];
}

/**
 * @param array<string, mixed> $data POST fields
 * @param array<string, mixed>|null $staff Existing staff row (for optional re-upload)
 * @param array<string, mixed> $files $_FILES
 * @return array<string, string>
 */
function validateRegistrationPsa(array $data, ?array $staff, array $files): array
{
    $errors = [];

    if (trim((string) ($data['psa_licence'] ?? '')) === '') {
        $errors['psa_licence'] = 'PSA licence number is required.';
    }

    $expiry = trim((string) ($data['psa_expiry_date'] ?? ''));
    if ($expiry === '') {
        $errors['psa_expiry_date'] = 'PSA expiry date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        $errors['psa_expiry_date'] = 'Enter a valid expiry date.';
    }

    $hasFront = trim((string) ($staff['psa_front_image'] ?? '')) !== ''
        || psaUploadProvided($files, 'psa_front_image');
    $hasBack = trim((string) ($staff['psa_back_image'] ?? '')) !== ''
        || psaUploadProvided($files, 'psa_back_image');

    if (!$hasFront) {
        $errors['psa_front_image'] = 'PSA front photo is required.';
    }
    if (!$hasBack) {
        $errors['psa_back_image'] = 'PSA back photo is required.';
    }

    foreach (['psa_front_image', 'psa_back_image'] as $field) {
        $err = validatePsaFileUpload($files, $field);
        if ($err !== null) {
            $errors[$field] = $err;
        }
    }

    return $errors;
}

/**
 * @param array<string, mixed> $files
 */
function psaUploadProvided(array $files, string $field): bool
{
    return isset($files[$field]) && (int) ($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

/**
 * @param array<string, mixed> $files
 */
function validatePsaFileUpload(array $files, string $field): ?string
{
    if (!isset($files[$field])) {
        return null;
    }

    $error = (int) ($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        return 'Could not upload file. Please try again.';
    }

    $size = (int) ($files[$field]['size'] ?? 0);
    if ($size > 8 * 1024 * 1024) {
        return 'Image must be 8 MB or smaller.';
    }

    $tmp  = (string) ($files[$field]['tmp_name'] ?? '');
    $mime = $tmp !== '' && is_uploaded_file($tmp) ? (mime_content_type($tmp) ?: '') : '';
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        return 'Please upload an image file (JPG or PNG).';
    }

    return null;
}

/**
 * @param array<string, mixed> $files $_FILES
 * @return array<string, string> Paths to set on staff row (psa_front_image, psa_back_image)
 */
function processStaffPsaFileUploads(int $staffId, array $files): array
{
    $paths     = [];
    $uploadDir = dirname(__DIR__) . '/uploads/psa/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    foreach (['psa_front_image' => 'psa_front', 'psa_back_image' => 'psa_back'] as $field => $prefix) {
        if (!psaUploadProvided($files, $field)) {
            continue;
        }

        $ext = strtolower(pathinfo((string) $files[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $ext = 'jpg';
        }

        $filename = $prefix . '_' . $staffId . '_' . time() . '.' . $ext;
        if (move_uploaded_file((string) $files[$field]['tmp_name'], $uploadDir . $filename)) {
            $paths[$field] = '/uploads/psa/' . $filename;
        }
    }

    return $paths;
}

/**
 * Persist PSA text and optional image uploads after registration or status update.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $files
 */
function saveStaffPsaFromForm(PDO $pdo, int $staffId, array $data, array $files): void
{
    if ($staffId < 1) {
        return;
    }

    $update = [];
    $licence = trim((string) ($data['psa_licence'] ?? ''));
    $expiry  = trim((string) ($data['psa_expiry_date'] ?? ''));
    if ($licence !== '') {
        $update['psa_licence'] = $licence;
    }
    if ($expiry !== '') {
        $update['psa_expiry_date'] = $expiry;
    }

    $update = array_merge($update, processStaffPsaFileUploads($staffId, $files));

    if ($update === []) {
        return;
    }

    if (updateStaffProfile($pdo, $staffId, $update)) {
        $staff = getStaffById($pdo, $staffId);
        if ($staff !== null && isStaffOnboardingComplete($staff)) {
            markStaffProfileCompleted($pdo, $staffId);
        }
    }
}
