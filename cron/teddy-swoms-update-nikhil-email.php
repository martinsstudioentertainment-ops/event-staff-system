<?php

declare(strict_types=1);

/**
 * Align Nikhil Teddy Swoms registration with spreadsheet email (nicks.ks88@gmail.com).
 * Resolves duplicate staff row if the newer duplicate has no registrations.
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

const TEDDY_EVENT_ID        = 6;
const NIKHIL_REG_ID         = 548;
const NIKHIL_PRIMARY_STAFF  = 123;
const NIKHIL_DUPLICATE_STAFF = 188;
const CORRECT_EMAIL         = 'nicks.ks88@gmail.com';
const OLD_EMAIL             = 'nikicricket@gmail.com';
const NIKHIL_BIB            = '1060';

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';

    $reg = getStaffRegistrationById($pdo, NIKHIL_REG_ID);
    if ($reg === null || (int) ($reg['event_id'] ?? 0) !== TEDDY_EVENT_ID) {
        exit(json_encode(['ok' => false, 'error' => 'Nikhil Teddy Swoms registration not found'], JSON_PRETTY_PRINT));
    }

    $dupCount = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :sid');
    $dupCount->execute(['sid' => NIKHIL_DUPLICATE_STAFF]);
    $duplicateRegCount = (int) $dupCount->fetchColumn();

    $actions = [];

    if ($duplicateRegCount === 0) {
        $actions[] = 'remove_duplicate_staff_' . NIKHIL_DUPLICATE_STAFF;
    } else {
        $actions[] = 'keep_duplicate_staff_' . NIKHIL_DUPLICATE_STAFF . '_has_regs';
    }

    $actions[] = 'update_staff_' . NIKHIL_PRIMARY_STAFF . '_email';
    $actions[] = 'update_registration_' . NIKHIL_REG_ID . '_email';
    $actions[] = 'assign_bib_' . NIKHIL_BIB;

    if (!$dryRun) {
        if ($duplicateRegCount === 0) {
            $pdo->prepare('DELETE FROM staff WHERE id = :id AND email = :email')
                ->execute(['id' => NIKHIL_DUPLICATE_STAFF, 'email' => CORRECT_EMAIL]);
        }

        $pdo->prepare('UPDATE staff SET email = :email WHERE id = :id')
            ->execute(['email' => CORRECT_EMAIL, 'id' => NIKHIL_PRIMARY_STAFF]);

        $pdo->prepare('UPDATE staff_registrations SET email = :email WHERE id = :id')
            ->execute(['email' => CORRECT_EMAIL, 'id' => NIKHIL_REG_ID]);

        if (registrationBibColumnEnabled($pdo)) {
            $pdo->prepare('UPDATE staff_registrations SET assigned_bib_number = :bib WHERE id = :id')
                ->execute(['bib' => NIKHIL_BIB, 'id' => NIKHIL_REG_ID]);
        }

        saveAttendanceBibNumber($pdo, NIKHIL_REG_ID, NIKHIL_BIB);
    }

    $afterReg = $dryRun ? $reg : (getStaffRegistrationById($pdo, NIKHIL_REG_ID) ?: $reg);
    $afterAtt = getAttendanceByRegistration($pdo, NIKHIL_REG_ID);

    echo json_encode([
        'ok'      => true,
        'dry_run' => $dryRun,
        'actions' => $actions,
        'before'  => [
            'staff_email'        => OLD_EMAIL,
            'registration_email' => (string) ($reg['email'] ?? ''),
            'assigned_bib'       => (string) ($reg['assigned_bib_number'] ?? ''),
            'attendance_bib'     => (string) ($afterAtt['bib_number'] ?? ''),
        ],
        'after' => [
            'staff_email'        => CORRECT_EMAIL,
            'registration_email' => $dryRun ? CORRECT_EMAIL : (string) ($afterReg['email'] ?? ''),
            'assigned_bib'       => NIKHIL_BIB,
            'attendance_bib'     => $dryRun ? NIKHIL_BIB : (string) (($afterAtt ?? [])['bib_number'] ?? ''),
            'checked_in'         => $afterAtt !== null,
            'hours_paid'         => $afterAtt['hours_paid'] ?? null,
        ],
        'sheet_row' => [
            'first_name' => 'Nikhil Kadabagere',
            'surname'    => 'Shivashankaraiah',
            'bib'        => NIKHIL_BIB,
            'email'      => CORRECT_EMAIL,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
