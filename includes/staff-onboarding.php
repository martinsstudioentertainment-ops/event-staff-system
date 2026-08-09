<?php



/**

 * Staff onboarding / profile completion (required before using the portal).

 *

 * Stewards and PSA-licensed roles use separate completion tracks:

 * - Base profile: identity, contact, address, payroll (all roles)

 * - PSA licence: dsp, static, both only (never stewards)

 */



require_once __DIR__ . '/staff-repository.php';

require_once __DIR__ . '/site-urls.php';



/** @return array<string, string> field key => human label */

function getStaffBaseProfileRequiredFields(): array

{

    return [

        'first_name'    => 'First name',

        'surname'       => 'Last name',

        'email'         => 'Email',

        'mobile'        => 'Phone',

        'full_address'  => 'Address',

        'eircode'       => 'Postcode',

        'date_of_birth' => 'Date of birth',

        'bank_iban'     => 'Bank IBAN',

    ];

}



/**

 * Whether this staff row must complete PSA licence fields (dsp / static / both — not steward).

 *

 * @param array<string, mixed>|null $staff

 */

function staffRoleRequiresOnboardingPsa(?array $staff): bool

{

    if ($staff === null) {

        return true;

    }



    require_once __DIR__ . '/staff-psa.php';



    return registrationRoleRequiresPsa($staff);

}



/**

 * Required onboarding fields for a specific staff member (base + PSA when applicable).

 *

 * @param array<string, mixed>|null $staff When null, returns base fields only (no role assumed).

 * @return array<string, string> field key => human label

 */

function getStaffOnboardingRequiredFields(?array $staff = null): array

{

    $fields = getStaffBaseProfileRequiredFields();



    if ($staff !== null && staffRoleRequiresOnboardingPsa($staff)) {

        require_once __DIR__ . '/staff-psa.php';

        $fields = array_merge($fields, getStaffPsaFieldLabels());

    }



    return $fields;

}



/**

 * @param array<string, mixed> $staff Row from staff table (or merged).

 * @return list<string> Missing base profile field labels (no PSA).

 */

function getStaffBaseProfileMissingFields(array $staff): array

{

    $missing = [];



    foreach (getStaffBaseProfileRequiredFields() as $field => $label) {

        $value = $staff[$field] ?? '';

        if ($field === 'email') {

            if (!filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL)) {

                $missing[] = $label;

            }

            continue;

        }

        if (trim((string) $value) === '' || ($field === 'date_of_birth' && trim((string) $value) === '0000-00-00')) {

            $missing[] = $label;

        }

    }



    return $missing;

}



/**

 * @param array<string, mixed> $staff Row from staff table (or merged).

 * @return list<string> Missing field labels for this role (base + PSA when required).

 */

function getStaffOnboardingMissingFields(array $staff): array

{

    $missing = getStaffBaseProfileMissingFields($staff);



    if (staffRoleRequiresOnboardingPsa($staff)) {

        require_once __DIR__ . '/staff-psa.php';

        $missing = array_merge($missing, getStaffPsaMissingFields($staff));

    }



    return $missing;

}



function isStaffBaseProfileComplete(?array $staff): bool

{

    return $staff !== null && getStaffBaseProfileMissingFields($staff) === [];

}



/**

 * @param array<string, mixed>|null $staff

 */

function isStaffOnboardingComplete(?array $staff): bool

{

    if ($staff === null) {

        return false;

    }



    if (!isStaffBaseProfileComplete($staff)) {

        return false;

    }



    if (!staffRoleRequiresOnboardingPsa($staff)) {

        return true;

    }



    require_once __DIR__ . '/staff-psa.php';



    return isStaffPsaComplete($staff);

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

 * SQL fragment for base profile fields only.

 */

function staffBaseProfileCompleteSqlCondition(string $tableAlias = 's'): string

{

    $t     = $tableAlias . '.';

    $parts = [];



    foreach (array_keys(getStaffBaseProfileRequiredFields()) as $field) {

        if ($field === 'email') {

            $parts[] = "{$t}email IS NOT NULL AND TRIM({$t}email) != '' AND {$t}email LIKE '%@%'";

            continue;

        }



        if ($field === 'date_of_birth') {

            $parts[] = "{$t}{$field} IS NOT NULL AND TRIM({$t}{$field}) != '' AND {$t}{$field} != '0000-00-00'";

            continue;

        }



        $parts[] = "{$t}{$field} IS NOT NULL AND TRIM({$t}{$field}) != ''";

    }



    return '(' . implode(' AND ', $parts) . ')';

}



/**

 * SQL fragment for PSA licence fields only.

 */

function staffPsaCompleteSqlCondition(string $tableAlias = 's'): string

{

    $t     = $tableAlias . '.';

    $parts = [];



    require_once __DIR__ . '/staff-psa.php';



    foreach (array_keys(getStaffPsaFieldLabels()) as $field) {

        if (in_array($field, ['psa_front_image', 'psa_back_image'], true)) {

            $parts[] = "{$t}{$field} IS NOT NULL AND TRIM({$t}{$field}) != '' AND TRIM({$t}{$field}) != 'pending-upload'";

            continue;

        }



        if ($field === 'psa_expiry_date') {

            $parts[] = "{$t}{$field} IS NOT NULL AND TRIM({$t}{$field}) != '' AND {$t}{$field} != '0000-00-00'";

            continue;

        }



        $parts[] = "{$t}{$field} IS NOT NULL AND TRIM({$t}{$field}) != ''";

    }



    return '(' . implode(' AND ', $parts) . ')';

}



/**

 * SQL condition matching isStaffOnboardingComplete() for staff directory filters.

 * Stewards: base profile only. PSA roles: base + PSA licence.

 */

function staffOnboardingCompleteSqlCondition(string $tableAlias = 's'): string

{

    $t    = $tableAlias . '.';

    $base = staffBaseProfileCompleteSqlCondition($tableAlias);

    $psa  = staffPsaCompleteSqlCondition($tableAlias);



    return "(({$t}staff_role = 'steward' AND {$base}) OR (({$t}staff_role IS NULL OR {$t}staff_role != 'steward') AND {$base} AND {$psa}))";

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

        'staff_role'      => trim((string) ($staff['staff_role'] ?? '')),

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



    if (staffRoleRequiresOnboardingPsa($staff)) {

        if (psaUploadProvided($files, 'psa_front_image')) {

            $data['psa_front_image'] = 'pending-upload';

        }

        if (psaUploadProvided($files, 'psa_back_image')) {

            $data['psa_back_image'] = 'pending-upload';

        }



        $errors = array_merge($errors, validateRegistrationPsa($data, $staff, $files));

    }



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



    $approved    = 0;

    $approvedIds = [];

    foreach ($ids as $id) {

        if ($id < 1 || !updateStaffStatus($pdo, $id, 'approved')) {

            continue;

        }



        ++$approved;

        $approvedIds[] = $id;

        try {

            ensureCheckinToken($pdo, $id);

            if (isGoogleSheetsSyncEnabled($pdo)) {

                syncRegistrationToGoogleSheet($pdo, $id);

            }

        } catch (Throwable $e) {

            error_log('[EventStaff] auto-approve registration id=' . $id . ': ' . $e->getMessage());

        }

    }



    if ($approvedIds !== []) {

        try {

            notifyStaffStatusChanges($pdo, $approvedIds, 'approved');

        } catch (Throwable $e) {

            error_log('[EventStaff] auto-approve notify batch: ' . $e->getMessage());

        }

        error_log('[EventStaff] Auto-approved ' . $approved . ' pending registration(s) for staff id=' . $staffId);

    }



    return $approved;

}

