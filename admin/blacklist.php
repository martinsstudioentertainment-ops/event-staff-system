<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-blacklist.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('staff');

$pdo       = getDB();
$allRows   = getActiveBlacklist($pdo);
$total     = count($allRows);
$page      = adminListPage();
$rows      = array_slice($allRows, adminListOffset($page), adminListPerPage());
$flash = getAdminFlash();

$pageTitle  = 'Staff Blacklist';
$activePage = 'blacklist';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Blacklist</h2>
            <p class="card__subtitle">
                Staff are added automatically after <?= (int) STAFF_NO_SHOW_BLACKLIST_THRESHOLD ?> consecutive approved no-shows.
                Remove someone to allow registration again.
            </p>
        </div>
        <a href="staff.php" class="btn btn--secondary">← Staff list</a>
    </div>

    <?php if ($rows === []): ?>
        <p class="form-hint">No one is currently blacklisted.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Reason</th>
                        <th>No-shows</th>
                        <th>Since</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')));
                        $latest = getLatestRegistrationByEmail($pdo, (string) $row['email']);
                        ?>
                        <tr>
                            <td><?= h($name !== '' ? $name : '—') ?></td>
                            <td><?= h((string) $row['email']) ?></td>
                            <td><?= h((string) ($row['mobile'] ?? '—')) ?></td>
                            <td>
                                <?= h((string) $row['reason']) ?>
                                <?php if ((int) ($row['auto_blacklisted'] ?? 0) === 1): ?>
                                    <span class="badge badge--pending">Auto</span>
                                <?php else: ?>
                                    <span class="badge badge--approved">Manual</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($row['consecutive_no_shows'] ?? 0) ?></td>
                            <td><?= h(date('d.m.Y H:i', strtotime((string) $row['blacklisted_at']))) ?></td>
                            <td class="table-actions">
                                <?php if ($latest): ?>
                                    <a href="view-staff.php?id=<?= (int) $latest['id'] ?>" class="btn btn--small btn--secondary">View</a>
                                <?php endif; ?>
                                <form method="post" action="blacklist-action.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                                    <button type="submit" class="btn btn--small btn--primary">Remove from blacklist</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderAdminPagination($page, $total, 'blacklist.php'); ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
