<?php

declare(strict_types=1);

/**
 * Restore Nikhil Shivashankaraiah correct BIB 1924 on June 2026 events (Teddy + Katy).
 * Also aligns Katy registration email to nicks.ks88@gmail.com.
 * GET: ?key=...&dry_run=1 | dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/registration-bib.php';

header('Content-Type: application/json; charset=UTF-8');

const NIKHIL_STAFF_ID   = 123;
const CORRECT_EMAIL     = 'nicks.ks88@gmail.com';
const CORRECT_BIB       = '1924';

/** @var list<array{registration_id: int, event_id: int, event_name: string}> */
const FIX_REGISTRATIONS = [
    ['registration_id' => 548, 'event_id' => 6, 'event_name' => 'Teddy Swoms'],
    ['registration_id' => 549, 'event_id' => 7, 'event_name' => 'Katy Perry'],
];

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $results = [];

    foreach (FIX_REGISTRATIONS as $target) {
        $regId = (int) $target['registration_id'];
        $reg = getStaffRegistrationById($pdo, $regId);
        if ($reg === null || (int) ($reg['staff_id'] ?? 0) !== NIKHIL_STAFF_ID) {
            $results[] = ['registration_id' => $regId, 'ok' => false, 'error' => 'Registration mismatch'];
            continue;
        }

        $att = getAttendanceByRegistration($pdo, $regId);
        $before = [
            'email'        => (string) ($reg['email'] ?? ''),
            'assigned_bib' => (string) ($reg['assigned_bib_number'] ?? ''),
            'attendance_bib' => (string) ($att['bib_number'] ?? ''),
        ];

        if (!$dryRun) {
            $pdo->prepare('UPDATE staff_registrations SET email = :email WHERE id = :id')
                ->execute(['email' => CORRECT_EMAIL, 'id' => $regId]);

            if (registrationBibColumnEnabled($pdo)) {
                $pdo->prepare('UPDATE staff_registrations SET assigned_bib_number = :bib WHERE id = :id')
                    ->execute(['bib' => CORRECT_BIB, 'id' => $regId]);
            }

            if ($att !== null) {
                saveAttendanceBibNumber($pdo, $regId, CORRECT_BIB);
            }
        }

        $afterReg = $dryRun ? $reg : (getStaffRegistrationById($pdo, $regId) ?: $reg);
        $afterAtt = getAttendanceByRegistration($pdo, $regId);

        $results[] = [
            'ok'              => true,
            'event_id'        => (int) $target['event_id'],
            'event_name'      => $target['event_name'],
            'registration_id' => $regId,
            'before'          => $before,
            'after'           => [
                'email'          => CORRECT_EMAIL,
                'assigned_bib'   => CORRECT_BIB,
                'attendance_bib' => $dryRun ? CORRECT_BIB : (string) (($afterAtt ?? [])['bib_number'] ?? ''),
                'checked_in'     => $afterAtt !== null,
                'hours_paid'     => $afterAtt['hours_paid'] ?? null,
            ],
        ];
    }

    echo json_encode([
        'ok'      => true,
        'dry_run' => $dryRun,
        'staff'   => 'Nikhil Kadabagere Shivashankaraiah',
        'correct_bib' => CORRECT_BIB,
        'correct_email' => CORRECT_EMAIL,
        'events_fixed' => $results,
        'note' => 'Corrected wrong test/debug BIB assignments (1060 Teddy, 1295 Katy).',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
