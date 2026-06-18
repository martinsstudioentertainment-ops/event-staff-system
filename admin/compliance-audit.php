<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('audit');

$pdo = getDB();

$filters = [
    'category' => trim((string) ($_GET['category'] ?? '')),
    'action'   => trim((string) ($_GET['action'] ?? '')),
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'from'     => trim((string) ($_GET['from'] ?? '')),
    'to'       => trim((string) ($_GET['to'] ?? '')),
];

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = getFilteredAuditLogEntries($pdo, $filters, 5000, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compliance-audit-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Admin', 'Action', 'Target type', 'Target ID', 'Details', 'IP']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['created_at'] ?? ''),
            (string) ($row['admin_username'] ?? ''),
            formatAuditActionLabel((string) ($row['action'] ?? '')),
            (string) ($row['target_type'] ?? ''),
            (string) ($row['target_id'] ?? ''),
            (string) ($row['details'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$page    = adminListPage();
$limit   = adminListPerPage();
$offset  = adminListOffset($page);
$total   = countFilteredAuditLogEntries($pdo, $filters);
$entries = getFilteredAuditLogEntries($pdo, $filters, $limit, $offset);

$queryBase = http_build_query(array_filter($filters, static fn ($v): bool => $v !== ''));

$pageTitle  = 'Audit & Compliance Logs';
$activePage = 'compliance-audit';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Audit & Compliance Logs</h1>
        <p class="wf-hero__subtitle">Approvals, attendance changes, GPS overrides, payroll, invoices, and user actions — search, filter, export.</p>
    </div>
    <a class="btn btn--primary" href="compliance-audit.php?export=csv<?= $queryBase !== '' ? '&' . h($queryBase) : '' ?>">Export CSV</a>
</div>

<section class="card erp-card">
    <form method="get" class="wf-filters">
        <div>
            <label for="category">Category</label>
            <select id="category" name="category" class="input">
                <option value="">All</option>
                <?php foreach (['approvals' => 'Approvals', 'attendance' => 'Attendance', 'gps' => 'GPS', 'payroll' => 'Payroll', 'invoices' => 'Invoices', 'users' => 'User actions'] as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $filters['category'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="q">Search</label><input id="q" name="q" value="<?= h($filters['q']) ?>" class="input" placeholder="Admin, details…"></div>
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($filters['from']) ?>" class="input"></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($filters['to']) ?>" class="input"></div>
        <div style="align-self:end;"><button type="submit" class="btn btn--primary">Filter</button></div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php if ($entries === []): ?>
                <tr><td colspan="6" class="data-table__empty">No audit entries match your filters.</td></tr>
            <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= h(formatSystemDateTime((string) $entry['created_at'], $pdo)) ?></td>
                        <td><?= h($entry['admin_username'] ?: '—') ?></td>
                        <td><?= h(formatAuditActionLabel($entry['action'])) ?></td>
                        <td>
                            <?php if (!empty($entry['target_type'])): ?>
                                <?= h($entry['target_type']) ?><?= $entry['target_id'] ? ' #' . (int) $entry['target_id'] : '' ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= h((string) ($entry['details'] ?? '')) ?></td>
                        <td><?= h((string) ($entry['ip_address'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderAdminPagination($page, $total, 'compliance-audit.php', array_filter($filters)); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
