<?php

declare(strict_types=1);

require_once __DIR__ . '/MobileAttendanceService.php';
require_once __DIR__ . '/MobileShiftService.php';
require_once __DIR__ . '/MobileAvailabilityService.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../schema/mobile-api-schema.php';

/** @return list<string> */
function mobileOfflineSupportedActions(): array
{
    return [
        'checkin',
        'checkout',
        'gps_ping',
        'shift_respond',
        'availability_set',
        'leave_request',
    ];
}

function mobileOfflineWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('offline_sync_' . $staffId, 30, 60);
}

function mobileOfflineEnsureSchema(PDO $pdo): void
{
    ensureMobileApiSchema($pdo);

    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'mobile_offline_actions'")->fetchColumn();
        if ($exists) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $path = dirname(__DIR__, 3) . '/database/migrate-phase69-mobile-offline-sync.sql';
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            // Table may already exist.
        }
    }
}

function mobileOfflineValidateClientId(string $clientId): bool
{
    $clientId = trim($clientId);

    return $clientId !== '' && preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $clientId) === 1;
}

/**
 * @return array<string, mixed>|null
 */
function mobileOfflineFindExisting(PDO $pdo, int $staffId, string $clientId): ?array
{
    mobileOfflineEnsureSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM mobile_offline_actions
             WHERE staff_id = :staff_id AND client_id = :client_id
             LIMIT 1'
        );
        $stmt->execute(['staff_id' => $staffId, 'client_id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[MobileAPI] offline find: ' . $e->getMessage());

        return null;
    }
}

/**
 * @param array<string, mixed> $result
 */
function mobileOfflineStoreAction(
    PDO $pdo,
    int $staffId,
    string $clientId,
    string $action,
    array $payload,
    string $status,
    array $result,
    ?string $conflictReason = null
): void {
    mobileOfflineEnsureSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mobile_offline_actions
                (staff_id, client_id, action_type, payload_json, status, result_json, conflict_reason, synced_at)
             VALUES
                (:staff_id, :client_id, :action_type, :payload_json, :status, :result_json, :conflict_reason, NOW())
             ON DUPLICATE KEY UPDATE
                action_type = VALUES(action_type),
                payload_json = VALUES(payload_json),
                status = VALUES(status),
                result_json = VALUES(result_json),
                conflict_reason = VALUES(conflict_reason),
                synced_at = NOW()'
        );
        $stmt->execute([
            'staff_id'        => $staffId,
            'client_id'       => $clientId,
            'action_type'     => $action,
            'payload_json'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json'     => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'          => $status,
            'conflict_reason' => $conflictReason,
        ]);
    } catch (Throwable $e) {
        error_log('[MobileAPI] offline store: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, status: string, result: array, conflict_reason?: string}
 */
function mobileOfflineProcessAction(PDO $pdo, array $staff, string $action, array $payload): array
{
    $action = strtolower(trim($action));

    $result = match ($action) {
        'checkin'           => mobileAttendanceServiceCheckin($pdo, $staff, $payload),
        'checkout'          => mobileAttendanceServiceCheckout($pdo, $staff, $payload),
        'gps_ping'          => mobileAttendanceServiceGpsPing($pdo, $staff, $payload),
        'shift_respond'     => mobileShiftServiceRespond(
            $pdo,
            $staff,
            (int) ($payload['registration_id'] ?? 0),
            (string) ($payload['response'] ?? '')
        ),
        'availability_set'  => mobileAvailabilityServiceSetDay(
            $pdo,
            $staff,
            (string) ($payload['date'] ?? ''),
            $payload
        ),
        'leave_request'     => mobileAvailabilityServiceLeave($pdo, $staff, $payload),
        default             => [
            'ok'      => false,
            'message' => 'Unsupported offline action.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ],
    };

    if (!empty($result['ok'])) {
        $clean = $result;
        unset($clean['ok']);

        return [
            'ok'     => true,
            'status' => 'synced',
            'result' => $clean,
        ];
    }

    $code = (string) ($result['code'] ?? 'ERROR');
    if ($code === 'CONFLICT') {
        return [
            'ok'              => false,
            'status'          => 'conflict',
            'result'          => [
                'code'    => $code,
                'message' => (string) ($result['message'] ?? 'Conflict'),
            ],
            'conflict_reason' => (string) ($result['message'] ?? 'Conflict'),
        ];
    }

    return [
        'ok'     => false,
        'status' => 'failed',
        'result' => [
            'code'    => $code,
            'message' => (string) ($result['message'] ?? 'Failed'),
            'status'  => (int) ($result['status'] ?? 400),
        ],
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array{ok: true, synced: int, failed: int, conflicts: int, duplicates: int, results: list}|array{ok: false, message: string, code: string, status: int}
 */
function mobileOfflineSyncServiceProcess(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileOfflineWriteThrottle($staffId);

    if ($staffId < 1) {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $items = $body['items'] ?? null;
    if (!is_array($items)) {
        return [
            'ok'      => false,
            'message' => 'Items array is required.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if (count($items) > 50) {
        return [
            'ok'      => false,
            'message' => 'Maximum 50 items per sync request.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    mobileOfflineEnsureSchema($pdo);

    $synced = 0;
    $failed = 0;
    $conflicts = 0;
    $duplicates = 0;
    $results = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $failed++;
            $results[] = [
                'index'     => $index,
                'client_id' => '',
                'action'    => '',
                'status'    => 'failed',
                'result'    => ['message' => 'Invalid item.', 'code' => 'VALIDATION_ERROR'],
            ];
            continue;
        }

        $clientId = trim((string) ($item['client_id'] ?? ''));
        $action   = strtolower(trim((string) ($item['action'] ?? '')));
        $payload  = is_array($item['payload'] ?? null) ? $item['payload'] : [];

        if (!mobileOfflineValidateClientId($clientId)) {
            $failed++;
            $results[] = [
                'index'     => $index,
                'client_id' => $clientId,
                'action'    => $action,
                'status'    => 'failed',
                'result'    => ['message' => 'Invalid client_id.', 'code' => 'VALIDATION_ERROR'],
            ];
            continue;
        }

        if (!in_array($action, mobileOfflineSupportedActions(), true)) {
            $failed++;
            $results[] = [
                'index'     => $index,
                'client_id' => $clientId,
                'action'    => $action,
                'status'    => 'failed',
                'result'    => ['message' => 'Unsupported action.', 'code' => 'VALIDATION_ERROR'],
            ];
            continue;
        }

        $existing = mobileOfflineFindExisting($pdo, $staffId, $clientId);
        if ($existing !== null) {
            $existingStatus = (string) ($existing['status'] ?? '');
            $storedResult   = json_decode((string) ($existing['result_json'] ?? '{}'), true);
            if (!is_array($storedResult)) {
                $storedResult = [];
            }

            if (in_array($existingStatus, ['synced', 'duplicate', 'conflict', 'failed'], true)) {
                $duplicates++;
                $results[] = [
                    'index'           => $index,
                    'client_id'       => $clientId,
                    'action'          => (string) ($existing['action_type'] ?? $action),
                    'status'          => 'duplicate',
                    'result'          => $storedResult,
                    'conflict_reason' => $existing['conflict_reason'] ?? null,
                ];
                continue;
            }
        }

        $processed = mobileOfflineProcessAction($pdo, $staff, $action, $payload);
        $status    = (string) ($processed['status'] ?? 'failed');
        $resultPayload = $processed['result'] ?? [];

        mobileOfflineStoreAction(
            $pdo,
            $staffId,
            $clientId,
            $action,
            $payload,
            $status,
            $resultPayload,
            $processed['conflict_reason'] ?? null
        );

        if ($status === 'synced') {
            $synced++;
        } elseif ($status === 'conflict') {
            $conflicts++;
        } else {
            $failed++;
        }

        $entry = [
            'index'     => $index,
            'client_id' => $clientId,
            'action'    => $action,
            'status'    => $status,
            'result'    => $resultPayload,
        ];
        if (isset($processed['conflict_reason'])) {
            $entry['conflict_reason'] = $processed['conflict_reason'];
        }
        $results[] = $entry;
    }

    return [
        'ok'         => true,
        'synced'     => $synced,
        'failed'     => $failed,
        'conflicts'  => $conflicts,
        'duplicates' => $duplicates,
        'results'    => $results,
    ];
}
