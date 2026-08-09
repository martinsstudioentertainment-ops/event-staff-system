<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../../staff-onboarding.php';
require_once __DIR__ . '/../../staff-profile-gate.php';
require_once __DIR__ . '/../../staff-portal-dashboard.php';
require_once __DIR__ . '/../../automation/staff-portal.php';
require_once __DIR__ . '/../../sensitive-data.php';
require_once __DIR__ . '/../../validation.php';
require_once __DIR__ . '/../mobile-request.php';

/** @var list<string> */
const MOBILE_PROFILE_PATCH_ALLOWED = [
    'mobile',
    'full_address',
    'eircode',
    'location_lat',
    'location_lng',
];

/**
 * @return array<string, mixed>
 */
function mobileProfileServiceBuild(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    $fresh = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return ['ok' => false, 'message' => 'Staff not found.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    $metrics   = getStaffPortalDashboardMetrics($pdo, $email, $staffId);
    $documents = portal_staff_documents($pdo, $fresh);
    $docStatus = mobileProfileSummarizeDocuments($documents);
    $missing   = getStaffOnboardingMissingFields($fresh);

    return [
        'ok'    => true,
        'staff' => mobileProfileServiceFormatStaff($pdo, $fresh, $metrics, $docStatus, $missing),
    ];
}

/**
 * @param list<array<string, mixed>> $documents
 * @return array{total: int, valid: int, expiring: int, expired: int, items: list<array<string, mixed>>}
 */
function mobileProfileSummarizeDocuments(array $documents): array
{
    $summary = ['total' => 0, 'valid' => 0, 'expiring' => 0, 'expired' => 0, 'items' => []];

    foreach ($documents as $doc) {
        $status = strtolower((string) ($doc['status'] ?? 'valid'));
        $summary['total']++;
        if ($status === 'expiring') {
            $summary['expiring']++;
        } elseif ($status === 'expired' || $status === 'missing') {
            $summary['expired']++;
        } else {
            $summary['valid']++;
        }
        $summary['items'][] = [
            'label'  => (string) ($doc['label'] ?? ''),
            'expiry' => (string) ($doc['expiry'] ?? ''),
            'status' => $status !== '' ? $status : 'valid',
        ];
    }

    return $summary;
}

/**
 * @param list<string> $missingFields
 * @return array<string, mixed>
 */
function mobileProfileServiceFormatStaff(
    PDO $pdo,
    array $staff,
    array $metrics,
    array $docStatus,
    array $missingFields
): array {
    $profileComplete = isStaffOnboardingComplete($staff);
    $reverify        = staffRequiresProfileReverify($staff);
    $gateBlocked     = staffNeedsProfileForm($pdo, $staff);

    return [
        'id'          => (int) ($staff['id'] ?? 0),
        'personal'    => [
            'first_name'    => (string) ($staff['first_name'] ?? ''),
            'surname'       => (string) ($staff['surname'] ?? ''),
            'display_name'  => trim(((string) ($staff['first_name'] ?? '')) . ' ' . ((string) ($staff['surname'] ?? ''))),
            'email'         => (string) ($staff['email'] ?? ''),
            'date_of_birth' => (string) ($staff['date_of_birth'] ?? ''),
            'gender'        => (string) ($staff['gender'] ?? ''),
            'staff_role'    => (string) ($staff['staff_role'] ?? ''),
            'pps_masked'    => maskPpsNumber((string) ($staff['pps_number'] ?? '')),
        ],
        'contact'     => [
            'mobile'        => (string) ($staff['mobile'] ?? ''),
            'full_address'  => (string) ($staff['full_address'] ?? ''),
            'eircode'       => (string) ($staff['eircode'] ?? ''),
            'location_lat'  => $staff['location_lat'] !== null && $staff['location_lat'] !== ''
                ? (float) $staff['location_lat'] : null,
            'location_lng'  => $staff['location_lng'] !== null && $staff['location_lng'] !== ''
                ? (float) $staff['location_lng'] : null,
        ],
        'approval'    => [
            'total_registrations' => (int) ($metrics['total'] ?? 0),
            'approved'            => (int) ($metrics['approved'] ?? 0),
            'pending'             => (int) ($metrics['pending'] ?? 0),
            'rejected'            => (int) ($metrics['rejected'] ?? 0),
            'upcoming_shifts'     => (int) ($metrics['upcoming'] ?? 0),
            'completed_shifts'    => (int) ($metrics['completed'] ?? 0),
            'has_registrations'   => (bool) ($metrics['has_data'] ?? false),
        ],
        'documents'   => $docStatus,
        'profile'     => [
            'profile_complete'          => $profileComplete,
            'profile_reverify_required' => $reverify,
            'profile_gate_blocked'      => $gateBlocked,
            'onboarding_missing'      => $missingFields,
            'can_edit_limited_fields'   => mobileProfileCanEditLimitedFields($staff),
            'must_use_web_profile'      => $reverify,
        ],
    ];
}

function mobileProfileCanEditLimitedFields(array $staff): bool
{
    if (staffRequiresProfileReverify($staff)) {
        return false;
    }
    if (!isStaffOnboardingComplete($staff)) {
        return false;
    }

    return true;
}

/**
 * @return array{ok: true, staff: array}|array{ok: false, message: string, code: string, status: int, details?: array}
 */
function mobileProfileServicePatch(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $fresh   = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return ['ok' => false, 'message' => 'Staff not found.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    if (staffRequiresProfileReverify($fresh)) {
        return [
            'ok'      => false,
            'message' => 'Profile re-verification is required. Please use the web profile page.',
            'code'    => 'USE_WEB_PROFILE',
            'status'  => 403,
        ];
    }

    $validation = mobileProfileValidatePatchBody($body, $fresh);
    if (!$validation['ok']) {
        return [
            'ok'      => false,
            'message' => (string) $validation['message'],
            'code'    => (string) ($validation['code'] ?? 'VALIDATION_ERROR'),
            'status'  => (int) ($validation['status'] ?? 422),
            'details' => $validation['details'] ?? [],
        ];
    }

    $patchData = $validation['data'];
    if ($patchData === []) {
        return [
            'ok'      => false,
            'message' => 'No valid fields to update.',
            'code'    => 'NO_CHANGES',
            'status'  => 400,
        ];
    }

    $updated = updateStaffProfile($pdo, $staffId, $patchData);
    if (!$updated) {
        $unchanged = getStaffById($pdo, $staffId);

        return mobileProfileServiceBuild($pdo, $unchanged ?? $fresh);
    }

    $after = getStaffById($pdo, $staffId);

    return mobileProfileServiceBuild($pdo, $after ?? $fresh);
}

/**
 * @return array{ok: bool, message?: string, code?: string, status?: int, data?: array<string, mixed>, details?: array<string, string>}
 */
function mobileProfileValidatePatchBody(array $body, array $staff): array
{
    $blockedAlways = [
        'email', 'id', 'pps_number', 'bank_iban', 'psa_licence', 'psa_expiry_date',
        'psa_front_image', 'psa_back_image', 'first_name', 'surname', 'date_of_birth',
        'gender', 'staff_role', 'profile_completed', 'profile_token',
    ];

    $details = [];
    foreach (array_keys($body) as $key) {
        if (in_array($key, $blockedAlways, true)) {
            $details[$key] = 'Field cannot be updated via the mobile app.';
        } elseif (!in_array($key, MOBILE_PROFILE_PATCH_ALLOWED, true)) {
            $details[$key] = 'Unknown or disallowed field.';
        }
    }

    if ($details !== []) {
        return [
            'ok'      => false,
            'message' => 'One or more fields cannot be updated via the mobile app.',
            'code'    => 'FIELD_NOT_ALLOWED',
            'status'  => 403,
            'details' => $details,
        ];
    }

    if (!isStaffOnboardingComplete($staff)) {
        return [
            'ok'      => false,
            'message' => 'Complete your profile on the web before updating via the app.',
            'code'    => 'PROFILE_INCOMPLETE',
            'status'  => 403,
        ];
    }

    $data = [];
    foreach (MOBILE_PROFILE_PATCH_ALLOWED as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        $value = $body[$field];
        if ($field === 'location_lat' || $field === 'location_lng') {
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                return ['ok' => false, 'message' => 'Invalid coordinates.', 'code' => 'VALIDATION_ERROR', 'status' => 422];
            }
            $data[$field] = (string) $value;
            continue;
        }
        $data[$field] = is_string($value) ? trim($value) : $value;
    }

    if (isset($data['mobile'])) {
        $mobile = (string) $data['mobile'];
        if ($mobile !== '' && !preg_match('/^\+[1-9]\d{6,14}$/', $mobile)) {
            return [
                'ok'      => false,
                'message' => 'Please enter a valid mobile number with country code (e.g. +353871234567).',
                'code'    => 'INVALID_MOBILE',
                'status'  => 422,
            ];
        }
    }

    return ['ok' => true, 'data' => $data];
}
