<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-center.php';
require_once __DIR__ . '/../includes/components/notification-list.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('dashboard');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Invalid request.');
        header('Location: notifications.php');
        exit;
    }

    if (isset($_POST['mark_all_read'])) {
        markAllNotificationsRead($pdo, 'admin');
        setAdminFlash('success', 'All notifications marked as read.');
        header('Location: notifications.php');
        exit;
    }

    if (isset($_POST['clear_all_notifications']) && isAdminSuperUser()) {
        $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if (strtoupper($confirmPhrase) !== 'DELETE') {
            setAdminFlash('error', 'Type DELETE to confirm clearing all notifications.');
            header('Location: notifications.php');
            exit;
        }

        $scope = (string) ($_POST['scope'] ?? 'admin');
        if (!in_array($scope, ['all', 'admin', 'staff'], true)) {
            $scope = 'admin';
        }

        $deleted = clearAllNotifications($pdo, $scope);
        setAdminFlash('success', sprintf('Cleared %d notification(s).', $deleted));
        header('Location: notifications.php');
        exit;
    }
}

$notifications = getAdminNotifications($pdo, 100);
$unreadCount   = countUnreadAdminNotifications($pdo);
$totalCount    = countAllNotifications($pdo, 'admin');

$pageTitle  = 'Notifications';
$activePage = 'notifications';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/notifications.css">

<section class="card">
    <div class="card__header notif-page__header">
        <div>
            <h2 class="card__title">Notifications</h2>
            <p class="card__subtitle">New registrations and alerts for your team.</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <span class="notif-page__badge" aria-label="<?= (int) $unreadCount ?> unread"><?= (int) $unreadCount ?></span>
        <?php endif; ?>
    </div>

    <div class="notif-page__actions" style="margin-bottom:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
        <?php if ($unreadCount > 0): ?>
            <form method="post" action="notifications.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="mark_all_read" value="1">
                <button type="submit" class="btn btn--secondary btn--sm">Mark all as read</button>
            </form>
        <?php endif; ?>
        <?php if (isAdminSuperUser() && $totalCount > 0): ?>
            <form method="post" action="notifications.php" class="notif-page__clear" onsubmit="return confirm('Permanently delete all admin notifications?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="clear_all_notifications" value="1">
                <input type="hidden" name="scope" value="admin">
                <input type="text" name="confirm_phrase" class="input input--sm" placeholder="Type DELETE" required autocomplete="off" style="max-width:8rem">
                <button type="submit" class="btn btn--danger btn--sm">Clear all</button>
            </form>
        <?php endif; ?>
    </div>

    <?php renderNotificationList($notifications, 'No admin notifications yet. New staff registrations will appear here.', true); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
