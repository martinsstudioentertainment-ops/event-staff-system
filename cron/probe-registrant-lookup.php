<?php

declare(strict_types=1);

/**
 * Debug profile-only returning-user lookup (step-by-step).
 * /cron/probe-registrant-lookup.php?key=email-encoding-verify-20260606&email=...
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/registration-forms.php';
require_once dirname(__DIR__) . '/includes/staff-profile-gate.php';
require_once dirname(__DIR__) . '/includes/staff-onboarding.php';
require_once dirname(__DIR__) . '/includes/registration-returning-profile.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$fallbackKey = 'email-encoding-verify-20260606';

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));

    if (!(($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) || hash_equals($fallbackKey, $providedKey))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'email parameter required'], JSON_PRETTY_PRINT);
        exit;
    }

    $steps = [];

    $row = getLatestRegistrationByEmail($pdo, $email);
    $steps['registration_row'] = $row !== null;

    $staffRow = getStaffByEmail($pdo, $email) ?: [];
    $steps['staff_row'] = $staffRow !== [];

    if ($row === null && $staffRow !== []) {
        $row = $staffRow;
        $steps['used_staff_fallback'] = true;
    }

    if ($row === null) {
        echo json_encode(['ok' => true, 'found' => false, 'steps' => $steps], JSON_PRETTY_PRINT);
        exit;
    }

    $registeredEvents = getRegisteredEventsSummaryByEmail($pdo, $email);
    $steps['registered_events'] = count($registeredEvents);

    $role = normalizeStaffRole((string) ($row['staff_role'] ?? ''));
    $steps['role'] = $role;

    $profileComplete = $staffRow !== [] && !staffNeedsProfileForm($pdo, $staffRow);
    $steps['profile_complete'] = $profileComplete;

    $summary = buildReturningRegistrantSummary($pdo, $row, $staffRow, $registeredEvents);
    $steps['summary_ok'] = true;

    $formSlug = staffRoleToFormSlug($role);
    $steps['form_slug'] = $formSlug;

    echo json_encode([
        'ok' => true,
        'found' => true,
        'steps' => $steps,
        'profile_complete' => $profileComplete,
        'summary' => $summary,
        'form_slug' => $formSlug,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'steps' => $steps ?? [],
    ], JSON_PRETTY_PRINT);
}
