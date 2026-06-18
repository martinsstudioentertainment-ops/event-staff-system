<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/platform-schema.php';
require_once __DIR__ . '/../includes/platform/unified-inbox.php';

requireAdminCapability('dashboard');
requirePlatformFeature(getDB(), 'unified_inbox', 'Unified Inbox');

$pdo     = getDB();
$flash   = getAdminFlash();
$limit  = max(10, min(100, (int) ($_GET['limit'] ?? 50)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$filters = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'type'     => (string) ($_GET['type'] ?? 'all'),
    'status'   => (string) ($_GET['status'] ?? 'all'),
    'archived' => !empty($_GET['archived']),
];
$items   = listUnifiedInboxItems($pdo, $filters, $limit, $offset);
$summary = summarizeUnifiedInbox($pdo);

$pageTitle  = 'Unified Inbox';
$activePage = 'unified-inbox';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Unified inbox</h2>
            <p class="card__subtitle"><?= (int) $summary['unread'] ?> unread · <?= (int) $summary['total'] ?> active · <?= (int) $summary['archived'] ?> archived</p>
        </div>
    </div>

    <form method="get" class="inbox-filters">
        <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search…" class="form-input" style="max-width:14rem;">
        <select name="type" class="form-select">
            <?php foreach (['all' => 'All types', 'notification' => 'Notifications', 'message' => 'Messages', 'payroll' => 'Payroll', 'gps' => 'GPS', 'sheets' => 'Sheets'] as $v => $l): ?>
            <option value="<?= h($v) ?>"<?= $filters['type'] === $v ? ' selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select">
            <option value="all"<?= $filters['status'] === 'all' ? ' selected' : '' ?>>All</option>
            <option value="unread"<?= $filters['status'] === 'unread' ? ' selected' : '' ?>>Unread</option>
            <option value="read"<?= $filters['status'] === 'read' ? ' selected' : '' ?>>Read</option>
        </select>
        <label class="form-check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label>
        <button type="submit" class="btn btn--secondary btn--small">Filter</button>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Category</th><th>Title</th><th>Preview</th><th>When</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                [$srcType, $srcIdStr] = explode(':', (string) $item['key'], 2);
                $srcId = (int) $srcIdStr;
                ?>
                <tr class="<?= empty($item['is_read']) ? 'inbox-item--unread' : '' ?>">
                    <td><span class="inbox-cat"><?= h((string) $item['category']) ?></span></td>
                    <td><?= h((string) $item['title']) ?></td>
                    <td><?= h(mb_strimwidth((string) ($item['body'] ?? ''), 0, 80, '…')) ?></td>
                    <td><?= h(formatSystemDateTime((string) ($item['created_at'] ?? ''), $pdo)) ?></td>
                    <td class="table-actions">
                        <?php if ((string) ($item['action_url'] ?? '') !== ''): ?>
                        <a href="<?= h((string) $item['action_url']) ?>" class="btn btn--small btn--secondary">Open</a>
                        <?php endif; ?>
                        <form method="post" action="unified-inbox-action.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="source_type" value="<?= h($srcType) ?>">
                            <input type="hidden" name="source_id" value="<?= $srcId ?>">
                            <input type="hidden" name="action" value="<?= $filters['archived'] ? 'unarchive' : 'archive' ?>">
                            <button type="submit" class="btn btn--small btn--ghost"><?= $filters['archived'] ? 'Restore' : 'Archive' ?></button>
                        </form>
                        <?php if (empty($item['is_read'])): ?>
                        <form method="post" action="unified-inbox-action.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="source_type" value="<?= h($srcType) ?>">
                            <input type="hidden" name="source_id" value="<?= $srcId ?>">
                            <input type="hidden" name="action" value="read">
                            <button type="submit" class="btn btn--small btn--ghost">Mark read</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="5">No items match your filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="toolbar" style="margin-top:1rem;">
        <?php if ($offset > 0): ?>
        <a href="?<?= h(http_build_query(array_merge($filters, ['limit' => $limit, 'offset' => max(0, $offset - $limit)]))) ?>" class="btn btn--small btn--secondary">← Newer</a>
        <?php endif; ?>
        <?php if (count($items) >= $limit): ?>
        <a href="?<?= h(http_build_query(array_merge($filters, ['limit' => $limit, 'offset' => $offset + $limit]))) ?>" class="btn btn--small btn--secondary">Older →</a>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
