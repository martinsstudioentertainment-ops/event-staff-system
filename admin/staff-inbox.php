<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/staff-messages.php';

require_once __DIR__ . '/../includes/staff-broadcast.php';

require_once __DIR__ . '/../includes/admin-capabilities.php';

require_once __DIR__ . '/../includes/audit-log.php';

require_once __DIR__ . '/../includes/notification-center.php';

require_once __DIR__ . '/../includes/date-format.php';



requireAdminCapability('staff');



$pdo       = getDB();

$adminUser = getAdminUser();

$threads   = listStaffInboxThreads($pdo, 80);

$unread    = countUnreadStaffMessagesForAdmin($pdo);

$messageCount      = countAllStaffMessages($pdo);

$notificationCount = countAllNotifications($pdo, 'all');

$flash     = getAdminFlash();

$broadcast = getActiveStaffFlashBroadcast($pdo);



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');



    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {

        setAdminFlash('error', 'Invalid request. Refresh and try again.');

        header('Location: staff-inbox.php');

        exit;

    }



    if ($action === 'broadcast') {

        $result = publishStaffFlashBroadcast($pdo, (string) ($_POST['message'] ?? ''), (int) ($adminUser['id'] ?? 0));

        if (!empty($result['ok'])) {

            logAdminAudit($pdo, 'staff_flash_broadcast', 'staff', null, 'Published flash announcement');

            setAdminFlash('success', (string) ($result['message'] ?? 'Announcement published.'));

        } else {

            setAdminFlash('error', (string) ($result['message'] ?? 'Could not publish announcement.'));

        }

        header('Location: staff-inbox.php');

        exit;

    }



    if ($action === 'clear_broadcast') {

        clearStaffFlashBroadcast($pdo);

        logAdminAudit($pdo, 'staff_flash_clear', 'staff', null, 'Cleared flash announcement');

        setAdminFlash('success', 'Flash announcement removed from staff pages.');

        header('Location: staff-inbox.php');

        exit;

    }

    if ($action === 'delete_all_messages') {
        if (!isAdminSuperUser()) {
            setAdminFlash('error', 'Only the main admin account can delete all messages.');
            header('Location: staff-inbox.php');
            exit;
        }

        $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if (strtoupper($confirmPhrase) !== 'DELETE') {
            setAdminFlash('error', 'Type DELETE to confirm permanent deletion of all messages.');
            header('Location: staff-inbox.php');
            exit;
        }

        $result = deleteAllStaffMessages($pdo);
        if (!empty($result['ok'])) {
            setAdminFlash('success', (string) ($result['message'] ?? 'All messages deleted.'));
        } else {
            setAdminFlash('error', (string) ($result['message'] ?? 'Could not delete messages.'));
        }

        header('Location: staff-inbox.php');
        exit;
    }

    if ($action === 'clear_all_notifications') {
        if (!isAdminSuperUser()) {
            setAdminFlash('error', 'Only the main admin account can clear all notifications.');
            header('Location: staff-inbox.php');
            exit;
        }

        $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
        if (strtoupper($confirmPhrase) !== 'DELETE') {
            setAdminFlash('error', 'Type DELETE to confirm clearing all notifications.');
            header('Location: staff-inbox.php');
            exit;
        }

        $scope = (string) ($_POST['scope'] ?? 'all');
        if (!in_array($scope, ['all', 'admin', 'staff'], true)) {
            $scope = 'all';
        }

        $deleted = clearAllNotifications($pdo, $scope);
        $label   = match ($scope) {
            'admin' => 'admin',
            'staff' => 'staff',
            default => 'all',
        };
        setAdminFlash('success', sprintf('Cleared %d %s notification(s).', $deleted, $label));
        header('Location: staff-inbox.php');
        exit;
    }

}



$pageTitle  = 'Staff messages';

$activePage = 'staff-inbox';



include __DIR__ . '/../includes/admin/layout-top.php';

?>



<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/messages.css">



<?php if ($flash): ?>

    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>

<?php endif; ?>



<section class="card msg-admin-tools">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title">Email all staff (rich HTML)</h2>

            <p class="card__subtitle">Compose your own subject and HTML message — sent as email and saved in each staff member’s inbox thread. History appears under <strong>Recent campaigns</strong>.</p>

        </div>

        <div class="toolbar" style="gap:0.5rem;flex-wrap:wrap">
            <a href="communication-hub.php" class="btn btn--primary">Open Communication Hub</a>
            <a href="communication-hub.php#bulk-inbox" class="btn btn--secondary">Bulk inbox to all staff</a>
        </div>

    </div>

</section>



<section class="card msg-admin-tools">

    <div class="card__header">

        <h2 class="card__title">Flash announcement (all staff)</h2>

        <p class="card__subtitle">Shows a banner at the top of the staff app, registration form, and status pages until dismissed or cleared.</p>

    </div>



    <?php if ($broadcast !== null): ?>

        <div class="msg-broadcast-live">

            <span class="msg-broadcast-live__label">Live now</span>

            <p class="msg-broadcast-live__text"><?= nl2br(h($broadcast['message'])) ?></p>

            <?php if ($broadcast['updated_at'] !== ''): ?>

                <p class="form-hint">Published <?= h(formatSystemDateTime($broadcast['updated_at'], $pdo)) ?></p>

            <?php endif; ?>

        </div>

    <?php endif; ?>



    <form method="post" class="msg-broadcast-form">

        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <input type="hidden" name="action" value="broadcast">

        <div class="form-group">

            <label class="form-label form-label--required" for="broadcast-message">Message for everyone</label>

            <textarea class="form-input" id="broadcast-message" name="message" rows="3" maxlength="2000" placeholder="e.g. Check-in opens at 08:00 — bring your PSA card." required><?= h($broadcast['message'] ?? '') ?></textarea>

        </div>

        <div class="toolbar" style="gap:0.5rem;flex-wrap:wrap">

            <button type="submit" class="btn btn--primary">Publish flash message</button>

        </div>

    </form>

    <?php if ($broadcast !== null): ?>
        <form method="post" class="msg-broadcast-clear" onsubmit="return confirm('Remove the announcement from all staff pages?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="clear_broadcast">
            <button type="submit" class="btn btn--secondary">Clear announcement</button>
        </form>
    <?php endif; ?>

</section>



<section class="card msg-admin-tools" id="staff-chat-picker" data-search-url="<?= h($assetBase) ?>api/admin-staff-search.php">

    <div class="card__header">

        <h2 class="card__title">Message a staff member</h2>

        <p class="card__subtitle">Search by name or email to open a chat — you can start a conversation even if they have not messaged you first.</p>

    </div>

    <div class="msg-picker-wrap">

        <label class="form-label" for="staff-chat-picker-q">Find staff</label>

        <input type="search" id="staff-chat-picker-q" class="form-input" placeholder="Type name or email…" autocomplete="off">

        <ul id="staff-chat-picker-results" class="msg-picker-results" hidden></ul>

    </div>

</section>



<section class="card">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title">Inbox</h2>

            <p class="card__subtitle">One conversation thread per staff email — all sent and received messages stay together.</p>

        </div>

        <?php if ($unread > 0): ?>

            <span class="msg-inbox-badge"><?= (int) $unread ?></span>

        <?php endif; ?>

    </div>



    <?php if ($threads === []): ?>

        <p class="data-table__empty" style="padding:1.5rem 0">No messages yet. Staff can message you from the staff app, or search above to start a chat.</p>

    <?php else: ?>

        <ul class="msg-inbox-list">

            <?php foreach ($threads as $thread): ?>

                <?php

                $staffId      = (int) ($thread['staff_id'] ?? 0);
                $threadEmail  = normalizeStaffMessageEmail((string) ($thread['staff_email'] ?? ''));
                $name         = getStaffDisplayNameFromRow($thread);

                $preview      = trim((string) ($thread['last_body'] ?? ''));
                $lastSubject  = trim((string) ($thread['last_subject'] ?? ''));

                $threadUnread = (int) ($thread['unread_count'] ?? 0);

                ?>

                <li class="msg-inbox-item">

                    <a class="msg-inbox-link" href="staff-inbox-thread.php?email=<?= rawurlencode($threadEmail) ?><?= $staffId > 0 ? '&amp;staff_id=' . $staffId : '' ?>">

                        <span class="msg-inbox-link__name">

                            <?= h($name) ?>

                            <?php if ($threadUnread > 0): ?>

                                <span class="msg-inbox-badge" style="margin-left:0.35rem;vertical-align:middle"><?= $threadUnread ?></span>

                            <?php endif; ?>

                        </span>

                        <span class="msg-inbox-link__time"><?= h(formatSystemDateTime((string) ($thread['last_at'] ?? ''), $pdo)) ?></span>
                        <?php if ($lastSubject !== ''): ?>
                            <span class="msg-inbox-link__subject"><?= h($lastSubject) ?></span>
                        <?php endif; ?>
                        <span class="msg-inbox-link__preview"><?= h($preview !== '' ? $preview : '(no text)') ?></span>

                    </a>

                </li>

            <?php endforeach; ?>

        </ul>

    <?php endif; ?>

</section>

<?php if (isAdminSuperUser()): ?>
<section class="card msg-admin-tools msg-admin-danger">
    <div class="card__header">
        <h2 class="card__title">Admin maintenance</h2>
        <p class="card__subtitle">Permanent actions — cannot be undone. Type <strong>DELETE</strong> to confirm.</p>
    </div>

    <div class="msg-admin-danger__grid">
        <form method="post" class="msg-admin-danger__form" onsubmit="return confirm('Delete every staff message thread permanently?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_all_messages">
            <h3 class="msg-admin-danger__label">Delete all messages</h3>
            <p class="form-hint">Removes <?= (int) $messageCount ?> message(s) from every inbox thread.</p>
            <label class="form-label" for="confirm-delete-messages">Confirm</label>
            <input class="form-input" id="confirm-delete-messages" name="confirm_phrase" placeholder="Type DELETE" required autocomplete="off">
            <button type="submit" class="btn btn--danger">Delete all messages</button>
        </form>

        <form method="post" class="msg-admin-danger__form" onsubmit="return confirm('Clear all notifications permanently?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="clear_all_notifications">
            <h3 class="msg-admin-danger__label">Clear notifications</h3>
            <p class="form-hint">Removes <?= (int) $notificationCount ?> notification(s) for admin and staff.</p>
            <label class="form-label" for="notif-scope">Scope</label>
            <select class="form-input" id="notif-scope" name="scope">
                <option value="all">All (admin + staff)</option>
                <option value="admin">Admin only</option>
                <option value="staff">Staff only</option>
            </select>
            <label class="form-label" for="confirm-clear-notifications">Confirm</label>
            <input class="form-input" id="confirm-clear-notifications" name="confirm_phrase" placeholder="Type DELETE" required autocomplete="off">
            <button type="submit" class="btn btn--danger">Clear notifications</button>
        </form>
    </div>
</section>
<?php endif; ?>

<script src="<?= h($assetBase) ?>assets/js/admin-staff-inbox.js"></script>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

