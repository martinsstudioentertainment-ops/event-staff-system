<?php

declare(strict_types=1);

/**
 * Confirm Mpho Mathaba on-site for Teddy Swoms and assign bib for contractor sheet.
 * GET: ?key=...&dry_run=1 | dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/registration-bib.php';

header('Content-Type: application/json; charset=UTF-8');

const TEDDY_EVENT_ID       = 6;
const MPBO_REGISTRATION_ID = 534;
const MPBO_BIB             = '1958';
const MPBO_NOTE            = 'Teddy Swoms 2026-06-23 — admin confirmed on-site (client verified). Worked full shift 7.5 h.';

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';

    $reg = getStaffRegistrationById($pdo, MPBO_REGISTRATION_ID);
    if ($reg === null || (int) ($reg['event_id'] ?? 0) !== TEDDY_EVENT_ID) {
        exit(json_encode(['ok' => false, 'error' => 'Mpho Teddy Swoms registration not found'], JSON_PRETTY_PRINT));
    }

    $att = getAttendanceByRegistration($pdo, MPBO_REGISTRATION_ID);
    if ($att === null) {
        exit(json_encode(['ok' => false, 'error' => 'No attendance row for Mpho on Teddy Swoms'], JSON_PRETTY_PRINT));
    }

    $before = [
        'attendance_id'      => (int) $att['id'],
        'checked_in_method'  => (string) ($att['checked_in_method'] ?? ''),
        'hours_note'         => (string) ($att['hours_note'] ?? ''),
        'bib_number'         => (string) ($att['bib_number'] ?? ''),
        'assigned_bib'     => (string) ($reg['assigned_bib_number'] ?? ''),
        'hours_paid'         => (float) ($att['hours_paid'] ?? 0),
    ];

    if (!$dryRun) {
        $adminId = (int) ($pdo->query('SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

        $update = $pdo->prepare(
            "UPDATE attendance SET
                checked_in_method = 'admin_manual',
                attendance_status = 'completed',
                hours_note = :note,
                hours_adjusted_by = :admin_id,
                hours_adjusted_at = NOW()
             WHERE id = :id AND registration_id = :registration_id"
        );
        $update->execute([
            'note'            => MPBO_NOTE,
            'admin_id'        => $adminId,
            'id'              => (int) $att['id'],
            'registration_id' => MPBO_REGISTRATION_ID,
        ]);

        saveAttendanceBibNumber($pdo, MPBO_REGISTRATION_ID, MPBO_BIB);

        if (registrationBibColumnEnabled($pdo)) {
            $pdo->prepare(
                'UPDATE staff_registrations SET assigned_bib_number = :bib WHERE id = :id'
            )->execute(['bib' => MPBO_BIB, 'id' => MPBO_REGISTRATION_ID]);
        }
    }

    $afterAtt = $dryRun ? $att : (getAttendanceByRegistration($pdo, MPBO_REGISTRATION_ID) ?: $att);
    $afterReg = $dryRun ? $reg : (getStaffRegistrationById($pdo, MPBO_REGISTRATION_ID) ?: $reg);

    echo json_encode([
        'ok'      => true,
        'dry_run' => $dryRun,
        'event'   => getEventById($pdo, TEDDY_EVENT_ID),
        'staff'   => [
            'name'            => trim(($reg['first_name'] ?? '') . ' ' . ($reg['surname'] ?? '')),
            'email'           => $reg['email'] ?? '',
            'registration_id' => MPBO_REGISTRATION_ID,
        ],
        'before' => $before,
        'after'  => [
            'checked_in_method' => $dryRun ? 'admin_manual' : (string) ($afterAtt['checked_in_method'] ?? ''),
            'hours_note'        => $dryRun ? MPBO_NOTE : (string) ($afterAtt['hours_note'] ?? ''),
            'bib_number'        => $dryRun ? MPBO_BIB : (string) ($afterAtt['bib_number'] ?? ''),
            'assigned_bib'      => $dryRun ? MPBO_BIB : (string) ($afterReg['assigned_bib_number'] ?? ''),
            'on_site_confirmed' => true,
            'on_contractor_sheet' => true,
        ],
        'sheet_row' => [
            'first_name' => 'Mpho',
            'surname'    => 'Mathaba',
            'bib'        => MPBO_BIB,
            'email'      => 'mmathaba35@gmail.com',
        ],
        'admin_urls' => [
            'attendance'       => 'https://admin.olasentra.com/admin/attendance.php?event_id=' . TEDDY_EVENT_ID,
            'contractor_sheet' => 'https://admin.olasentra.com/admin/contractor-sheet.php?event_id=' . TEDDY_EVENT_ID,
            'work_hours'       => 'https://admin.olasentra.com/admin/work-hours.php?event_id=' . TEDDY_EVENT_ID,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
