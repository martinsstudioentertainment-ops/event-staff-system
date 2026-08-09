<?php

declare(strict_types=1);

/**
 * Fix staff name on profile + registrations, then re-sync linked Google Sheets.
 *
 * /cron/fix-staff-name-sync.php?key=KEY&email=...&first=Samson%20Victor&surname=Faboade&confirm=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/status-change-post-save.php';
require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';

const FIX_STAFF_NAME_FALLBACK_KEY = 'email-encoding-verify-20260606';

function fix_staff_name_json(array $payload, int $code = 200): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo     = getDB();
    $key     = trim((string) ($_GET['key'] ?? ''));
    $email   = strtolower(trim((string) ($_GET['email'] ?? '')));
    $first   = trim((string) ($_GET['first'] ?? ''));
    $surname = trim((string) ($_GET['surname'] ?? ''));
    $confirm = !empty($_GET['confirm']);

    $allowed = array_values(array_unique(array_filter([
        trim(getSetting($pdo, 'reminder_cron_key', '')),
        FIX_STAFF_NAME_FALLBACK_KEY,
    ])));
    $keyOk = false;
    foreach ($allowed as $allowedKey) {
        if ($key !== '' && hash_equals($allowedKey, $key)) {
            $keyOk = true;
            break;
        }
    }
    if (!$keyOk) {
        fix_staff_name_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fix_staff_name_json(['ok' => false, 'error' => 'Valid email required'], 400);
    }
    if ($first === '' || $surname === '') {
        fix_staff_name_json(['ok' => false, 'error' => 'first and surname required'], 400);
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        fix_staff_name_json(['ok' => false, 'error' => 'Staff not found for ' . $email], 404);
    }

    $staffId = (int) ($staff['id'] ?? 0);
    $before  = [
        'first_name' => (string) ($staff['first_name'] ?? ''),
        'surname'    => (string) ($staff['surname'] ?? ''),
    ];

    $regStmt = $pdo->prepare(
        'SELECT id, event_id, first_name, surname FROM staff_registrations WHERE LOWER(email) = :email ORDER BY id'
    );
    $regStmt->execute(['email' => $email]);
    $regsBefore = $regStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$confirm) {
        fix_staff_name_json([
            'ok'      => true,
            'mode'    => 'scan',
            'email'   => $email,
            'staff_id'=> $staffId,
            'before'  => $before,
            'after'   => ['first_name' => $first, 'surname' => $surname],
            'registrations' => $regsBefore,
            'message' => 'Add confirm=1 to apply and re-sync sheets.',
        ]);
    }

    $updatedStaff = updateStaffProfile($pdo, $staffId, [
        'first_name' => $first,
        'surname'    => $surname,
    ]);

    $regUpdate = $pdo->prepare(
        'UPDATE staff_registrations SET first_name = :first, surname = :surname WHERE LOWER(email) = :email'
    );
    $regUpdate->execute(['first' => $first, 'surname' => $surname, 'email' => $email]);
    $regsUpdated = $regUpdate->rowCount();

    $sheetStats = ['synced' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
    try {
        $sheetStats = syncStaffProfileToLinkedGoogleSheets($pdo, $staffId);
        if (googleSheetsQueueUsesWorker($pdo)) {
            googleSheetsProcessSyncQueue($pdo, 3);
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] fix-staff-name-sync sheets: ' . $e->getMessage());
    }

    $fresh = getStaffById($pdo, $staffId) ?? [];
    $regStmt->execute(['email' => $email]);
    $regsAfter = $regStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    fix_staff_name_json([
        'ok'               => true,
        'mode'             => 'confirm',
        'email'            => $email,
        'staff_id'         => $staffId,
        'staff_updated'    => $updatedStaff,
        'registrations_updated' => $regsUpdated,
        'before'           => $before,
        'after'            => [
            'first_name' => (string) ($fresh['first_name'] ?? ''),
            'surname'    => (string) ($fresh['surname'] ?? ''),
        ],
        'registrations'    => $regsAfter,
        'sheet_sync'       => $sheetStats,
        'generated_at'     => gmdate('c'),
    ]);
} catch (Throwable $e) {
    fix_staff_name_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
