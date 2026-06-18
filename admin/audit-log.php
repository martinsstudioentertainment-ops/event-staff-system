<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('audit');

$pdo     = getDB();
$page    = adminListPage();
$limit   = adminListPerPage();
$offset  = adminListOffset($page);
$total   = countAuditLogEntries($pdo);
$entries = getAuditLogEntries($pdo, $limit, $offset);

$pageTitle  = 'Activity logs';
$activePage = 'audit-log';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Activity logs</h2>
        <p class="card__subtitle">Admin actions — logins, approvals, exports, check-ins, and site changes.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entries === []): ?>
                    <tr><td colspan="6" class="data-table__empty">No audit entries yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?= h(formatSystemDateTime((string) $entry['created_at'], $pdo)) ?></td>
                            <td><?= h($entry['admin_username'] ?: '—') ?></td>
                            <td><?= h(formatAuditActionLabel($entry['action'])) ?></td>
                            <td>
                                <?php if (!empty($entry['target_type'])): ?>
                                    <?= h($entry['target_type']) ?><?= $entry['target_id'] ? ' #' . (int) $entry['target_id'] : '' ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) ($entry['details'] ?? '')) ?></td>
                            <td><?= h((string) ($entry['ip_address'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderAdminPagination($page, $total, 'audit-log.php'); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
