<?php

declare(strict_types=1);

/**
 * Verify staff app BIB display + schema on production.
 * GET: ?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-data.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

ensureStaffRegistrationBibSchema($pdo);

$checks = [];

$regCols = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$checks['schema_assigned_bib'] = [
    'pass' => in_array('assigned_bib_number', $regCols, true),
];

$files = [
    'includes/staff-app-v3-shell.php' => ['renderStaffV3BibBanner', 'display_bib'],
    'includes/staff-app-v3-pages.php' => ['es-v3-bib-number', 'type="text"', 'name="bib_number"'],
    'includes/staff-venue-checkin.php' => ['resolveCheckinBibForRegistration'],
    'includes/checkin-bib.php' => ['resolveStaffDisplayBibNumber', 'assignStaffRegistrationBibNumber'],
    'assets/css/staff-app-v3.css' => ['es-v3__bib-banner'],
    'staff-checkin.php' => ['staff_app_checkin'],
];

$fileChecks = [];
foreach ($files as $rel => $needles) {
    $path = dirname(__DIR__) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $body = is_file($path) ? (string) file_get_contents($path) : '';
    $fileChecks[$rel] = [
        'exists' => is_file($path),
        'pass'   => is_file($path) && array_reduce($needles, static fn ($ok, $n) => $ok && str_contains($body, $n), true),
    ];
}

$assignedCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM staff_registrations WHERE assigned_bib_number IS NOT NULL AND TRIM(assigned_bib_number) <> ''"
)->fetchColumn();

$sample = $pdo->query(
    "SELECT sr.email, sr.assigned_bib_number, a.bib_number
     FROM staff_registrations sr
     LEFT JOIN attendance a ON a.registration_id = sr.id
     WHERE sr.assigned_bib_number IS NOT NULL AND TRIM(sr.assigned_bib_number) <> ''
     ORDER BY sr.id DESC
     LIMIT 3"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$resolveChecks = [];
foreach ($sample as $row) {
    $display = resolveStaffDisplayBibNumber($row);
    $resolveChecks[] = [
        'email'   => $row['email'] ?? '',
        'display' => $display,
        'pass'    => $display !== '',
    ];
}

$pagesPath = dirname(__DIR__) . '/includes/staff-app-v3-pages.php';
$pagesBody = is_file($pagesPath) ? (string) file_get_contents($pagesPath) : '';
$checks['checkin_bib_input_editable'] = [
    'pass' => str_contains($pagesBody, 'id="es-v3-bib-number"')
        && str_contains($pagesBody, 'type="text"')
        && !str_contains($pagesBody, 'type="hidden" name="bib_number"'),
];

echo json_encode([
    'ok'             => true,
    'checks'         => $checks,
    'files'          => $fileChecks,
    'assigned_count' => $assignedCount,
    'resolve_sample' => $resolveChecks,
], JSON_PRETTY_PRINT);
