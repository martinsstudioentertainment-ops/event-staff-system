<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/compliance-repository.php';

requireAdminCapability('staff');

$pdo      = getDB();
$summary  = wf_compliance_summary($pdo);
$certTypes = wf_compliance_cert_types();
$filter   = trim((string) ($_GET['status'] ?? ''));

$alerts = $summary['alerts'];
if ($filter !== '' && in_array($filter, ['valid', 'expiring', 'expired', 'missing'], true)) {
    $alerts = array_values(array_filter($alerts, static fn (array $a): bool => ($a['status'] ?? '') === $filter));
}

$pageTitle  = 'Staff Compliance Centre';
$activePage = 'compliance-centre';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Compliance Centre</h1>
        <p class="wf-hero__subtitle">Track PSA licence compliance. Additional certification types are listed for reference — configure fields when ready.</p>
    </div>
</div>

<div class="wf-grid">
    <div class="wf-metric wf-metric--green"><div class="wf-metric__value"><?= (int) $summary['valid'] ?></div><div class="wf-metric__label">Valid PSA</div></div>
    <div class="wf-metric wf-metric--amber"><div class="wf-metric__value"><?= (int) $summary['expiring'] ?></div><div class="wf-metric__label">Expiring soon</div></div>
    <div class="wf-metric wf-metric--red"><div class="wf-metric__value"><?= (int) $summary['expired'] ?></div><div class="wf-metric__label">Expired</div></div>
    <div class="wf-metric"><div class="wf-metric__value"><?= (int) $summary['missing'] ?></div><div class="wf-metric__label">Missing</div></div>
</div>

<section class="card erp-card wf-panel">
    <h2 class="wf-panel__title">Certification types</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Type</th><th>Status in system</th></tr></thead>
            <tbody>
            <?php foreach ($certTypes as $cert): ?>
                <tr>
                    <td><?= h((string) ($cert['label'] ?? '')) ?></td>
                    <td><?= !empty($cert['tracked']) ? 'Tracked (PSA fields on staff profile)' : 'Not yet tracked — add fields via migration' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <h2 class="card__title">Compliance alerts</h2>
        <div class="wf-period-tabs">
            <?php foreach (['' => 'All', 'expiring' => 'Expiring', 'expired' => 'Expired', 'missing' => 'Missing'] as $key => $label): ?>
                <a href="compliance-centre.php<?= $key !== '' ? '?status=' . urlencode($key) : '' ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($alerts === []): ?>
        <p class="card__subtitle">No compliance alerts.</p>
    <?php else: ?>
        <ul class="wf-alert-list">
            <?php foreach ($alerts as $alert): ?>
                <?php $st = (string) ($alert['status'] ?? ''); ?>
                <li>
                    <strong><?= h((string) ($alert['name'] ?? '')) ?></strong> — <?= h((string) ($alert['cert'] ?? '')) ?>
                    <span class="wf-risk wf-risk--<?= $st === 'expiring' ? 'amber' : ($st === 'valid' ? 'green' : 'red') ?>"><?= h(ucfirst($st)) ?></span>
                    <?php if (($alert['expiry'] ?? '') !== ''): ?> · Expires <?= h(formatSystemDate((string) $alert['expiry'], $pdo)) ?><?php endif; ?>
                    · <a href="view-staff.php?id=<?= (int) ($alert['staff_id'] ?? 0) ?>">View profile</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
