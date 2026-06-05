<?php

/**
 * Staff onboarding / profile completion (required before using the portal).
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/site-urls.php';

/** @return array<string, string> field key => human label */
function getStaffOnboardingRequiredFields(): array
{
    return [
        'first_name'       => 'First name',
        'surname'          => 'Last name',
        'email'            => 'Email',
        'mobile'           => 'Phone',
        'full_address'     => 'Address',
        'eircode'          => 'Postcode',
        'date_of_birth'    => 'Date of birth',
        'bank_iban'        => 'Bank IBAN',
        'psa_licence'      => 'PSA licence number',
        'psa_expiry_date'  => 'PSA expiry date',
        'psa_front_image'  => 'PSA card front photo',
        'psa_back_image'   => 'PSA card back photo',
    ];
}

/**
 * @param array<string, mixed> $staff Row from staff table (or merged).
 * @return list<string> Missing field labels
 */
function getStaffOnboardingMissingFields(array $staff): array
{
    $missing = [];

    foreach (getStaffOnboardingRequiredFields() as $field => $label) {
        $value = $staff[$field] ?? '';
        if ($field === 'email') {
            if (!filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL)) {
                $missing[] = $label;
            }
            continue;
        }
        if (trim((string) $value) === '') {
            $missing[] = $label;
        }
    }

    return $missing;
}

/**
 * @param array<string, mixed>|null $staff
 */
function isStaffOnboardingComplete(?array $staff): bool
{
    return $staff !== null && getStaffOnboardingMissingFields($staff) === [];
}

function isStaffOnboardingCompleteById(PDO $pdo, int $staffId): bool
{
    if ($staffId < 1) {
        return false;
    }

    return isStaffOnboardingComplete(getStaffById($pdo, $staffId));
}

function isStaffOnboardingCompleteByEmail(PDO $pdo, string $email): bool
{
    $staff = getStaffByEmail($pdo, $email);

    return isStaffOnboardingComplete($staff);
}

/**
 * Create profile_token if missing.
 */
function ensureStaffProfileToken(PDO $pdo, int $staffId): string
{
    $staff = getStaffById($pdo, $staffId);
    if (!$staff) {
        throw new InvalidArgumentException('Staff not found');
    }

    $token = trim((string) ($staff['profile_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    return generateStaffProfileToken($pdo, $staffId);
}

function getStaffProfileUrl(PDO $pdo, int $staffId): string
{
    $token = ensureStaffProfileToken($pdo, $staffId);

    $base = rtrim(getRegistrationSiteUrl($pdo), '/');

    return $base . '/staff-profile.php?token=' . urlencode($token);
}

/**
 * Public staff portal entry (email + date of birth).
 */
function getStaffPortalUrl(PDO $pdo): string
{
    return rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-portal.php';
}

/**
 * If onboarding incomplete, returns profile URL to redirect to; otherwise null.
 */
function getStaffOnboardingRedirectUrl(PDO $pdo, string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $staffId = ensureStaffRecordForEmail($pdo, $email);
    if ($staffId === null) {
        return null;
    }

    $staff = getStaffById($pdo, $staffId);
    if (isStaffOnboardingComplete($staff)) {
        return null;
    }

    return getStaffProfileUrl($pdo, $staffId);
}

/**
 * @param array<string, mixed> $post POST data from staff-profile form
 * @param array<string, mixed> $files $_FILES
 * @return array<string, string> field => error
 */
function validateStaffOnboardingPost(array $post, array $staff, array $files = []): array
{
    require_once __DIR__ . '/staff-psa.php';
    require_once __DIR__ . '/phone-numbers.php';

    $errors = [];
    prepareMobileFromRequest($post);
    $data   = [
        'first_name'      => trim((string) ($post['first_name'] ?? '')),
        'surname'         => trim((string) ($post['surname'] ?? '')),
        'email'           => trim((string) ($staff['email'] ?? '')),
        'mobile'          => trim((string) ($post['mobile'] ?? '')),
        'full_address'    => trim((string) ($post['full_address'] ?? '')),
        'eircode'         => trim((string) ($post['eircode'] ?? '')),
        'date_of_birth'   => trim((string) ($post['date_of_birth'] ?? '')) !== ''
            ? trim((string) $post['date_of_birth'])
            : trim((string) ($staff['date_of_birth'] ?? '')),
        'bank_iban'       => trim((string) ($post['bank_iban'] ?? '')),
        'psa_licence'     => trim((string) ($post['psa_licence'] ?? '')),
        'psa_expiry_date' => trim((string) ($post['psa_expiry_date'] ?? '')),
        'psa_front_image' => trim((string) ($staff['psa_front_image'] ?? '')),
        'psa_back_image'  => trim((string) ($staff['psa_back_image'] ?? '')),
    ];

    if (psaUploadProvided($files, 'psa_front_image')) {
        $data['psa_front_image'] = 'pending-upload';
    }
    if (psaUploadProvided($files, 'psa_back_image')) {
        $data['psa_back_image'] = 'pending-upload';
    }

    $errors = array_merge($errors, validateRegistrationPsa($data, $staff, $files));

    $missing = getStaffOnboardingMissingFields($data);
    if ($missing !== []) {
        $errors['form'] = 'Please complete all required fields: ' . implode(', ', $missing);
    }

    require_once __DIR__ . '/financial-field-validation.php';
    $errors = array_merge($errors, validateFinancialStaffFields($data, true));

    $mobileError = validateMobileNumber((string) ($data['mobile'] ?? ''));
    if ($mobileError !== null) {
        $errors['mobile'] = $mobileError;
    }

    return $errors;
}

/**
 * When a staff profile becomes complete, approve their pending shift registrations.
 *
 * @return int Number of registrations auto-approved
 */
function autoApprovePendingRegistrationsForStaff(PDO $pdo, int $staffId): int
{
    if ($staffId < 1) {
        return 0;
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null || !isStaffOnboardingComplete($staff)) {
        return 0;
    }

    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($email === '') {
        return 0;
    }

    require_once __DIR__ . '/staff-registration-schema.php';
    require_once __DIR__ . '/attendance-repository.php';
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/google-sheets-sync.php';

    ensureStaffRegistrationCheckinSchema($pdo);

    $stmt = $pdo->prepare(
        "SELECT id FROM staff_registrations
         WHERE status = 'pending'
           AND (staff_id = :staff_id OR LOWER(email) = :email)
         ORDER BY id ASC"
    );
    $stmt->execute(['staff_id' => $staffId, 'email' => $email]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $approved = 0;
    foreach ($ids as $id) {
        if ($id < 1 || !updateStaffStatus($pdo, $id, 'approved')) {
            continue;
        }

        $approved++;
        try {
            ensureCheckinToken($pdo, $id);
            notifyStaffStatusChange($pdo, $id, 'approved');
            if (isGoogleSheetsSyncEnabled($pdo)) {
                syncRegistrationToGoogleSheet($pdo, $id);
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] auto-approve registration id=' . $id . ': ' . $e->getMessage());
        }
    }

    if ($approved > 0) {
        error_log('[EventStaff] Auto-approved ' . $approved . ' pending registration(s) for staff id=' . $staffId);
    }

    return $approved;
}
