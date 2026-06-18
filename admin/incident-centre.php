<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/incidents-repository.php';

requireAdminCapability('staff');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);

if (!empty($_GET['seed'])) {
    incidents_seed_from_attendance($pdo);
    setAdminFlash('success', 'Imported recent attendance incidents.');
    header('Location: incident-centre.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $admin = getAdminUser();
        incidents_create(
            $pdo,
            (string) ($_POST['incident_type'] ?? 'other'),
            trim((string) ($_POST['title'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            (int) ($_POST['staff_id'] ?? 0) ?: null,
            (int) ($_POST['event_id'] ?? 0) ?: null,
            $admin ? (int) $admin['id'] : null,
            trim((string) ($_POST['evidence_text'] ?? '')),
            trim((string) ($_POST['actions_taken'] ?? '')),
            (string) ($_POST['risk_level'] ?? 'medium')
        );
        setAdminFlash('success', 'Incident logged.');
    } elseif ($action === 'update') {
        incidents_update_status($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? 'open'), (string) ($_POST['resolution'] ?? ''));
        incidents_update_details($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['evidence_text'] ?? ''), (string) ($_POST['actions_taken'] ?? ''), (string) ($_POST['risk_level'] ?? ''));
        setAdminFlash('success', 'Incident updated.');
    }
    header('Location: incident-centre.php');
    exit;
}

$filter  = trim((string) ($_GET['status'] ?? ''));
$summary = incidents_summary($pdo);
$list    = incidents_list($pdo, $filter !== '' ? $filter : null, null, 100);
$events  = getEventsForFilter($pdo);
$staffList = getStaffWithFilters($pdo, [], 200, 0);

$pageTitle  = 'Incident Management';
$activePage = 'incident-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Incident Management</h1>
        <p class="wf-hero__subtitle">Attendance, venue, conduct, GPS, safety — investigations and resolutions.</p>
    </div>
    <a href="incident-centre.php?seed=1" class="btn btn--secondary">Import from attendance</a>
</div>

<div class="wf-grid">
    <?php foreach (['open', 'investigating', 'resolved', 'closed'] as $st): ?>
        <a href="incident-centre.php?status=<?= h($st) ?>" class="wf-metric" style="text-decoration:none;color:inherit;">
            <div class="wf-metric__value"><?= (int) ($summary[$st] ?? 0) ?></div>
            <div class="wf-metric__label"><?= h(ucfirst($st)) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<section class="card erp-card">
    <h2 class="wf-panel__title">Log incident</h2>
    <form method="post" class="wf-filters"><?= csrfField() ?><input type="hidden" name="action" value="create">
        <div><label>Type</label><select name="incident_type" class="input"><?php foreach (incident_types() as $t): ?><option value="<?= h($t) ?>"><?= h(str_replace('_', ' ', ucfirst($t))) ?></option><?php endforeach; ?></select></div>
        <div><label>Risk</label><select name="risk_level" class="input"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
        <div><label>Staff</label><select name="staff_id" class="input"><option value="0">—</option><?php foreach ($staffList as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h(trim($s['first_name'] . ' ' . $s['surname'])) ?></option><?php endforeach; ?></select></div>
        <div><label>Event</label><select name="event_id" class="input"><option value="0">—</option><?php foreach ($events as $ev): ?><option value="<?= (int) $ev['id'] ?>"><?= h($ev['name'] ?? '') ?></option><?php endforeach; ?></select></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Title</label><input name="title" class="input" required></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Description</label><textarea name="description" class="input" rows="3"></textarea></div>
        <div class="form-group--full" style="grid-column:1/-1;"><label>Evidence</label><textarea name="evidence_text" class="input" rows="2" placeholder="Screenshots, GPS logs, witness statements…"></textarea></div>
        <div><button class="btn btn--primary">Create</button></div>
    </form>
</section>

<section class="card erp-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Reported</th><th>Type</th><th>Risk</th><th>Title</th><th>Staff</th><th>Event</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($list as $i): ?>
                <tr>
                    <td><?= h(formatSystemDateTime((string) ($i['reported_at'] ?? ''), $pdo)) ?></td>
                    <td><?= h(str_replace('_', ' ', ucfirst((string) ($i['incident_type'] ?? '')))) ?></td>
                    <td><?= h(ucfirst((string) ($i['risk_level'] ?? 'medium'))) ?></td>
                    <td><?= h((string) ($i['title'] ?? '')) ?></td>
                    <td><?= h(trim(($i['first_name'] ?? '') . ' ' . ($i['surname'] ?? ''))) ?: '—' ?></td>
                    <td><?= h((string) ($i['event_name'] ?? '—')) ?></td>
                    <td><?= h((string) ($i['incident_status'] ?? '')) ?></td>
                    <td>
                        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) ($i['id'] ?? 0) ?>">
                            <select name="status" class="input"><option value="investigating">Investigating</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select>
                            <input name="actions_taken" class="input" placeholder="Actions taken" style="max-width:140px;">
                            <button class="btn btn--secondary">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($list === []): ?><tr><td colspan="8" class="data-table__empty">No incidents.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
