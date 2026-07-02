<?php

declare(strict_types=1);

/**
 * Google Sheets production cleanup — Apply vault + event registration sheets.
 *
 * Audit:
 *   /cron/google-sheets-cleanup-production.php?key=...
 *
 * Apply cleanup + full resync:
 *   /cron/google-sheets-cleanup-production.php?key=...&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/google-sheets-control.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/platform/apply-vault-bridge.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    $report = [
        'ok'          => true,
        'applied'     => $apply,
        'generated_at'=> gmdate('c'),
    ];

    $linkedEvents = (int) $pdo->query(
        "SELECT COUNT(*) FROM events WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> ''"
    )->fetchColumn();

    $eventRowsBefore = 0;
    if ($apply) {
        try {
            $eventRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations WHERE status = \'approved\'')->fetchColumn();
        } catch (Throwable $e) {
            $eventRowsBefore = 0;
        }
    }

    $applyResult = null;
    $applyUrl    = rtrim(getSetting($pdo, 'apply_site_base_url', 'https://apply.olasentra.com'), '/')
        . '/admin/cron/sheets-cleanup-production.php?key=' . urlencode($key)
        . ($apply ? '&apply=1' : '');

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 300,
            'ignore_errors' => true,
        ],
    ]);
    $applyBody = @file_get_contents($applyUrl, false, $ctx);
    if (is_string($applyBody) && $applyBody !== '') {
        $decoded = json_decode($applyBody, true);
        if (is_array($decoded)) {
            $applyResult = $decoded;
        }
    }

    $eventResync = null;
    if ($apply) {
        require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';
        $eventIds = $pdo->query(
            "SELECT id FROM events WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> '' AND is_active = 1 ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $eventIds = array_map('intval', $eventIds);

        $direct = !googleSheetsQueueUsesWorker($pdo) || (string) ($_GET['direct'] ?? '') === '1';
        if ($direct) {
            $eventResync = ['events' => count($eventIds), 'success' => 0, 'failed' => 0, 'queued' => 0, 'results' => []];
            foreach ($eventIds as $eventId) {
                try {
                    $result = googleSheetsRebuildEventTab($pdo, (int) $eventId, null, false);
                    if (!empty($result['ok'])) {
                        ++$eventResync['success'];
                    } else {
                        ++$eventResync['failed'];
                    }
                    if (count($eventResync['results']) < 15) {
                        $eventResync['results'][] = [
                            'event_id' => (int) $eventId,
                            'ok'       => !empty($result['ok']),
                            'rows'     => (int) ($result['rows'] ?? 0),
                            'message'  => (string) ($result['message'] ?? ''),
                        ];
                    }
                } catch (Throwable $e) {
                    ++$eventResync['failed'];
                }
            }
        } else {
            $eventResync = resyncAllEventSheets($pdo);
        }
        productionHealthRecordSetting($pdo, 'production_health_last_google_sheets_cleanup_at', gmdate('Y-m-d H:i:s'));
    }

    $verification = auditGoogleSheetsSynchronization($pdo);

    $applyClean = is_array($applyResult)
        && (($applyResult['google_sheets_status'] ?? '') === 'CLEAN'
            || (($applyResult['verification']['pass'] ?? false) && !empty($applyResult['sync']['ok'])));

    $eventFailed = (int) ($eventResync['failed'] ?? 0);
    $clean       = !$apply || ($applyClean && $eventFailed === 0);

    $report['apply_vault_and_central_sheets'] = $applyResult;
    $report['event_registration_sheets']    = [
        'linked_events'     => $linkedEvents,
        'approved_reg_rows' => $eventRowsBefore,
        'resync'            => $eventResync,
    ];
    $report['google_sync_audit']              = $verification;
    $report['summary']                        = [
        'vault_rows_before'       => (int) ($applyResult['vault_cleanup']['vault_rows_before'] ?? 0),
        'vault_rows_deleted'      => (int) ($applyResult['vault_cleanup']['rows_deleted'] ?? 0),
        'test_rows_deleted'       => (int) ($applyResult['vault_cleanup']['deleted_breakdown']['test_record'] ?? 0),
        'merged_alias_deleted'    => (int) ($applyResult['vault_cleanup']['deleted_breakdown']['merged_staff_alias'] ?? 0),
        'duplicate_rows_deleted'  => (int) (($applyResult['vault_cleanup']['deleted_breakdown']['duplicate_email'] ?? 0)
            + ($applyResult['vault_cleanup']['deleted_breakdown']['duplicate_phone_non_erp'] ?? 0)
            + ($applyResult['vault_cleanup']['deleted_breakdown']['duplicate_psa_non_erp'] ?? 0)
            + ($applyResult['vault_cleanup']['deleted_breakdown']['duplicate_pps_non_erp'] ?? 0)),
        'vault_rows_after'        => (int) ($applyResult['vault_cleanup']['vault_rows_after'] ?? 0),
        'payroll_sheet_before'    => (int) ($applyResult['sheets_before']['payroll'] ?? 0),
        'payroll_sheet_after'     => (int) ($applyResult['sheets_after']['payroll'] ?? 0),
        'master_sheet_before'     => (int) ($applyResult['sheets_before']['master'] ?? 0),
        'master_sheet_after'      => (int) ($applyResult['sheets_after']['master'] ?? 0),
        'psa_sheet_before'        => (int) ($applyResult['sheets_before']['psa'] ?? 0),
        'psa_sheet_after'         => (int) ($applyResult['sheets_after']['psa'] ?? 0),
        'event_sheets_resynced'   => (int) ($eventResync['success'] ?? 0),
        'event_sheets_failed'     => $eventFailed,
        'manual_review'           => $applyResult['manual_review'] ?? [],
    ];
    $report['google_sheets_status']          = $clean ? 'CLEAN' : ($apply ? 'REVIEW REQUIRED' : 'AUDIT ONLY');
    $report['google_sheets_status_display']  = $clean
        ? 'Google Sheets Status: CLEAN ✅'
        : ($apply ? 'Google Sheets Status: REVIEW REQUIRED ⚠️' : 'Google Sheets Status: AUDIT ONLY');

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
