<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/clients-repository.php';
require_once __DIR__ . '/../includes/automation/contracts-repository.php';

requireAdminCapability('staff');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);

$tab = ($_GET['tab'] ?? '') === 'client' ? 'client' : 'staff';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    if (($_POST['contract_type'] ?? '') === 'client') {
        contracts_save_client($pdo, $_POST, (int) ($_POST['id'] ?? 0) ?: null);
    } else {
        contracts_save_staff($pdo, $_POST, (int) ($_POST['id'] ?? 0) ?: null);
    }
    setAdminFlash('success', 'Contract saved.');
    header('Location: contract-centre.php?tab=' . urlencode($tab));
    exit;
}

$expiry   = contracts_expiry_summary($pdo);
$staffC   = contracts_list_staff($pdo);
$clientC  = contracts_list_client($pdo);
$staffList = getStaffWithFilters($pdo, [], 200, 0);
$clients  = clients_list($pdo);

$pageTitle  = 'Contract Management';
$activePage = in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['contracts-centre.php', 'contract-centre.php'], true)
    ? (basename($_SERVER['SCRIPT_NAME'] ?? '', '.php'))
    : 'contract-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Contract Management</h1>
        <p class="wf-hero__subtitle">Staff and client contracts — expiry, renewals, signed documents, compliance.</p>
    </div>
    <div class="wf-period-tabs">
        <a href="contract-centre.php?tab=staff" class="<?= $tab === 'staff' ? 'is-active' : '' ?>">Staff</a>
        <a href="contract-centre.php?tab=client" class="<?= $tab === 'client' ? 'is-active' : '' ?>">Clients</a>
    </div>
</div>

<div class="wf-grid">
    <div class="wf-metric wf-metric--amber"><div class="wf-metric__value"><?= (int) $expiry['expiring'] ?></div><div class="wf-metric__label">Expiring (30d)</div></div>
    <div class="wf-metric wf-metric--red"><div class="wf-metric__value"><?= (int) $expiry['expired'] ?></div><div class="wf-metric__label">Expired</div></div>
    <div class="wf-metric"><div class="wf-metric__value"><?= (int) $expiry['renewal_due'] ?></div><div class="wf-metric__label">Renewal due</div></div>
    <div class="wf-metric wf-metric--red"><div class="wf-metric__value"><?= (int) ($expiry['missing'] ?? 0) ?></div><div class="wf-metric__label">Missing contracts</div></div>
</div>

<section class="card erp-card">
    <h2 class="wf-panel__title">Add contract</h2>
    <form method="post" class="wf-filters"><?= csrfField() ?>
        <input type="hidden" name="contract_type" value="<?= h($tab) ?>">
        <?php if ($tab === 'staff'): ?>
            <div><label>Staff</label><select name="staff_id" class="input" required><?php foreach ($staffList as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h(trim($s['first_name'] . ' ' . $s['surname'])) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
            <div><label>Client</label><select name="client_id" class="input" required><?php foreach ($clients as $c): if ((int)($c['id']??0)<1) continue; ?><option value="<?= (int) $c['id'] ?>"><?= h($c['name'] ?? '') ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div><label>Title</label><input name="title" class="input" required></div>
        <div><label>Start</label><input type="date" name="start_date" class="input"></div>
        <div><label>End</label><input type="date" name="end_date" class="input"></div>
        <div><label>Status</label><select name="contract_status" class="input"><option value="draft">Draft</option><option value="active">Active</option><option value="renewal_due">Renewal due</option></select></div>
        <div><label>Signed</label><input type="date" name="signed_at" class="input"></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Document path / URL</label><input name="document_path" class="input" placeholder="storage/..."></div>
        <div><button class="btn btn--primary">Save</button></div>
    </form>
</section>

<section class="card erp-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Party</th><th>Title</th><th>End</th><th>Status</th><th>Compliance</th></tr></thead>
            <tbody>
            <?php $rows = $tab === 'client' ? $clientC : $staffC; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($tab === 'client' ? (string) ($r['client_name'] ?? '') : trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''))) ?></td>
                    <td><?= h((string) ($r['title'] ?? '')) ?></td>
                    <td><?= ($r['end_date'] ?? '') ? h(formatSystemDate((string) $r['end_date'], $pdo)) : '—' ?></td>
                    <td><?= h((string) ($r['contract_status'] ?? '')) ?></td>
                    <td><?= h((string) ($r['compliance_status'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="5" class="data-table__empty">No contracts on file.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
