<?php
/**
 * Google Sheets API connectivity test (admin only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

requireAdminCapability('settings');

$path = getGoogleServiceAccountPath();
$sa   = loadGoogleServiceAccount();
$rows = [];
$flash = '';
$driveQuotaIssue = false;

$rows[] = ['PHP cURL extension', function_exists('curl_init') ? 'pass' : 'fail', function_exists('curl_init') ? 'Available' : 'Missing — hosting must enable curl'];
$rows[] = ['PHP OpenSSL', extension_loaded('openssl') ? 'pass' : 'fail', extension_loaded('openssl') ? 'Available' : 'Missing — required for service account JWT'];
$rows[] = ['Credentials file', is_file($path) ? 'pass' : 'fail', is_file($path) ? h($path) : 'Upload JSON in Settings → Google Sheets'];

$projectId = is_array($sa) ? (string) ($sa['project_id'] ?? '') : '';
$email     = is_array($sa) ? (string) ($sa['client_email'] ?? '') : '';
$rows[] = ['Service account email', $email !== '' ? 'pass' : 'fail', $email !== '' ? h($email) : '—'];
$rows[] = ['Google Cloud project_id (from JSON)', $projectId !== '' ? 'pass' : 'warn', $projectId !== '' ? h($projectId) : '—'];

$keyOk = false;
if (is_array($sa) && !empty($sa['private_key'])) {
    $key = openssl_pkey_get_private((string) $sa['private_key']);
    $keyOk = $key !== false;
}
$rows[] = ['Private key parses', $keyOk ? 'pass' : 'fail', $keyOk ? 'OK' : 'Re-download JSON key from Google Cloud and upload again'];

$token = null;
if ($keyOk && is_array($sa)) {
    $token = googleSheetsGetAccessToken($sa, [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive',
    ]);
}
$rows[] = ['OAuth access token', ($token ?? '') !== '' ? 'pass' : 'fail', ($token ?? '') !== '' ? 'Received' : h(getLastGoogleSheetsApiError() ?: 'Token request failed — see log')];

$ownedCount = 0;
if (($token ?? '') !== '' && is_array($sa)) {
    $owned = googleDriveListOwnedSpreadsheets($sa, 1000);
    $ownedCount = count($owned);
    $rows[] = [
        'Service account Drive spreadsheets',
        $ownedCount < 400 ? 'pass' : 'warn',
        (string) $ownedCount . ' owned by the service account (auto-created + tests)',
    ];
}

if (($token ?? '') !== '' && is_array($sa) && isset($_GET['purge_test'])) {
    $purge = googleDrivePurgeTestSpreadsheets($sa);
    $flash = $purge['message'];
    $owned = googleDriveListOwnedSpreadsheets($sa, 1000);
    $ownedCount = count($owned);
}

$probe = null;
if (($token ?? '') !== '' && is_array($sa) && isset($_GET['test_create'])) {
    $probe = googleSheetsProbeCreate($sa, 'Event Staff API probe ' . date('Y-m-d H:i'));
    $driveSummary = (string) ($probe['drive']['summary'] ?? '');
    $driveQuotaIssue = str_contains(mb_strtolower($driveSummary), 'storage quota');
    $rows[] = [
        'Drive API create probe',
        ($probe['drive']['code'] ?? 0) >= 200 && ($probe['drive']['code'] ?? 0) < 300 ? 'pass' : 'fail',
        'HTTP ' . (int) ($probe['drive']['code'] ?? 0) . ' — ' . h($driveSummary),
    ];
    $rows[] = [
        'Sheets API create probe',
        ($probe['sheets']['code'] ?? 0) >= 200 && ($probe['sheets']['code'] ?? 0) < 300 ? 'pass' : 'fail',
        'HTTP ' . (int) ($probe['sheets']['code'] ?? 0) . ' — ' . h((string) ($probe['sheets']['summary'] ?? '')),
    ];
}

$createOk = false;
$createDetail = '';
$permissionHint = '';
if (($token ?? '') !== '' && is_array($sa) && isset($_GET['test_create'])) {
    $test = googleSheetsCreateSpreadsheet($sa, 'Event Staff API test ' . date('Y-m-d H:i'), 'Registrations');
    $createOk     = $test !== null;
    if ($createOk && isset($test['spreadsheetId'])) {
        googleDriveDeleteFile($sa, $test['spreadsheetId']);
        $createDetail = 'Created OK (test sheet removed to save quota)';
    } else {
        $createDetail = h(getLastGoogleSheetsApiError() ?: 'Unknown error');
    }
    if (!$createOk) {
        $permissionHint = googleSheetsCreatePermissionHint(getLastGoogleSheetsApiError(), $projectId !== '' ? $projectId : null);
        if (str_contains(mb_strtolower(getLastGoogleSheetsApiError()), 'storage quota')) {
            $driveQuotaIssue = true;
        }
    }
}
if (isset($_GET['test_create'])) {
    $rows[] = ['Create test spreadsheet (app)', $createOk ? 'pass' : 'fail', $createDetail];
}

$pageTitle  = 'Google Sheets diagnostic';
$activePage = 'settings-production';
include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Google Sheets diagnostic</h2>
        <p class="card__subtitle">Use this when <strong>Create Google Sheet(s)</strong> fails on Events.</p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert--info" style="margin-bottom:1rem"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($driveQuotaIssue): ?>
        <div class="alert alert--warning" style="margin-bottom:1rem">
            <strong>Drive storage full</strong>
            <p style="margin:0.5rem 0 0">Each diagnostic run and bulk create adds spreadsheets to the <strong>service account’s</strong> Drive (not your personal Gmail). Repeated tests can fill that quota.</p>
            <ol style="margin:0.5rem 0 0 1.25rem">
                <li>Click <strong>Purge test spreadsheets</strong> below (removes names containing “Event Staff API probe/test”).</li>
                <li>If you already ran bulk create, those real event sheets stay — only tests are removed. If quota is still full, delete unneeded sheets via API or use a new service account.</li>
                <li>Then run <strong>create test</strong> once (not repeatedly).</li>
            </ol>
        </div>
    <?php endif; ?>

    <table class="data-table">
        <thead>
            <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as [$label, $status, $detail]): ?>
                <tr>
                    <td><?= h($label) ?></td>
                    <td><span class="badge badge--<?= $status === 'pass' ? 'approved' : ($status === 'warn' ? 'pending' : 'rejected') ?>"><?= h(strtoupper($status)) ?></span></td>
                    <td style="word-break:break-word"><?= $detail ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($projectId !== ''): ?>
        <p class="form-hint" style="margin-top:1rem">
            In Google Cloud, enable <strong>Google Sheets API</strong> and <strong>Google Drive API</strong> on project
            <code><?= h($projectId) ?></code> (must match this JSON file).
        </p>
    <?php endif; ?>

    <?php if ($permissionHint !== '' && !$driveQuotaIssue): ?>
        <div class="alert alert--warning" style="margin-top:1rem">
            <strong>How to fix HTTP 403 (permission)</strong>
            <ol style="margin:0.5rem 0 0 1.25rem">
                <li>Open <a href="https://console.cloud.google.com/apis/library?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">APIs &amp; Services → Library</a> — both APIs <strong>Enabled</strong>.</li>
                <li><a href="https://console.cloud.google.com/billing/linkedaccount?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">Link billing</a> to this project.</li>
                <li><a href="https://console.cloud.google.com/iam-admin/iam?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">IAM</a> → <code><?= h($email) ?></code> → <strong>Service Usage Consumer</strong> or Editor.</li>
            </ol>
            <p style="margin-top:0.75rem;margin-bottom:0"><?= h($permissionHint) ?></p>
        </div>
    <?php endif; ?>

    <div class="toolbar" style="margin-top:1rem">
        <a href="?purge_test=1" class="btn btn--secondary">Purge test spreadsheets</a>
        <a href="?test_create=1" class="btn btn--primary">Run create test (one sheet)</a>
        <a href="settings-production.php#google-sheets" class="btn btn--secondary">Google Sheets settings</a>
        <a href="events.php" class="btn btn--secondary">Events</a>
    </div>

    <p class="form-hint" style="margin-top:1rem">
        <strong>Order:</strong> purge test sheets first if Drive quota failed, then run create test once.
        Log: <code>storage/logs/google-sheets.log</code>.
    </p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
