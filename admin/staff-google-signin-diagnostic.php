<?php
/**
 * Staff Gmail OAuth diagnostic (admin only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';
require_once __DIR__ . '/../includes/staff-google-oauth.php';

requireAdminCapability('settings');

$pdo = getDB();
$rows = [];

$clientId = trim(getSetting($pdo, 'google_oauth_client_id', ''));
$secretOk = isGoogleOAuthClientSecretConfigured($pdo);
$redirect = staffGoogleOAuthRedirectUri($pdo);
$origin   = rtrim(getRegistrationSiteUrl($pdo), '/');
$staffApp = $origin . '/staff-app.php';

$rows[] = ['OAuth Client ID saved', $clientId !== '' ? 'pass' : 'fail', $clientId !== '' ? 'Set' : 'Add in Settings → Google Sheets'];
$rows[] = ['OAuth Client secret saved', $secretOk ? 'pass' : 'fail', $secretOk ? 'Set' : 'Paste secret and save Google Sheets settings'];
$rows[] = ['Staff redirect URI', 'pass', h($redirect)];
$rows[] = ['Authorized JavaScript origin (add in Google Cloud)', 'pass', h($origin)];
$rows[] = ['Staff app URL', 'pass', '<a href="' . h($staffApp) . '" target="_blank" rel="noopener">' . h($staffApp) . '</a>'];
$rows[] = ['Google sign-in enabled', isStaffGoogleSigninEnabled($pdo) ? 'pass' : 'warn', isStaffGoogleSigninEnabled($pdo) ? 'Yes' : 'No — enable in Settings'];
$rows[] = ['Google sign-in required', isStaffGoogleSigninRequired($pdo) ? 'pass' : 'warn', isStaffGoogleSigninRequired($pdo) ? 'Yes (Gmail only on staff app)' : 'No (email + PPS still available)'];
$rows[] = ['PHP cURL', function_exists('curl_init') ? 'pass' : 'fail', function_exists('curl_init') ? 'OK' : 'Enable curl on hosting'];

$ch = curl_init('https://accounts.google.com/.well-known/openid-configuration');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true]);
curl_exec($ch);
$googleHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$rows[] = ['Reach Google OAuth', ($googleHttp >= 200 && $googleHttp < 400) ? 'pass' : 'fail', 'HTTP ' . $googleHttp];

$pageTitle = 'Staff Gmail sign-in diagnostic';
include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h1 class="card__title">Staff Gmail sign-in diagnostic</h1>
        <p class="card__subtitle">Use this before you enable Gmail for staff tonight. Admin Drive OAuth tokens are separate — staff sign-in does not overwrite them.</p>
    </div>

    <table class="data-table" style="width:100%;margin-bottom:1.5rem;">
        <thead>
            <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as [$label, $status, $detail]): ?>
                <tr>
                    <td><?= h($label) ?></td>
                    <td>
                        <?php if ($status === 'pass'): ?>
                            <span style="color:#15803d;font-weight:600;">Pass</span>
                        <?php elseif ($status === 'warn'): ?>
                            <span style="color:#b45309;font-weight:600;">Warn</span>
                        <?php else: ?>
                            <span style="color:#b91c1c;font-weight:600;">Fail</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $detail ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="font-size:1.1rem;margin:0 0 0.75rem;">Google Cloud Console (one-time)</h2>
    <ol style="margin:0 0 1.25rem;line-height:1.6;padding-left:1.25rem;">
        <li>Open your <strong>Web client</strong> (same as Google Sheets).</li>
        <li><strong>Authorized redirect URIs</strong> → add: <code><?= h($redirect) ?></code></li>
        <li><strong>Authorized JavaScript origins</strong> → add: <code><?= h($origin) ?></code></li>
        <li>Save in Google Cloud, wait ~1 minute, then test on your phone.</li>
    </ol>

    <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem;">
        <a href="settings-production.php#staff-google-signin" class="btn btn--primary">Settings → enable Gmail</a>
        <a href="<?= h($staffApp) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Open staff app</a>
        <a href="settings-production.php" class="btn btn--secondary">Back to settings</a>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
