<?php
/**
 * One-page easy setup — Gmail sign-in + staff app.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';
require_once __DIR__ . '/../includes/staff-google-oauth.php';
require_once __DIR__ . '/../includes/staff-profile-gate.php';
require_once __DIR__ . '/../includes/pwa-install-analytics.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';

requireAdminCapability('settings');

$pdo       = getDB();
$adminUser = getAdminUser();
$flash     = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'staff_google_signin_go_live') {
        $result = processSettingsPost($pdo, $adminUser, 'staff_google_signin_go_live');
        $flash  = $result['success'] ?: '';
        $error  = $result['error'] ?: '';
    } elseif ($action === 'deactivate_profile_gate') {
        deactivateStaffProfileUpdateRequired($pdo);
        $flash = 'Profile gate turned off — staff go straight to shifts after Google sign-in.';
    }
}

$redirect = staffGoogleOAuthRedirectUri($pdo);
$origin   = rtrim(getRegistrationSiteUrl($pdo), '/');
$staffApp = $origin . '/staff-app.php';
$adminRedirect = googleDriveOAuthRedirectUri($pdo);
$adminOrigin   = googleDriveOAuthJavaScriptOrigin($pdo);
$pwa           = getPwaInstallDashboardMetrics($pdo, 'staff');
$gmailOn       = isStaffGoogleSigninEnabled($pdo);
$gmailRequired = isStaffGoogleSigninRequired($pdo);
$profileGate   = isStaffProfileUpdateRequired($pdo);
$clientOk      = isStaffGoogleSigninConfigured($pdo);

$pageTitle = 'Staff app — easy setup';
include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h1 class="card__title">Staff app — easy setup</h1>
        <p class="card__subtitle">Do these 4 steps once. Staff then only tap <strong>Continue with Google</strong> on their phone.</p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <ol style="margin:0;padding-left:1.35rem;line-height:1.75;font-size:1rem;">
        <li style="margin-bottom:1.25rem;">
            <strong>Google Cloud</strong> → <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Credentials</a> → your <strong>Web client</strong><br>
            Add <strong>JavaScript origins</strong>:<br>
            <code><?= h($adminOrigin) ?></code><br>
            <code><?= h($origin) ?></code><br>
            Add <strong>Redirect URIs</strong>:<br>
            <code><?= h($adminRedirect) ?></code><br>
            <code><?= h($redirect) ?></code>
        </li>
        <li style="margin-bottom:1.25rem;">
            <strong>Admin settings</strong> — paste OAuth Client ID + secret under
            <a href="settings-production.php#google-sheets">Google Sheets settings</a> if not saved yet.
            <?php if ($clientOk): ?>
                <span class="badge badge--approved">Client OK</span>
            <?php else: ?>
                <span class="badge badge--pending">Client missing</span>
            <?php endif; ?>
        </li>
        <li style="margin-bottom:1.25rem;">
            <strong>Turn on Gmail for staff</strong>
            <?php if ($gmailOn && $gmailRequired): ?>
                <span class="badge badge--approved">On &amp; required</span>
            <?php elseif ($gmailOn): ?>
                <span class="badge badge--pending">On (optional)</span>
            <?php else: ?>
                <form method="post" style="display:inline;margin-left:0.5rem;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="staff_google_signin_go_live">
                    <button type="submit" class="btn btn--primary">Enable Gmail sign-in now</button>
                </form>
            <?php endif; ?>
        </li>
        <li>
            <strong>Test on your phone</strong> —
            <a href="<?= h($staffApp) ?>" target="_blank" rel="noopener">Open staff app</a> → Continue with Google → Install on home screen.
        </li>
    </ol>

    <div style="margin-top:1.5rem;padding:1rem;background:var(--surface-elevated,#f8fafc);border-radius:8px;">
        <h2 style="margin:0 0 0.5rem;font-size:1rem;">Status</h2>
        <ul style="margin:0;padding-left:1.25rem;line-height:1.7;">
            <li>Gmail sign-in: <?= $gmailOn ? 'On' : 'Off' ?><?= $gmailRequired ? ' (required)' : '' ?></li>
            <li>Profile gate (blocks shifts): <?= $profileGate ? 'On — consider turning off' : 'Off (easy mode)' ?></li>
            <li>Staff app installs: <?= (int) ($pwa['installed_total'] ?? 0) ?> —
                <a href="dashboard.php#dash-pwa-analytics">see dashboard</a></li>
        </ul>
        <?php if ($profileGate): ?>
        <form method="post" style="margin-top:0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="deactivate_profile_gate">
            <button type="submit" class="btn btn--secondary" onclick="return confirm('Turn off profile gate? Staff see shifts immediately after Google sign-in.');">
                Turn off profile gate (recommended)
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div class="form-actions form-actions--end" style="margin-top:1.25rem;flex-wrap:wrap;gap:0.5rem;">
        <a href="<?= h($staffApp) ?>" class="btn btn--primary" target="_blank" rel="noopener">Open staff app</a>
        <a href="staff-google-signin-diagnostic.php" class="btn btn--secondary">Diagnostic</a>
        <a href="dashboard.php#dash-pwa-analytics" class="btn btn--secondary">Install counts</a>
        <a href="settings-production.php#staff-google-signin" class="btn btn--secondary">Settings</a>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
