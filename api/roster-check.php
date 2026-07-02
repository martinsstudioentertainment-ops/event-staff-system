<?php
/**
 * Public roster diagnostic (no secrets). Use after deploy/import.
 * https://olasentra.com/api/roster-check.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/live-events-sync.php';
require_once dirname(__DIR__) . '/includes/registration-options-repository.php';
require_once dirname(__DIR__) . '/includes/app-environment.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo = getDB();
    $sample = getLiveRosterSampleEvent($pdo, 'Nick Cave');
    $masterFile = getLiveEventsMasterFilePath();
    $masterOk   = is_file($masterFile);

    $apiSample = null;
    try {
        $opts = getRegistrationOptionsForForm($pdo, 'dsp');
        foreach ($opts['eventsByVenue'] ?? [] as $list) {
            foreach ($list as $ev) {
                if (stripos((string) ($ev['name'] ?? ''), 'Nick Cave') !== false) {
                    $apiSample = $ev;
                    break 2;
                }
            }
        }
    } catch (Throwable $e) {
        $apiSample = ['error' => 'registration options unavailable'];
    }

    $ok = $sample
        && trim((string) ($sample['main_security_company'] ?? '')) !== ''
        && (int) ($sample['venue_id'] ?? 0) > 0;

    $registrationStats = null;
    $eventId = (int) ($sample['id'] ?? 0);
    if ($eventId > 0) {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_registrations,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                COUNT(DISTINCT CASE WHEN TRIM(COALESCE(email, '')) != '' THEN LOWER(TRIM(email)) END) AS unique_people
             FROM staff_registrations
             WHERE event_id = :event_id"
        );
        $stmt->execute(['event_id' => $eventId]);
        $registrationStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $payload = [
        'roster_ok'            => $ok,
        'registration_stats'   => $registrationStats,
        'registration_api'     => $apiSample,
        'hint'                 => $ok
            ? 'Database looks correct — hard refresh register site (Ctrl+F5).'
            : 'Run Admin → import-roster.php or Deploy HEAD commit (auto import).',
    ];

    if (!isProductionApp()) {
        require_once dirname(__DIR__) . '/includes/staff-repository.php';
        require_once dirname(__DIR__) . '/includes/admin-pagination.php';
        $payload['master_file'] = $masterOk ? basename($masterFile) : 'missing';
        $payload['db_sample']   = $sample;
        if ($eventId > 0) {
            $listFilters = ['q' => '', 'status' => '', 'role' => '', 'event_id' => $eventId, 'email' => ''];
            $payload['staff_list_diag'] = [
                'per_page_default'       => adminStaffListPerPage(),
                'count_registrations'    => countStaffRegistrations($pdo, $listFilters),
                'count_unique_grouped'   => countUniqueStaffRegistrants($pdo, $listFilters),
                'count_staff_list_total' => countStaffListTotal($pdo, $listFilters),
            ];
        }
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[RosterCheck] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to run roster check.'], JSON_PRETTY_PRINT);
}
