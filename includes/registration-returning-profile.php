<?php

declare(strict_types=1);

/**
 * Returning registrant profile summary — computed from existing data, no DB changes.
 */

require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/staff-profile-gate.php';

/** @param array<string, mixed> $registrationRow */
/** @param array<string, mixed> $staffRow */
/** @param list<array<string, mixed>> $registeredEvents */
function buildReturningRegistrantSummary(PDO $pdo, array $registrationRow, array $staffRow, array $registeredEvents): array
{
    $merged = array_merge($registrationRow, $staffRow);

    $required = getStaffOnboardingRequiredFields($merged);
    $total    = count($required);
    $missing  = getStaffOnboardingMissingFields($merged);
    $filled   = max(0, $total - count($missing));
    $pct      = $total > 0 ? (int) round(($filled / $total) * 100) : 0;

    require_once __DIR__ . '/staff-psa.php';
    require_once __DIR__ . '/registration-forms.php';
    $staffRole = normalizeStaffRole((string) ($merged['staff_role'] ?? ''));

    $hasPending = false;
    foreach ($registeredEvents as $ev) {
        if (strtolower((string) ($ev['status'] ?? '')) === 'pending') {
            $hasPending = true;
            break;
        }
    }

    $psaMissing = staffRoleRequiresPsa($staffRole)
        && (in_array('PSA licence number', $missing, true)
        || in_array('PSA card front photo', $missing, true)
        || in_array('PSA card back photo', $missing, true)
        || in_array('PSA expiry date', $missing, true));

    $psaExpiring = false;
    $expiryRaw   = trim((string) ($merged['psa_expiry_date'] ?? ''));
    if ($expiryRaw !== '') {
        $expiry = DateTime::createFromFormat('Y-m-d', $expiryRaw)
            ?: DateTime::createFromFormat('d/m/Y', $expiryRaw);
        if ($expiry instanceof DateTime) {
            $days = (int) (new DateTime('today'))->diff($expiry)->format('%r%a');
            $psaExpiring = $days >= 0 && $days <= 30;
        }
    }

    $profileComplete = $staffRow !== [] && !staffNeedsProfileForm($pdo, $staffRow);

    $statusKey   = 'incomplete';
    $statusLabel = 'Incomplete';

    if ($hasPending) {
        $statusKey   = 'review_required';
        $statusLabel = 'Review Required';
    } elseif ($psaMissing) {
        $statusKey   = 'psa_missing';
        $statusLabel = 'PSA Missing';
    } elseif ($psaExpiring) {
        $statusKey   = 'psa_expiring';
        $statusLabel = 'PSA Expiring';
    } elseif ($profileComplete && $missing === []) {
        $statusKey   = 'complete';
        $statusLabel = 'Complete';
    }

    $complianceParts = [];
    if (!staffRoleRequiresPsa($staffRole)) {
        $complianceParts[] = 'PSA not required for steward role';
    } elseif ($psaMissing) {
        $complianceParts[] = 'PSA documentation incomplete';
    } elseif ($psaExpiring) {
        $complianceParts[] = 'PSA expiring within 30 days';
    } else {
        $complianceParts[] = 'PSA on file';
    }
    if ($hasPending) {
        $complianceParts[] = 'Application(s) awaiting organiser review';
    } elseif ($profileComplete) {
        $complianceParts[] = 'Profile ready for new opportunities';
    } else {
        $complianceParts[] = 'Profile needs attention';
    }

    return [
        'profile_completion_pct' => min(100, max(0, $pct)),
        'profile_status'         => $statusKey,
        'profile_status_label'   => $statusLabel,
        'compliance_status'      => implode(' · ', $complianceParts),
        'profile_complete'       => $profileComplete,
        'events_applied_count'   => count($registeredEvents),
        'has_pending_review'     => $hasPending,
        'missing_field_count'    => count($missing),
    ];
}

/**
 * Prefill registration POST from saved staff + latest registration (returning members).
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed>|null $existingStaff
 * @return array<string, mixed>
 */
function mergeReturningRegistrantPostData(PDO $pdo, array $data, ?array $existingStaff): array
{
    require_once __DIR__ . '/staff-repository.php';

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '' || !is_array($existingStaff) || staffNeedsProfileForm($pdo, $existingStaff)) {
        return $data;
    }

    $latest  = getLatestRegistrationByEmail($pdo, $email) ?? [];
    $sources = array_merge($latest, $existingStaff);

    foreach ([
        'surname', 'first_name', 'full_address', 'eircode', 'date_of_birth', 'gender',
        'mobile', 'pps_number', 'bank_iban', 'staff_role', 'psa_licence', 'psa_expiry_date',
    ] as $field) {
        if (trim((string) ($data[$field] ?? '')) === '' && trim((string) ($sources[$field] ?? '')) !== '') {
            $data[$field] = $sources[$field];
        }
    }

    if (trim((string) ($data['form_slug'] ?? '')) === '' && trim((string) ($sources['staff_role'] ?? '')) !== '') {
        require_once __DIR__ . '/registration-forms.php';
        $data['form_slug'] = staffRoleToFormSlug(normalizeStaffRole((string) $sources['staff_role']));
    }

    return $data;
}

/**
 * Server-side form defaults for Gmail-verified returning registrants.
 *
 * @param array<string, mixed> $old
 * @return array<string, mixed>
 */
function applyReturningRegistrantPrefill(PDO $pdo, string $email, array $old): array
{
    require_once __DIR__ . '/staff-repository.php';

    $email = strtolower(trim($email));
    if ($email === '') {
        return $old;
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        $latest = getLatestRegistrationByEmail($pdo, $email);
        if ($latest === null) {
            return $old;
        }

        return array_merge($latest, $old);
    }

    if (staffNeedsProfileForm($pdo, $staff)) {
        return array_merge(getLatestRegistrationByEmail($pdo, $email) ?? [], $old);
    }

    return mergeReturningRegistrantPostData($pdo, array_merge(getLatestRegistrationByEmail($pdo, $email) ?? [], $old), $staff);
}
