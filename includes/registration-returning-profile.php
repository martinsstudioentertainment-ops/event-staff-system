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

    $required = getStaffOnboardingRequiredFields();
    $total    = count($required);
    $missing  = getStaffOnboardingMissingFields($merged);
    $filled   = max(0, $total - count($missing));
    $pct      = $total > 0 ? (int) round(($filled / $total) * 100) : 0;

    $hasPending = false;
    foreach ($registeredEvents as $ev) {
        if (strtolower((string) ($ev['status'] ?? '')) === 'pending') {
            $hasPending = true;
            break;
        }
    }

    $psaMissing = in_array('PSA licence number', $missing, true)
        || in_array('PSA card front photo', $missing, true)
        || in_array('PSA card back photo', $missing, true)
        || in_array('PSA expiry date', $missing, true);

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
    if ($psaMissing) {
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
