<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/master-staff-identity-ui.php';

requireAdminCapability('settings');

$pdo    = getDB();
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$data   = masterStaffIdentityGetManagerData($pdo, $search !== '' ? $search : null);
$flash  = getAdminFlash();

$pageTitle  = 'Staff Identity Manager';
$activePage = 'staff-identity-manager';

$summary    = $data['summary'] ?? [];
$protection = $data['protection'] ?? [];
$nightly    = $data['nightly'] ?? [];
$version    = $data['version'] ?? [];
$protectionActive = !empty($protection['active']);

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Identity Manager</h2>
            <p class="card__subtitle">Master Staff Identity ensures one person always has one master staff profile — across email changes, mobile, PPSN, PSA licence, event applications, Google Sheets, and mobile logins.</p>
        </div>
        <div class="toolbar">
            <a href="system-health.php" class="btn btn--secondary btn--small">System health</a>
            <a href="staff.php" class="btn btn--secondary btn--small">Staff queue</a>
        </div>
    </div>

    <div class="alert alert--<?= $protectionActive ? 'success' : 'warning' ?> alert--visible" role="status">
        <strong><?= $protectionActive ? '🟢 Master Staff Identity Protection Active' : '🟡 Staff Identity Review Required' ?></strong>
        <p style="margin:0.5rem 0 0">Duplicate staff: <strong><?= (int) ($protection['duplicate_staff'] ?? 0) ?></strong>
        · Duplicate emails: <strong><?= (int) ($protection['duplicate_emails'] ?? 0) ?></strong>
        · Duplicate PPSN: <strong><?= (int) ($protection['duplicate_ppsn'] ?? 0) ?></strong>
        · Duplicate mobile: <strong><?= (int) ($protection['duplicate_mobile'] ?? 0) ?></strong>
        · Duplicate PSA: <strong><?= (int) ($protection['duplicate_psa'] ?? 0) ?></strong>
        · Identity conflicts: <strong><?= (int) ($protection['identity_conflicts'] ?? 0) ?></strong></p>
    </div>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header"><h3 class="card__title">Identity summary</h3></div>
    <div class="erp-dash-kpis" style="padding:0 1rem 1rem">
        <?php
        $kpis = [
            ['Total staff', (int) ($summary['total_staff'] ?? 0), 'blue'],
            ['Active staff', (int) ($summary['active_staff'] ?? 0), 'green'],
            ['Applicants', (int) ($summary['applicants'] ?? 0), 'indigo'],
            ['Identity conflicts', (int) ($summary['identity_conflicts'] ?? 0), 'amber'],
            ['Duplicates prevented', (int) ($summary['duplicate_attempts_prevented'] ?? 0), 'green'],
            ['Alias email events', (int) ($summary['alias_email_events'] ?? 0), 'blue'],
            ['Duplicate PPSN', (int) ($summary['duplicate_pps_attempts'] ?? 0), 'amber'],
            ['Duplicate mobile', (int) ($summary['duplicate_mobile_attempts'] ?? 0), 'amber'],
            ['Duplicate PSA', (int) ($summary['duplicate_psa_attempts'] ?? 0), 'amber'],
        ];
        foreach ($kpis as [$label, $value, $color]):
        ?>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--<?= h($color) ?>" aria-hidden="true">#</div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= $value ?></p>
                <p class="erp-dash-kpi__label"><?= h($label) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header card__header--row">
        <h3 class="card__title">Search staff identity</h3>
    </div>
    <form method="get" class="toolbar" style="padding:0 1rem 1rem;gap:0.5rem;flex-wrap:wrap">
        <input type="search" name="q" class="form-input" style="max-width:22rem;flex:1;min-width:12rem"
               placeholder="Staff ID, name, email, alias, PPS, mobile, PSA…"
               value="<?= h($search) ?>" autocomplete="off">
        <button type="submit" class="btn btn--primary btn--small">Search</button>
        <?php if ($search !== ''): ?>
            <a href="staff-identity-manager.php" class="btn btn--secondary btn--small">Clear</a>
        <?php endif; ?>
    </form>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header"><h3 class="card__title">Staff identity</h3></div>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead>
                <tr>
                    <th>Staff ID</th>
                    <th>Name</th>
                    <th>Primary email</th>
                    <th>Alias emails</th>
                    <th>Mobile</th>
                    <th>PPS</th>
                    <th>PSA</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php if (($data['staff'] ?? []) === []): ?>
                <tr><td colspan="10" class="text-muted">No staff records match your search.</td></tr>
            <?php else: ?>
                <?php foreach ($data['staff'] as $row): ?>
                    <tr>
                        <td><a href="staff-edit.php?id=<?= (int) ($row['id'] ?? 0) ?>">#<?= (int) ($row['id'] ?? 0) ?></a></td>
                        <td><?= h(trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? '')))) ?></td>
                        <td><?= h((string) ($row['email'] ?? '')) ?></td>
                        <td><?= h(implode(', ', $row['alias_emails'] ?? []) ?: '—') ?></td>
                        <td><?= h((string) ($row['mobile'] ?? '')) ?></td>
                        <td><?= h((string) ($row['pps_number'] ?? '')) ?></td>
                        <td><?= h((string) ($row['psa_licence'] ?? '')) ?></td>
                        <td><?= h((string) ($row['status_label'] ?? '')) ?></td>
                        <td><?= h(substr((string) ($row['created_at'] ?? ''), 0, 10)) ?></td>
                        <td><?= h(substr((string) ($row['updated_at'] ?? ''), 0, 10)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($search === ''): ?>
        <p class="card__subtitle" style="margin:0.75rem 1rem 1rem">Showing the 75 most recently updated staff. Use search to find any record.</p>
    <?php endif; ?>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header"><h3 class="card__title">Identity history</h3></div>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Source</th>
                    <th>Action taken</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php if (($data['history'] ?? []) === []): ?>
                <tr><td colspan="5" class="text-muted">No identity events recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($data['history'] as $event): ?>
                    <?php
                    $action = (string) ($event['action'] ?? '');
                    $detail = trim((string) ($event['details'] ?? ''));
                    if ($detail === '' && !empty($event['submitted_email']) && !empty($event['canonical_email'])) {
                        $detail = (string) $event['submitted_email'] . ' → ' . (string) $event['canonical_email'];
                    }
                    ?>
                    <tr>
                        <td><?= h((string) ($event['created_at'] ?? '')) ?></td>
                        <td>
                            <?php if (!empty($event['staff_id'])): ?>
                                <a href="staff-edit.php?id=<?= (int) $event['staff_id'] ?>">#<?= (int) $event['staff_id'] ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= h(masterStaffIdentitySourceLabel((string) ($event['source'] ?? ''))) ?></td>
                        <td><?= h(masterStaffIdentityActionLabel($action)) ?></td>
                        <td><?= h($detail !== '' ? $detail : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header card__header--row">
        <div>
            <h3 class="card__title">Nightly identity audit</h3>
            <p class="card__subtitle">Master Staff Identity Protection runs automatically each night.</p>
        </div>
    </div>
    <div class="erp-dash-kpis" style="padding:0 1rem 1rem">
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= h((string) ($nightly['last_at'] ?: '—')) ?></p>
                <p class="erp-dash-kpi__label">Last audit date</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) ($nightly['issues_found'] ?? 0) ?></p>
                <p class="erp-dash-kpi__label">Issues found (last run)</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) ($nightly['issues_fixed_auto'] ?? 0) ?></p>
                <p class="erp-dash-kpi__label">Issues fixed automatically</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) ($nightly['manual_review_required'] ?? 0) ?></p>
                <p class="erp-dash-kpi__label">Manual reviews required</p>
            </div>
        </div>
    </div>
</section>

<section class="card" style="margin-top:1rem">
    <div class="card__header"><h3 class="card__title">How Master Staff Identity works</h3></div>
    <div style="padding:0 1rem 1rem">
        <p>Whenever a new application is submitted, Staff Identity Protection:</p>
        <ol style="margin:0.5rem 0 0;padding-left:1.25rem">
            <li>Searches for an existing staff member by Staff ID, PPS number, primary email, mobile number, or PSA licence.</li>
            <li>If a match exists — updates the existing master profile and never creates a duplicate staff member.</li>
            <li>If no match exists — creates a new master staff profile.</li>
            <li>Records every action in the Staff Identity Audit log above.</li>
        </ol>
        <p class="form-hint" style="margin-top:0.75rem">Version <?= h((string) ($version['canonical_identity_version'] ?? '1.0.0')) ?> · Baseline <?= h((string) ($version['baseline_date'] ?? '')) ?></p>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
