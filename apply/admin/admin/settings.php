<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/secure-layout.php';

$message = '';
$error   = '';
$adminInfo = null;

$mainPdo = getMainAdminPdo();
if ($mainPdo instanceof PDO) {
    try {
        $stmt = $mainPdo->prepare('SELECT id, username, full_name, email, role, created_at FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['admin_id']]);
        $adminInfo = $stmt->fetch() ?: null;
    } catch (Exception $e) {
        $error = 'Failed to load operator profile.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_admin') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    try {
        if (!$mainPdo instanceof PDO) {
            throw new Exception('Main admin database is not available.');
        }
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new Exception('All password fields are required.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new Exception('New passwords do not match.');
        }
        if (strlen($newPassword) < 8) {
            throw new Exception('Password must be at least 8 characters.');
        }

        $stmt = $mainPdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['admin_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, (string) ($row['password_hash'] ?? ''))) {
            throw new Exception('Current password is incorrect.');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $mainPdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
        $update->execute(['hash' => $hash, 'id' => (int) $_SESSION['admin_id']]);

        $message = 'Password updated successfully (main ERP account).';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

secure_layout_start('Security settings', 'settings', 'Operator profile and session controls for the Apply secure zone.');

if ($message !== '') {
    echo '<div class="secure-alert secure-alert--success">' . secure_h($message) . '</div>';
}
if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Operator profile</h2>
    <?php if ($adminInfo): ?>
        <div class="secure-dl">
            <div class="secure-dl__row">
                <div class="secure-dl__label">Full name</div>
                <div class="secure-dl__value"><?= secure_h((string) ($adminInfo['full_name'] ?? '')) ?></div>
            </div>
            <div class="secure-dl__row">
                <div class="secure-dl__label">Username</div>
                <div class="secure-dl__value"><?= secure_h((string) ($adminInfo['username'] ?? '')) ?></div>
            </div>
            <div class="secure-dl__row">
                <div class="secure-dl__label">Email</div>
                <div class="secure-dl__value"><?= secure_h((string) ($adminInfo['email'] ?? '')) ?></div>
            </div>
            <div class="secure-dl__row">
                <div class="secure-dl__label">Role</div>
                <div class="secure-dl__value"><?= secure_h(ucfirst((string) ($adminInfo['role'] ?? ''))) ?></div>
            </div>
            <div class="secure-dl__row">
                <div class="secure-dl__label">Account source</div>
                <div class="secure-dl__value">Main ERP (admin_users)</div>
            </div>
            <?php if (!empty($adminInfo['created_at'])): ?>
                <div class="secure-dl__row">
                    <div class="secure-dl__label">Member since</div>
                    <div class="secure-dl__value"><?= secure_h(date('F j, Y', strtotime((string) $adminInfo['created_at']))) ?></div>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p style="margin:0;color:var(--secure-muted);">Signed in as <?= secure_h((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Operator')) ?>.</p>
    <?php endif; ?>
</div>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Change password</h2>
    <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;">
        Updates your main ERP login — applies to both admin.olasentra.com and this secure zone.
    </p>
    <form method="post">
        <input type="hidden" name="action" value="update_admin">
        <div class="secure-field">
            <label class="secure-label" for="current_password">Current password</label>
            <input class="secure-input" type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="secure-grid">
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="new_password">New password</label>
                <input class="secure-input" type="password" id="new_password" name="new_password" required autocomplete="new-password">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="confirm_password">Confirm password</label>
                <input class="secure-input" type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            </div>
        </div>
        <button type="submit" class="secure-btn secure-btn--success">Update password</button>
    </form>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Zone information</h2>
    <div class="secure-dl">
        <div class="secure-dl__row">
            <div class="secure-dl__label">Apply admin</div>
            <div class="secure-dl__value">v1.0 — high security zone</div>
        </div>
        <div class="secure-dl__row">
            <div class="secure-dl__label">PHP</div>
            <div class="secure-dl__value"><?= secure_h(PHP_VERSION) ?></div>
        </div>
        <div class="secure-dl__row">
            <div class="secure-dl__label">Server time</div>
            <div class="secure-dl__value"><?= secure_h(date('Y-m-d H:i:s T')) ?></div>
        </div>
    </div>
</div>

<?php secure_layout_end(); ?>
