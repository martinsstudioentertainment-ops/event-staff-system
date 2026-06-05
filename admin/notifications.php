<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-center.php';
require_once __DIR__ . '/../includes/components/notification-list.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('dashboard');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (verifyCsrf($_POST['csrf_token'] ?? null)) {
        markAllNotificationsRead($pdo, 'admin');
        setAdminFlash('success', 'All notifications marked as read.');
    } else {
        setAdminFlash('error', 'Invalid request.');
    }
    header('Location: notifications.php');
    exit;
}

$notifications = getAdminNotifications($pdo, 100);
$unreadCount   = countUnreadAdminNotifications($pdo);

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

    <?php if ($unreadCount > 0): ?>
        <form method="post" action="notifications.php" style="margin-bottom:0.75rem">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="mark_all_read" value="1">
            <button type="submit" class="btn btn--secondary btn--sm">Mark all as read</button>
        </form>
    <?php endif; ?>

    <?php renderNotificationList($notifications, 'No admin notifications yet. New staff registrations will appear here.', true); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
