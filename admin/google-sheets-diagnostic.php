<?php
/**
 * Google Sheets API connectivity test (admin only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/settings-repository.php';

requireAdminCapability('settings');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['diagnostic_action'] ?? '') === 'save_folder') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $flash = 'Invalid request. Refresh the page and try again.';
    } else {
        $folderRaw = trim((string) ($_POST['google_sheets_drive_folder_id'] ?? ''));
        $folderId  = parseGoogleDriveFolderId($folderRaw);
        if ($folderId === '') {
            $flash = 'Enter a valid Drive folder URL or ID (the part after /folders/ in Google Drive).';
        } else {
            setSetting($pdo, 'google_sheets_drive_folder_id', $folderId);
            clearSettingsCache();
            $flash = 'Drive folder ID saved: ' . $folderId;
        }
    }
}

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

$folderId = getGoogleSheetsDriveParentFolderId();
$rows[] = [
    'Drive folder for new sheets',
    $folderId !== '' ? 'pass' : 'fail',
    $folderId !== ''
        ? 'Folder ID ' . h($folderId) . ' (sheets are created inside your shared folder)'
        : 'Not set — Settings → Google Sheets → Drive folder ID (required)',
];

$ownedCount = 0;
if (($token ?? '') !== '' && is_array($sa)) {
    $owned = googleDriveListOwnedSpreadsheets($sa, 1000);
    $ownedCount = count($owned);
    $rows[] = [
        'Service account Drive spreadsheets',
        $ownedCount < 50 ? 'pass' : 'warn',
        (string) $ownedCount . ' owned by the service account',
    ];
    $quota = googleDriveStorageQuota($sa);
    if ($quota !== null) {
        $limit = (int) ($quota['limit'] ?? 0);
        $usage = (int) ($quota['usage'] ?? 0);
        $detail = $limit > 0
            ? round($usage / $limit * 100, 1) . '% used (' . number_format($usage) . ' / ' . number_format($limit) . ' bytes)'
            : 'Usage ' . number_format($usage) . ' bytes in Drive';
        $full = $limit > 0 && $usage >= $limit;
        if ($full || $ownedCount >= 50) {
            $driveQuotaIssue = true;
        }
        $rows[] = [
            'Drive storage quota',
            $full ? 'fail' : 'pass',
            $detail,
        ];
    }
}

if (($token ?? '') !== '' && is_array($sa) && isset($_GET['purge_test'])) {
    $purge = googleDrivePurgeTestSpreadsheets($sa);
    $flash = $purge['message'];
    $owned = googleDriveListOwnedSpreadsheets($sa, 1000);
    $ownedCount = count($owned);
}

if (($token ?? '') !== '' && is_array($sa) && isset($_GET['purge_all']) && ($_GET['confirm'] ?? '') === 'yes') {
    $purge = googleDrivePurgeAllOwnedSpreadsheets($sa);
    $flash = $purge['message'] . ' Re-create sheets on Events if needed.';
    $owned = googleDriveListOwnedSpreadsheets($sa, 1000);
    $ownedCount = count($owned);
    $driveQuotaIssue = false;
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
    } else {
        $driveQuotaIssue = false;
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
                <li>Click <strong>Purge test spreadsheets</strong> first.</li>
                <li>If still full, click <strong>Delete ALL service account sheets</strong> (removes every sheet the robot owns — you can run <strong>Create N Google Sheet(s)</strong> on Events again later).</li>
                <li>Then <strong>Run create test</strong> once only — do not click it many times.</li>
            </ol>
            <p style="margin:0.5rem 0 0"><strong>Do not click “Run create test” again until after purge.</strong> Each click tries to create more files.</p>
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

    <?php if ($folderId === ''): ?>
        <form method="post" class="erp-settings-form" style="margin-top:1rem;padding:1rem;background:var(--surface-elevated, #f5f5f5);border-radius:8px">
            <h3 style="margin:0 0 0.75rem;font-size:1rem">Save Drive folder ID here</h3>
            <p class="form-hint" style="margin:0 0 0.75rem">If Settings clears the box after Save, paste your folder ID below and click <strong>Save folder ID</strong>.</p>
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="diagnostic_action" value="save_folder">
            <div class="form-group form-group--full">
                <input class="form-input" type="text" name="google_sheets_drive_folder_id" value="<?= h(getSetting($pdo, 'google_sheets_drive_folder_id', '')) ?>" placeholder="1yMRBJoz4nA7MVIopBbiv9qcpeB1zBjqW or full Drive folder URL">
            </div>
            <button type="submit" class="btn btn--primary">Save folder ID</button>
        </form>
    <?php endif; ?>

    <div class="toolbar" style="margin-top:1rem;flex-wrap:wrap;gap:0.5rem">
        <a href="?purge_test=1" class="btn btn--secondary">Purge test spreadsheets</a>
        <a href="?purge_all=1&amp;confirm=yes" class="btn btn--secondary" onclick="return confirm('Delete EVERY spreadsheet owned by the service account? Event sheet links in the database may break until you create sheets again.');">Delete ALL service account sheets</a>
        <a href="?test_create=1" class="btn btn--primary">Run create test (one sheet)</a>
        <a href="settings-production.php#google-sheets" class="btn btn--secondary">Google Sheets settings</a>
        <a href="events.php" class="btn btn--secondary">Events</a>
    </div>

    <p class="form-hint" style="margin-top:1rem">
        <strong>Order:</strong> 1) Purge test → 2) If still quota full, Delete ALL → 3) Create test once.
        Re-upload <code>includes/google-sheets-sync.php</code> and this page after git pull if buttons are missing.
    </p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
