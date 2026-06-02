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

$probe = null;
if (($token ?? '') !== '' && is_array($sa) && isset($_GET['test_create'])) {
    $probe = googleSheetsProbeCreate($sa, 'Event Staff API probe ' . date('Y-m-d H:i'));
    $rows[] = [
        'Drive API create probe',
        ($probe['drive']['code'] ?? 0) >= 200 && ($probe['drive']['code'] ?? 0) < 300 ? 'pass' : 'fail',
        'HTTP ' . (int) ($probe['drive']['code'] ?? 0) . ' — ' . h((string) ($probe['drive']['summary'] ?? '')),
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
    $createDetail = $createOk
        ? 'Created: ' . ($test['url'] ?? '')
        : h(getLastGoogleSheetsApiError() ?: 'Unknown error');
    if (!$createOk) {
        $permissionHint = googleSheetsCreatePermissionHint(getLastGoogleSheetsApiError(), $projectId !== '' ? $projectId : null);
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

    <?php if ($permissionHint !== ''): ?>
        <div class="alert alert--warning" style="margin-top:1rem">
            <strong>How to fix HTTP 403</strong>
            <ol style="margin:0.5rem 0 0 1.25rem">
                <li>Open <a href="https://console.cloud.google.com/apis/library?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">APIs &amp; Services → Library</a> with project <code><?= h($projectId) ?></code> selected — confirm both APIs show <strong>Enabled</strong>.</li>
                <li><a href="https://console.cloud.google.com/billing/linkedaccount?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">Link billing</a> to this project (required on many new projects even for free API use).</li>
                <li><a href="https://console.cloud.google.com/iam-admin/iam?project=<?= h(rawurlencode($projectId)) ?>" target="_blank" rel="noopener">IAM</a> → find <code><?= h($email) ?></code> → add role <strong>Service Usage Consumer</strong> (or <strong>Editor</strong>).</li>
                <li>Keys → create a <strong>new</strong> JSON key → re-upload in Settings → Google Sheets.</li>
                <li>Wait 10 minutes, then run the create test again.</li>
            </ol>
            <p style="margin-top:0.75rem;margin-bottom:0"><?= h($permissionHint) ?></p>
        </div>
    <?php endif; ?>

    <div class="toolbar" style="margin-top:1rem">
        <a href="?test_create=1" class="btn btn--primary">Run create test (one sheet)</a>
        <a href="settings-production.php#google-sheets" class="btn btn--secondary">Google Sheets settings</a>
        <a href="events.php" class="btn btn--secondary">Events</a>
    </div>

    <p class="form-hint" style="margin-top:1rem">
        Server log: <code>storage/logs/google-sheets.log</code> (cPanel File Manager → <code>public_html/storage/logs/</code>).
        Deploy latest <code>includes/google-sheets-sync.php</code> and this page before testing.
    </p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
