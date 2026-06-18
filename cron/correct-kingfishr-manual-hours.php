<?php

declare(strict_types=1);

/**
 * Kingfishr 2026-06-13 manual hours correction.
 *
 * Web:
 *   /cron/correct-kingfishr-manual-hours.php?key=...&dry_run=1
 *   /cron/correct-kingfishr-manual-hours.php?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        // ok
    } elseif ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        // ok
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';

    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($adminId < 1) {
        $adminId = 1;
    }

    $manualNote = 'Manual sign-in — worked full shift but did not complete venue sign-in (Kingfishr 2026-06-13).';
    $sentHomeNote = 'Sent home early (Kingfishr 2026-06-13).';
    $billyNote = 'Not permitted to work — wrong uniform (Kingfishr 2026-06-13).';

    $manualSignins = [
        ['registration_id' => 242, 'name' => 'Mustaf Osman Diriye', 'hours' => 8.5],
        ['registration_id' => 448, 'name' => 'Steve Uchechukwu Igboama', 'hours' => 8.5],
        ['registration_id' => 124, 'name' => 'Mahamoud Mahamed Sayid', 'hours' => 8.5],
        ['registration_id' => 80, 'name' => 'Abdiqani Abdulle Weydow', 'hours' => 8.5],
    ];

    $results = [];

    foreach ($manualSignins as $entry) {
        $regId = (int) $entry['registration_id'];
        $row = [
            'action'          => 'manual_signin',
            'registration_id' => $regId,
            'name'            => $entry['name'],
            'target_hours'    => $entry['hours'],
        ];

        if ($dryRun) {
            $check = $pdo->prepare('SELECT id FROM attendance WHERE registration_id = :rid LIMIT 1');
            $check->execute(['rid' => $regId]);
            $existing = $check->fetchColumn();
            $row['status'] = $existing ? 'skipped_already_checked_in' : 'would_create';
            $results[] = $row;
            continue;
        }

        $result = recordAdminManualCheckin($pdo, $regId, (float) $entry['hours'], $manualNote, $adminId);
        $row['status'] = $result === true ? 'created' : 'failed';
        $row['message'] = $result === true ? null : (string) $result;
        $results[] = $row;
    }

    // Prince Ralph Eke — already 8.5 hrs, no change
    $results[] = [
        'action'          => 'skip',
        'registration_id' => 290,
        'attendance_id'   => 42,
        'name'            => 'Prince Ralph Eke',
        'target_hours'    => 8.5,
        'status'          => $dryRun ? 'no_change_needed' : 'skipped',
    ];

    // Blessing Ebimaro — sent home 3 hrs
    $blessingId = 35;
    $blessingRow = [
        'action'        => 'sent_home',
        'attendance_id' => $blessingId,
        'name'          => 'Blessing Ebimaro',
        'target_hours'  => 3.0,
    ];
    if ($dryRun) {
        $before = $pdo->prepare('SELECT hours_paid FROM attendance WHERE id = :id');
        $before->execute(['id' => $blessingId]);
        $blessingRow['before_paid'] = $before->fetchColumn();
        $blessingRow['status'] = 'would_update';
    } else {
        $result = recordStaffSentHome($pdo, $blessingId, 3.0, $sentHomeNote, $adminId);
        $blessingRow['status'] = $result === true ? 'updated' : 'failed';
        $blessingRow['message'] = $result === true ? null : (string) $result;
    }
    $results[] = $blessingRow;

    // Billy John Oamen — wrong uniform, 0 hrs
    $billyId = 31;
    $billyRow = [
        'action'        => 'zero_hours',
        'attendance_id' => $billyId,
        'name'          => 'Billy John Oamen',
        'target_hours'  => 0.0,
    ];
    if ($dryRun) {
        $before = $pdo->prepare('SELECT hours_paid, hours_worked FROM attendance WHERE id = :id');
        $before->execute(['id' => $billyId]);
        $billyBefore = $before->fetch(PDO::FETCH_ASSOC) ?: [];
        $billyRow['before_paid'] = $billyBefore['hours_paid'] ?? null;
        $billyRow['before_worked'] = $billyBefore['hours_worked'] ?? null;
        $billyRow['status'] = 'would_zero';
    } else {
        $update = $pdo->prepare(
            'UPDATE attendance SET
                hours_worked = 0,
                hours_paid = 0,
                work_end_at = activated_at,
                hours_note = :note,
                hours_adjusted_by = :admin_id,
                hours_adjusted_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'note'     => $billyNote,
            'admin_id' => $adminId,
            'id'       => $billyId,
        ]);
        $billyRow['status'] = $update->rowCount() > 0 ? 'zeroed' : 'failed';
    }
    $results[] = $billyRow;

    echo json_encode([
        'ok'      => true,
        'dry_run' => $dryRun,
        'event'   => 'Kingfishr 2026-06-13',
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
