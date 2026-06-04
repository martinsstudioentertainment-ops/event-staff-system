<?php

/**
 * PSA licence fields — uploads and validation (registration + status page).
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/production-readiness.php';

/** Accept attribute for PSA photo file inputs (incl. iPhone HEIC). */
function psaImageFileAcceptAttribute(): string
{
    return 'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,image/*';
}

function ensureStaffPsaSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!tableExists($pdo, 'staff')) {
        $ready = true;

        return;
    }

    $columns = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM staff')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) ($row['Field'] ?? '')] = true;
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureStaffPsaSchema: ' . $e->getMessage());
        $ready = true;

        return;
    }

    $alters = [];
    if (empty($columns['psa_licence'])) {
        $alters[] = 'ADD COLUMN psa_licence VARCHAR(100) NULL DEFAULT NULL AFTER bank_iban';
    }
    if (empty($columns['psa_expiry_date'])) {
        $alters[] = 'ADD COLUMN psa_expiry_date DATE NULL DEFAULT NULL AFTER psa_licence';
    }
    if (empty($columns['psa_front_image'])) {
        $alters[] = 'ADD COLUMN psa_front_image VARCHAR(255) NULL DEFAULT NULL AFTER psa_expiry_date';
    }
    if (empty($columns['psa_back_image'])) {
        $alters[] = 'ADD COLUMN psa_back_image VARCHAR(255) NULL DEFAULT NULL AFTER psa_front_image';
    }
    if (empty($columns['profile_completed'])) {
        $alters[] = 'ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER psa_back_image';
    }

    foreach ($alters as $fragment) {
        try {
            $pdo->exec('ALTER TABLE staff ' . $fragment);
        } catch (Throwable $e) {
            error_log('[EventStaff] ensureStaffPsaSchema alter: ' . $e->getMessage());
        }
    }

    $ready = true;
}

function psaUploadErrorMessage(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Photo is too large. Use an image under 8 MB or take a new photo.',
        UPLOAD_ERR_PARTIAL      => 'Upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR   => 'Server upload folder is not available. Contact support.',
        UPLOAD_ERR_CANT_WRITE   => 'Could not save photo on server. Contact support.',
        UPLOAD_ERR_EXTENSION    => 'This file type is not allowed.',
        default                 => 'Could not upload file. Please try again.',
    };
}

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

    require_once __DIR__ . '/financial-field-validation.php';
    $psaErr = validatePsaLicenceField((string) ($data['psa_licence'] ?? ''), true);
    if ($psaErr !== null) {
        $errors['psa_licence'] = $psaErr;
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
function isAllowedPsaImageFile(array $file): bool
{
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];
    $ext         = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return in_array($ext, $allowedExts, true);
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '') {
        $mime = (string) (mime_content_type($tmp) ?: '');
    }

    if ($mime !== '' && str_starts_with($mime, 'image/')) {
        return true;
    }

    return in_array($ext, $allowedExts, true);
}

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
        return psaUploadErrorMessage($error);
    }

    $size = (int) ($files[$field]['size'] ?? 0);
    if ($size > 8 * 1024 * 1024) {
        return 'Image must be 8 MB or smaller.';
    }

    if (!isAllowedPsaImageFile($files[$field])) {
        return 'Please upload a photo (JPG, PNG, or HEIC from your camera roll).';
    }

    return null;
}

/**
 * @param array<string, mixed> $files $_FILES
 * @return array<string, string> Paths to set on staff row (psa_front_image, psa_back_image)
 */
function processStaffPsaFileUploads(int $staffId, array $files): array
{
    return processStaffPsaFileUploadsWithErrors($staffId, $files)['paths'];
}

/**
 * @return array{paths: array<string, string>, errors: array<string, string>}
 */
function processStaffPsaFileUploadsWithErrors(int $staffId, array $files): array
{
    $paths     = [];
    $errors    = [];
    $uploadDir = dirname(__DIR__) . '/uploads/psa/';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        foreach (['psa_front_image', 'psa_back_image'] as $field) {
            if (psaUploadProvided($files, $field)) {
                $errors[$field] = 'Photo folder is not writable on the server. Contact support.';
            }
        }

        return ['paths' => $paths, 'errors' => $errors];
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];

    foreach (['psa_front_image' => 'psa_front', 'psa_back_image' => 'psa_back'] as $field => $prefix) {
        if (!psaUploadProvided($files, $field)) {
            continue;
        }

        $ext = strtolower(pathinfo((string) $files[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $ext = 'jpg';
        }

        $filename = $prefix . '_' . $staffId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest     = $uploadDir . $filename;

        if (move_uploaded_file((string) $files[$field]['tmp_name'], $dest)) {
            $paths[$field] = '/uploads/psa/' . $filename;
            continue;
        }

        error_log('[EventStaff] PSA upload failed for staff ' . $staffId . ' field ' . $field);
        $errors[$field] = 'Could not save photo. Try a smaller JPG or PNG, or contact support.';
    }

    return ['paths' => $paths, 'errors' => $errors];
}

/**
 * Persist PSA text and optional image uploads after registration or status update.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $files
 * @return array<string, string> Field errors (empty on success)
 */
function saveStaffPsaFromForm(PDO $pdo, int $staffId, array $data, array $files): array
{
    if ($staffId < 1) {
        return [];
    }

    ensureStaffPsaSchema($pdo);

    $update = [];
    require_once __DIR__ . '/financial-field-validation.php';
    $data    = normalizeFinancialStaffFields($data);
    $licence = trim((string) ($data['psa_licence'] ?? ''));
    $expiry  = trim((string) ($data['psa_expiry_date'] ?? ''));
    if ($licence !== '') {
        $update['psa_licence'] = $licence;
    }
    if ($expiry !== '') {
        $update['psa_expiry_date'] = $expiry;
    }

    $upload = processStaffPsaFileUploadsWithErrors($staffId, $files);
    if ($upload['errors'] !== []) {
        return $upload['errors'];
    }

    $update = array_merge($update, $upload['paths']);

    if ($update === []) {
        return [];
    }

    if (updateStaffProfile($pdo, $staffId, $update)) {
        $staff = getStaffById($pdo, $staffId);
        if ($staff !== null && isStaffOnboardingComplete($staff)) {
            markStaffProfileCompleted($pdo, $staffId);
        }
    }

    return [];
}
