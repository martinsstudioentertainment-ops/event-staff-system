<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('settings');

$pdo       = getDB();
$adminUser = getAdminUser();
$result    = processSettingsPost($pdo, $adminUser, 'security');
$error     = $result['error'];
$success   = $result['success'];
$settings  = $result['settings'];

$pageTitle         = 'Security';
$activePage        = 'settings-security';
$erpSettingsActive = 'security';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card erp-card">
    <div class="card__header">
        <h2 class="card__title">Security</h2>
        <p class="card__subtitle">Sign-in rules, maps API, admin users, and your account password.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="security">

        <h4 class="form-section-title form-group--full">Staff sign-in</h4>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="signin_require_pps_last4" value="1"<?= ($settings['signin_require_pps_last4'] ?? '1') === '1' ? ' checked' : '' ?>>
                Require last 4 characters of PPS on email / venue sign-in
            </label>
            <p class="form-hint">Turn off for email-only sign-in. Personal QR check-in is unchanged.</p>
        </div>

        <h4 class="form-section-title form-group--full">Integrations</h4>
        <div class="form-group form-group--full">
            <label class="form-label" for="google_maps_api_key">Google Maps API key</label>
            <input class="form-input" type="text" id="google_maps_api_key" name="google_maps_api_key" value="<?= h($settings['google_maps_api_key'] ?? '') ?>" autocomplete="off">
            <p class="form-hint">Used for venue maps on public sign-in pages.</p>
        </div>

        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save security settings</button>
        </div>
    </form>

    <h4 class="form-section-title form-group--full" style="margin-top:1.5rem;">Admin access</h4>
    <div class="toolbar">
        <?php if (adminCan('users')): ?>
            <a href="users.php" class="btn btn--secondary">Team users</a>
        <?php endif; ?>
        <a href="settings-account.php" class="btn btn--secondary">Change my password</a>
        <?php if (adminCan('audit')): ?>
            <a href="geo-audits.php" class="btn btn--secondary">Login geo audits</a>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
