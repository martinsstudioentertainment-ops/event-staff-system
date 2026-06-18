<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/workforce-analytics.php';

requireAdminCapability('staff');

$pdo    = getDB();
$period = in_array($_GET['period'] ?? '', ['90d', '12m'], true) ? (string) $_GET['period'] : '30d';

$filters = [
    'q'               => trim((string) ($_GET['q'] ?? '')),
    'role'            => trim((string) ($_GET['role'] ?? '')),
    'risk'            => trim((string) ($_GET['risk'] ?? '')),
    'min_reliability' => trim((string) ($_GET['min_reliability'] ?? '')),
    'min_attendance'  => trim((string) ($_GET['min_attendance'] ?? '')),
    'min_gps'         => trim((string) ($_GET['min_gps'] ?? '')),
    'compliance'      => trim((string) ($_GET['compliance'] ?? '')),
    'availability'    => trim((string) ($_GET['availability'] ?? '')),
    'has_events'      => trim((string) ($_GET['has_events'] ?? '')),
];

$results = wf_smart_staff_search($pdo, $filters, $period, 75);

try {
    $roles = $pdo->query('SELECT DISTINCT staff_role FROM staff WHERE staff_role IS NOT NULL AND TRIM(staff_role) <> "" ORDER BY staff_role')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $roles = [];
}

$pageTitle  = 'Smart Staff Search';
$activePage = 'staff-search';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Smart Staff Search</h1>
        <p class="wf-hero__subtitle">Advanced filters — location, availability, reliability, compliance, attendance rating, and event history.</p>
    </div>
</div>

<section class="card erp-card">
    <form method="get" class="wf-filters">
        <div><label for="q">Search</label><input id="q" name="q" value="<?= h($filters['q']) ?>" class="input"></div>
        <div>
            <label for="role">Role / skill</label>
            <select id="role" name="role" class="input">
                <option value="">Any</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= h((string) $role) ?>" <?= $filters['role'] === (string) $role ? 'selected' : '' ?>><?= h((string) $role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="risk">Risk</label>
            <select id="risk" name="risk" class="input">
                <option value="">Any</option>
                <?php foreach (['green' => 'Low', 'amber' => 'Medium', 'red' => 'High'] as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $filters['risk'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="min_reliability">Min reliability</label><input id="min_reliability" name="min_reliability" type="number" min="0" max="100" value="<?= h($filters['min_reliability']) ?>" class="input"></div>
        <div><label for="min_attendance">Min attendance %</label><input id="min_attendance" name="min_attendance" type="number" min="0" max="100" value="<?= h($filters['min_attendance']) ?>" class="input"></div>
        <div><label for="min_gps">Min GPS %</label><input id="min_gps" name="min_gps" type="number" min="0" max="100" value="<?= h($filters['min_gps']) ?>" class="input"></div>
        <div>
            <label for="compliance">PSA compliance</label>
            <select id="compliance" name="compliance" class="input">
                <option value="">Any</option>
                <?php foreach (['valid', 'expiring', 'expired', 'missing'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= $filters['compliance'] === $st ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="availability">Availability today</label>
            <select id="availability" name="availability" class="input">
                <option value="">Any</option>
                <option value="available" <?= $filters['availability'] === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="unavailable" <?= $filters['availability'] === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
            </select>
        </div>
        <div>
            <label for="has_events">Event history</label>
            <select id="has_events" name="has_events" class="input">
                <option value="">Any</option>
                <option value="1" <?= $filters['has_events'] === '1' ? 'selected' : '' ?>>Has past events</option>
            </select>
        </div>
        <div>
            <label for="period">Metrics period</label>
            <select id="period" name="period" class="input">
                <?php foreach (['30d' => '30 days', '90d' => '90 days', '12m' => '12 months'] as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $period === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self:end;"><button type="submit" class="btn btn--primary">Search</button></div>
    </form>

    <p class="card__subtitle"><?= count($results) ?> result(s)</p>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Staff</th><th>Role</th><th>Reliability</th><th>Attendance</th><th>GPS</th><th>Risk</th><th>PSA</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($results === []): ?>
                <tr><td colspan="8" class="data-table__empty">No staff match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($results as $row): ?>
                    <?php
                    $psa = wf_psa_compliance_status((string) ($row['psa_expiry_date'] ?? ''), (string) ($row['psa_licence'] ?? ''));
                    $risk = (string) ($row['risk'] ?? 'green');
                    ?>
                    <tr>
                        <td><?= h(wf_staff_display($row)) ?><br><span class="text-muted"><?= h((string) ($row['email'] ?? '')) ?></span></td>
                        <td><?= h((string) ($row['staff_role'] ?? '—')) ?></td>
                        <td><?= (int) ($row['score'] ?? 0) ?></td>
                        <td><?= (int) ($row['attendance_pct'] ?? 0) ?>%</td>
                        <td><?= (int) ($row['gps_pct'] ?? 0) ?>%</td>
                        <td><span class="wf-risk wf-risk--<?= h($risk) ?>"><?= h(wf_risk_label($risk)) ?></span></td>
                        <td><?= h(ucfirst($psa)) ?></td>
                        <td><a class="btn btn--secondary" href="view-staff.php?id=<?= (int) ($row['id'] ?? 0) ?>">Profile</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
