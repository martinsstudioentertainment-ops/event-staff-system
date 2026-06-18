<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/compliance-repository.php';

requireAdminCapability('staff');

$pdo = getDB();
$q   = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$docs = wf_staff_documents($pdo, ['q' => $q, 'status' => $status], 100, 0);

$pageTitle  = 'Staff Document Centre';
$activePage = 'staff-documents';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Document Centre</h1>
        <p class="wf-hero__subtitle">Centralized view of PSA licences, certificate images, expiry dates, and missing documents.</p>
    </div>
</div>

<section class="card erp-card">
    <form method="get" class="wf-filters">
        <div>
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" class="input" placeholder="Name or email">
        </div>
        <div>
            <label for="status">Filter</label>
            <select id="status" name="status" class="input">
                <option value="">All</option>
                <option value="missing" <?= $status === 'missing' ? 'selected' : '' ?>>Missing documents</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending / expiring</option>
                <option value="valid" <?= $status === 'valid' ? 'selected' : '' ?>>Valid</option>
            </select>
        </div>
        <div style="align-self:end;"><button type="submit" class="btn btn--primary">Apply</button></div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Staff</th><th>Documents</th><th>Expiry</th><th>Status</th><th>Files</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($docs === []): ?>
                <tr><td colspan="6" class="data-table__empty">No documents match your filters.</td></tr>
            <?php else: ?>
                <?php foreach ($docs as $doc): ?>
                    <?php foreach (($doc['items'] ?? []) as $item): ?>
                        <?php $st = (string) ($item['status'] ?? 'missing'); ?>
                        <tr>
                            <td><?= h((string) ($doc['name'] ?? '')) ?></td>
                            <td><?= h((string) ($item['label'] ?? '')) ?> (<?= h((string) ($item['type'] ?? '')) ?>)</td>
                            <td><?= ($item['expiry'] ?? '') !== '' ? h(formatSystemDate((string) $item['expiry'], $pdo)) : '—' ?></td>
                            <td><span class="wf-risk wf-risk--<?= $st === 'valid' ? 'green' : ($st === 'expiring' ? 'amber' : 'red') ?>"><?= h(ucfirst($st)) ?></span></td>
                            <td><?= count($item['files'] ?? []) ?> file(s)</td>
                            <td><a class="btn btn--secondary" href="staff-edit.php?id=<?= (int) ($doc['staff_id'] ?? 0) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($doc['items'] ?? []) === []): ?>
                        <tr>
                            <td><?= h((string) ($doc['name'] ?? '')) ?></td>
                            <td colspan="2">No documents on file</td>
                            <td><span class="wf-risk wf-risk--red">Missing</span></td>
                            <td>—</td>
                            <td><a class="btn btn--secondary" href="staff-edit.php?id=<?= (int) ($doc['staff_id'] ?? 0) ?>">Add</a></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
