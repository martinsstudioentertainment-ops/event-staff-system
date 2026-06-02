<?php
/**
 * Import summer roster while logged into admin (no token needed).
 * URL: https://admin.olasentra.com/import-roster.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $result = syncLiveEventsFromMasterFile($pdo, false);
        logAdminAudit(
            $pdo,
            'import_live_roster',
            'system',
            0,
            "created {$result['created']}, updated {$result['updated']}"
        );
    } catch (Throwable $e) {
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">';
        echo '<h1>Import failed</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
        echo '<p><a href="events.php">Back to Events</a></p></body></html>';
        exit;
    }

    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;max-width:720px">';
    echo '<h1>Summer roster imported</h1>';
    $contractor = trim((string) ($result['main_security_company'] ?? ''));
    echo '<p><strong>Listed contractor (roster default):</strong> '
        . ($contractor !== '' ? htmlspecialchars($contractor, ENT_QUOTES, 'UTF-8') : 'none — portal-only') . '</p>';
    echo '<p>Created: ' . (int) $result['created'] . ' · Updated: ' . (int) $result['updated'] . ' · Skipped: ' . (int) $result['skipped'] . '</p>';
    echo '<ul>';
    foreach (array_slice($result['messages'], 0, 40) as $line) {
        echo '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    if (count($result['messages']) > 40) {
        echo '<li>… and ' . (count($result['messages']) - 40) . ' more</li>';
    }
    echo '</ul>';
    if ($result['errors'] !== []) {
        echo '<h2>Errors</h2><ul>';
        foreach ($result['errors'] as $err) {
            echo '<li>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    $regUrl = function_exists('getRegistrationSiteUrl') ? getRegistrationSiteUrl($pdo) : 'https://register.olasentra.com/';
    echo '<p><a href="' . htmlspecialchars($regUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open registration form ↗</a> (hard refresh Ctrl+F5)</p>';
    $sample = getLiveRosterSampleEvent($pdo, 'Nick Cave');
    echo '<h2>Verify (Nick Cave)</h2><pre style="background:#f1f5f9;padding:1rem">';
    echo htmlspecialchars(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'not found', ENT_QUOTES, 'UTF-8');
    echo '</pre>';
    if ($sample && trim((string) ($sample['main_security_company'] ?? '')) === '') {
        echo '<p style="color:#b91c1c"><strong>main_security_company still empty</strong> — check database/migrate-phase33 or contact support.</p>';
    }
    echo '<p><a href="roster-diagnostic.php">Full diagnostic</a> · <a href="events.php">Back to Events</a></p></body></html>';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import summer roster</title>
</head>
<body style="font-family:sans-serif;padding:2rem;max-width:520px">
    <h1>Import summer roster</h1>
    <p>This loads all 32 events from <code>database/live-events-2026.php</code>:</p>
    <ul>
        <li>Location / venue</li>
        <li>Staff needed</li>
        <li>Times (where set)</li>
        <li>On-site company: only if you set it per event (optional)</li>
    </ul>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" style="padding:0.75rem 1.25rem;font-size:1rem;cursor:pointer">Run import now</button>
    </form>
    <p style="margin-top:1.5rem"><a href="events.php">← Events</a></p>
</body>
</html>
