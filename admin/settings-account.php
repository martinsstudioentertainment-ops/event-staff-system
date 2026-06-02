<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdmin();

$pdo       = getDB();
$adminUser = getAdminUser();
$result    = processSettingsPost($pdo, $adminUser, 'password');
$error     = $result['error'];
$success   = $result['success'];

$pageTitle         = 'Account password';
$activePage        = 'settings-account';
$erpSettingsActive = 'security';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Admin account</h2>
        <p class="card__subtitle">Update your login password.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <dl class="detail-list detail-list--compact">
        <div class="detail-list__row"><dt>Username</dt><dd><?= h($adminUser['username'] ?? '') ?></dd></div>
        <div class="detail-list__row"><dt>Name</dt><dd><?= h($adminUser['name'] ?? '') ?></dd></div>
        <div class="detail-list__row"><dt>Role</dt><dd><?= h(formatAdminRoleLabel(getAdminRole())) ?></dd></div>
    </dl>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="password">
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="current_password">Current password</label>
            <input class="form-input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="form-group">
            <label class="form-label form-label--required" for="new_password">New password</label>
            <input class="form-input" type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="form-group">
            <label class="form-label form-label--required" for="confirm_password">Confirm new password</label>
            <input class="form-input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Update password</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
