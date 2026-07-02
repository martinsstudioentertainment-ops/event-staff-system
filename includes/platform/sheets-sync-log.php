<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';

function logSheetsSyncEvent(PDO $pdo, string $action, string $status, ?int $eventId = null, ?int $registrationId = null, ?string $detail = null): void
{
    ensurePlatformMaturitySchema($pdo);
    $allowed = ['success', 'failed', 'queued', 'skipped'];
    if (!in_array($status, $allowed, true)) {
        $status = 'queued';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_sheets_sync_log (event_id, registration_id, action, status, detail)
            VALUES (:event_id, :reg_id, :action, :status, :detail)
        ");
        $stmt->execute([
            'event_id' => $eventId,
            'reg_id'   => $registrationId,
            'action'   => substr($action, 0, 40),
            'status'   => $status,
            'detail'   => $detail !== null ? substr($detail, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] sheets sync log: ' . $e->getMessage());
    }
}

/** @param array{ok: bool, skipped: bool, rows: int, message: string} $result */
function googleSheetsLogRebuildTabOutcome(PDO $pdo, int $eventId, array $result): void
{
    if ($eventId < 1) {
        return;
    }

    if (!empty($result['skipped']) && str_contains((string) ($result['message'] ?? ''), 'Quiet hours')) {
        return;
    }

    if (!empty($result['ok'])) {
        logSheetsSyncEvent($pdo, 'rebuild_tab', 'success', $eventId, null, (string) ($result['message'] ?? ''));
    } elseif (!empty($result['skipped'])) {
        logSheetsSyncEvent($pdo, 'rebuild_tab', 'skipped', $eventId, null, (string) ($result['message'] ?? ''));
    } else {
        logSheetsSyncEvent($pdo, 'rebuild_tab', 'failed', $eventId, null, (string) ($result['message'] ?? ''));
    }
}
