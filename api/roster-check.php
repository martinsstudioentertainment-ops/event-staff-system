<?php
/**
 * Public roster diagnostic (no secrets). Use after deploy/import.
 * https://olasentra.com/api/roster-check.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/live-events-sync.php';
require_once dirname(__DIR__) . '/includes/registration-options-repository.php';

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
        $apiSample = ['error' => $e->getMessage()];
    }

    $ok = $sample
        && trim((string) ($sample['main_security_company'] ?? '')) !== ''
        && (int) ($sample['venue_id'] ?? 0) > 0;

    echo json_encode([
        'roster_ok'        => $ok,
        'master_file'      => $masterOk ? basename($masterFile) : 'missing',
        'db_sample'        => $sample,
        'registration_api' => $apiSample,
        'hint'             => $ok
            ? 'Database looks correct — hard refresh register site (Ctrl+F5).'
            : 'Run Admin → import-roster.php or Deploy HEAD commit (auto import).',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
