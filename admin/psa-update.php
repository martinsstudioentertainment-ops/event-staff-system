<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/google-form-psa-self-update.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/psa-licence-verify.php';

requireAdminCapability('staff');

$pdo = getDB();
$q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$staffId = (int) ($_GET['id'] ?? $_POST['staff_id'] ?? 0);
$flash = getAdminFlash();
$results = [];
$selected = null;
$newLicence = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update') {
    if (!verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        setAdminFlash('error', 'Your session expired. Try again.');
    } else {
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $licence = (string) ($_POST['psa_licence'] ?? '');
        $result = googleFormPsaAdminUpdate($pdo, $staffId, $licence);
        setAdminFlash(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Could not update.'));
    }
    $returnQ = rawurlencode(trim((string) ($_POST['q'] ?? '')));
    header('Location: psa-update.php?id=' . $staffId . ($returnQ !== '' ? '&q=' . $returnQ : ''));
    exit;
}

if ($q !== '') {
    $results = googleFormPsaAdminSearch($pdo, $q);
    if ($staffId < 1 && count($results) === 1) {
        $staffId = (int) ($results[0]['id'] ?? 0);
    }
}

if ($staffId > 0) {
    $selected = getStaffById($pdo, $staffId);
    if ($selected !== null) {
        $selected['events'] = googleFormPsaAdminStaffEvents(
            $pdo,
            $staffId,
            (string) ($selected['email'] ?? '')
        );
        $newLicence = (string) ($selected['psa_licence'] ?? '');
    }
}

$pageTitle  = 'Update PSA';
$activePage = 'psa-update';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css?v=3">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Update PSA licence</h2>
        <p class="card__subtitle">Type a name or email. Open the person. Change the licence. Official PSA register is checked on save.</p>
    </div>
    <form method="get" action="psa-update.php" class="form-grid settings-form">
        <div class="form-group form-group--full">
            <label class="form-label" for="q">Name or email</label>
            <input class="form-input" type="search" id="q" name="q" value="<?= h($q) ?>" autofocus placeholder="e.g. John Murphy or john@email.com">
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Find</button>
        </div>
    </form>
</section>

<?php if ($q !== '' && $selected === null): ?>
<section class="card" style="margin-top:0.75rem;">
    <?php if ($results === []): ?>
        <p>No one matched <strong><?= h($q) ?></strong>.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Mobile</th><th>PSA</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?= h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''))) ?></td>
                        <td><?= h((string) ($row['email'] ?? '')) ?></td>
                        <td><?= h((string) ($row['mobile'] ?? '')) ?></td>
                        <td><?= h((string) ($row['psa_licence'] ?? '')) ?></td>
                        <td><a class="btn btn--small btn--primary" href="psa-update.php?id=<?= (int) ($row['id'] ?? 0) ?>&amp;q=<?= h(urlencode($q)) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($selected !== null): ?>
    <?php
    $fullName = trim((string) ($selected['first_name'] ?? '') . ' ' . (string) ($selected['surname'] ?? ''));
    $psaStatus = trim((string) ($selected['psa_register_status'] ?? ''));
    $eventLines = [];
    foreach (($selected['events'] ?? []) as $event) {
        $label = trim((string) ($event['name'] ?? ''));
        $date = (string) ($event['date'] ?? '');
        if ($date !== '') {
            $label .= ($label !== '' ? ' — ' : '') . formatEventDateLabel($date);
        }
        if ($label !== '') {
            $eventLines[] = $label;
        }
    }
    ?>
<section class="card" style="margin-top:0.75rem;">
    <div class="card__header">
        <h2 class="card__title"><?= h($fullName !== '' ? $fullName : 'Staff') ?></h2>
    </div>
    <dl class="list-card__facts">
        <div><dt>Name</dt><dd><?= h($fullName !== '' ? $fullName : '—') ?></dd></div>
        <div><dt>Email</dt><dd><?= h((string) ($selected['email'] ?? '—')) ?></dd></div>
        <div><dt>Mobile</dt><dd><?= h((string) ($selected['mobile'] ?? '—')) ?></dd></div>
        <div><dt>Role</dt><dd><?= h((string) ($selected['staff_role'] ?? '—')) ?></dd></div>
        <div><dt>Current PSA</dt><dd><?= h((string) ($selected['psa_licence'] ?? '—')) ?></dd></div>
        <div><dt>Register</dt><dd><?= h(psaRegisterStatusLabel($psaStatus)) ?></dd></div>
        <div><dt>Expiry</dt><dd><?= h((string) ($selected['psa_expiry_date'] ?? '—')) ?></dd></div>
        <div><dt>Events</dt><dd><?= $eventLines !== [] ? h(implode(', ', $eventLines)) : '—' ?></dd></div>
    </dl>

    <form method="post" action="psa-update.php" class="form-grid settings-form" style="margin-top:1rem;">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="staff_id" value="<?= (int) $selected['id'] ?>">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <div class="form-group">
            <label class="form-label form-label--required" for="psa_licence">Correct PSA licence</label>
            <input class="form-input" type="text" id="psa_licence" name="psa_licence" required value="<?= h($newLicence) ?>" placeholder="EM123456/00" autocapitalize="characters" pattern="EM[0-9]{6}/[0-9]{2}">
            <p class="form-hint">Format EM123456/00. Checked on psa-gov.ie when you save.</p>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Update PSA</button>
            <a class="btn btn--secondary" href="psa-update.php">New search</a>
        </div>
    </form>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
