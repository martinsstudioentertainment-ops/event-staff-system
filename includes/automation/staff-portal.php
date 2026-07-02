<?php

declare(strict_types=1);

/**
 * Staff portal dashboard data layer (Module 12).
 */

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/staff-self-service.php';
require_once __DIR__ . '/../notification-center.php';
require_once __DIR__ . '/../workforce/compliance-repository.php';
require_once __DIR__ . '/../workforce/staff-availability.php';

/** @return list<array<string, mixed>> */
function portal_upcoming_events(PDO $pdo, string $email, int $limit = 20): array
{
    $out = [];
    foreach (ssp_get_assignments($pdo, $email) as $row) {
        if (($row['status'] ?? '') !== 'approved') {
            continue;
        }
        $date = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($date < date('Y-m-d')) {
            continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function portal_staff_documents(PDO $pdo, array $staff): array
{
    require_once dirname(__DIR__) . '/staff-psa.php';

    $docs = [];
    if (trim((string) ($staff['psa_licence'] ?? '')) !== '') {
        $docs[] = [
            'label'  => 'PSA Licence',
            'expiry' => (string) ($staff['psa_expiry_date'] ?? ''),
            'status' => wf_psa_compliance_status(
                (string) ($staff['psa_expiry_date'] ?? ''),
                (string) ($staff['psa_licence'] ?? '')
            ),
        ];
    }
    if (trim((string) ($staff['psa_front_image'] ?? '')) !== '') {
        $frontPath = (string) $staff['psa_front_image'];
        $docs[] = [
            'label'     => 'PSA Front Image',
            'expiry'    => '',
            'status'    => 'valid',
            'image_url' => isStoredPsaImagePath($frontPath) ? psaImagePublicUrl($frontPath, $pdo) : '',
        ];
    }
    if (trim((string) ($staff['psa_back_image'] ?? '')) !== '') {
        $backPath = (string) $staff['psa_back_image'];
        $docs[] = [
            'label'     => 'PSA Back Image',
            'expiry'    => '',
            'status'    => 'valid',
            'image_url' => isStoredPsaImagePath($backPath) ? psaImagePublicUrl($backPath, $pdo) : '',
        ];
    }

    return $docs;
}

/** @return list<array<string, mixed>> */
function portal_availability_month(PDO $pdo, int $staffId, string $month): array
{
    wf_ensure_availability_schema($pdo);
    if ($staffId < 1 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        return [];
    }

    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));

    return wf_get_availability_range($pdo, $from, $to, $staffId);
}

/** @return list<array<string, mixed>> */
function portal_notifications(PDO $pdo, string $email, int $limit = 15): array
{
    return getStaffNotifications($pdo, $email, $limit);
}

function portal_confirm_attendance(PDO $pdo, int $registrationId, string $email): bool
{
    return ssp_set_shift_response($pdo, $registrationId, $email, 'accepted');
}
