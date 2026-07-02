<?php
/**
 * Public API — look up returning registrant by email for form prefill.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/registration-api-guard.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/staff-profile-gate.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/registration-returning-profile.php';
require_once __DIR__ . '/../includes/events-repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['found' => false, 'error' => 'Method not allowed.']);
    exit;
}

requireRegistrationApiCsrf((string) ($_GET['csrf_token'] ?? ''));

$email = normalizeRegistrationEmail((string) ($_GET['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['found' => false]);
    exit;
}

throttleRegistrationLookup($email);

try {
    $pdo  = getDB();
    $row  = getLatestRegistrationByEmail($pdo, $email);

    if ($row === null) {
        echo json_encode(['found' => false]);
        exit;
    }

    $registeredEvents = getRegisteredEventsSummaryByEmail($pdo, $email);
    $registeredIds    = array_map(static fn(array $item): int => (int) $item['event_id'], $registeredEvents);
    $registeredDates  = array_values(array_unique(array_filter(array_map(
        static fn(array $item): string => normalizeEventDateYmd((string) ($item['event_date'] ?? '')),
        $registeredEvents
    ))));
    $role             = normalizeStaffRole((string) ($row['staff_role'] ?? ''));
    $staffRow         = getStaffByEmail($pdo, $email) ?: [];
    $profileComplete  = $staffRow !== [] && !staffNeedsProfileForm($pdo, $staffRow);
    $summary          = buildReturningRegistrantSummary($pdo, $row, $staffRow, $registeredEvents);

    echo json_encode([
        'found'   => true,
        'profile_complete' => $profileComplete,
        'profile_completion_pct' => $summary['profile_completion_pct'],
        'profile_status'       => $summary['profile_status'],
        'profile_status_label' => $summary['profile_status_label'],
        'compliance_status'    => $summary['compliance_status'],
        'events_applied_count' => $summary['events_applied_count'],
        'profile_url'      => 'staff-profile.php',
        'message' => $profileComplete
            ? 'Welcome back! Your saved details are loaded — select the new event(s) you want to register for.'
            : 'Welcome back! Complete your profile before you can pick new shifts.',
        'profile' => [
            'surname'       => (string) ($row['surname'] ?? ''),
            'first_name'    => (string) ($row['first_name'] ?? ''),
            'full_address'  => (string) ($row['full_address'] ?? ''),
            'eircode'       => (string) ($row['eircode'] ?? ''),
            'mobile'        => (string) ($row['mobile'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'gender'        => (string) ($row['gender'] ?? ''),
            'staff_role'    => $role,
            'form_slug'     => staffRoleToFormSlug($role),
            'location_lat'  => $row['location_lat'] !== null ? (string) $row['location_lat'] : '',
            'location_lng'  => $row['location_lng'] !== null ? (string) $row['location_lng'] : '',
            'pps_number'    => (string) ($row['pps_number'] ?? ($staffRow['pps_number'] ?? '')),
            'bank_iban'     => (string) ($row['bank_iban'] ?? ($staffRow['bank_iban'] ?? '')),
            'psa_licence'   => (string) ($staffRow['psa_licence'] ?? ''),
            'psa_expiry_date' => (string) ($staffRow['psa_expiry_date'] ?? ''),
            'has_psa_front' => !empty($staffRow['psa_front_image']),
            'has_psa_back'  => !empty($staffRow['psa_back_image']),
        ],
        'registered_event_ids'   => $registeredIds,
        'registered_event_dates' => $registeredDates,
        'registered_events'      => array_map(static function (array $item): array {
            return [
                'id'     => (int) $item['event_id'],
                'name'   => (string) ($item['event_name'] ?? ''),
                'date'   => !empty($item['event_date']) ? formatEventDateLabel((string) $item['event_date']) : '',
                'status' => (string) ($item['status'] ?? ''),
            ];
        }, $registeredEvents),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] registrant-lookup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['found' => false, 'error' => 'Unable to look up registrant.']);
}
