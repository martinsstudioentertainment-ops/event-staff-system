<?php
/**
 * Public API — look up returning registrant by email for form prefill.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-forms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['found' => false, 'error' => 'Method not allowed.']);
    exit;
}

$email = normalizeRegistrationEmail((string) ($_GET['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['found' => false]);
    exit;
}

try {
    $pdo  = getDB();
    $row  = getLatestRegistrationByEmail($pdo, $email);

    if ($row === null) {
        echo json_encode(['found' => false]);
        exit;
    }

    $registeredEvents = getRegisteredEventsSummaryByEmail($pdo, $email);
    $registeredIds    = array_map(static fn(array $item): int => (int) $item['event_id'], $registeredEvents);
    $role             = normalizeStaffRole((string) ($row['staff_role'] ?? ''));

    echo json_encode([
        'found'   => true,
        'message' => 'Welcome back! Your saved details are loaded — select the new event(s) you want to register for.',
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
        ],
        'registered_event_ids' => $registeredIds,
        'registered_events'    => array_map(static function (array $item): array {
            return [
                'id'     => (int) $item['event_id'],
                'name'   => (string) ($item['event_name'] ?? ''),
                'date'   => !empty($item['event_date']) ? date('d.m.Y', strtotime((string) $item['event_date'])) : '',
                'status' => (string) ($item['status'] ?? ''),
            ];
        }, $registeredEvents),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['found' => false, 'error' => 'Unable to look up registrant.']);
}
