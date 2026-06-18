<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workforce/staff-preferences.php';
require_once __DIR__ . '/../includes/workforce/preference-catalog.php';

requireAdminCapability('staff');

$pdo = getDB();
ensureStaffPreferencesFoundationSchema($pdo);

$filters = [
    'q'                => trim((string) ($_GET['q'] ?? '')),
    'shift_type'       => trim((string) ($_GET['shift_type'] ?? '')),
    'location'         => trim((string) ($_GET['location'] ?? '')),
    'role'             => trim((string) ($_GET['role'] ?? '')),
    'availability_day' => trim((string) ($_GET['availability_day'] ?? '')),
    'cert_type'        => trim((string) ($_GET['cert_type'] ?? '')),
];

$rows    = staffPreferencesAdminList($pdo, $filters);
$catalog = preferenceCatalogOptions();
$locations = preferenceLocationsList($pdo, true);

$pageTitle  = 'Staff preferences';
$activePage = 'staff-preferences';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff preferences</h1>
        <p class="wf-hero__subtitle">View shift, location, role, and availability preferences saved during registration or via the mobile API.</p>
    </div>
    <div class="wf-actions">
        <a class="btn btn--primary" href="staff-preferences-export.php?<?= h(http_build_query(array_filter($filters))) ?>">Export CSV</a>
        <a class="btn btn--secondary" href="settings-preference-locations.php">Manage locations</a>
    </div>
</div>

<section class="card erp-card">
    <form method="get" class="form-grid" style="margin-bottom:1rem;">
        <div class="form-group">
            <label class="form-label" for="q">Search</label>
            <input class="form-input" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Name, email, mobile">
        </div>
        <div class="form-group">
            <label class="form-label" for="shift_type">Shift type</label>
            <select class="form-select" id="shift_type" name="shift_type">
                <option value="">Any</option>
                <?php foreach ($catalog['shift_types'] as $opt): ?>
                    <option value="<?= h($opt['slug']) ?>"<?= $filters['shift_type'] === $opt['slug'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="location">Location</label>
            <select class="form-select" id="location" name="location">
                <option value="">Any</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= h((string) $loc['slug']) ?>"<?= $filters['location'] === $loc['slug'] ? ' selected' : '' ?>><?= h((string) $loc['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="role">Role</label>
            <select class="form-select" id="role" name="role">
                <option value="">Any</option>
                <?php foreach ($catalog['roles'] as $opt): ?>
                    <option value="<?= h($opt['slug']) ?>"<?= $filters['role'] === $opt['slug'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="availability_day">Available day</label>
            <select class="form-select" id="availability_day" name="availability_day">
                <option value="">Any</option>
                <?php foreach ($catalog['availability_days'] as $opt): ?>
                    <option value="<?= h($opt['slug']) ?>"<?= $filters['availability_day'] === $opt['slug'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="cert_type">Certification</label>
            <select class="form-select" id="cert_type" name="cert_type">
                <option value="">Any</option>
                <?php foreach (preferenceCertificationTypes() as $cert): ?>
                    <option value="<?= h($cert) ?>"<?= $filters['cert_type'] === $cert ? ' selected' : '' ?>><?= h($cert) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-group--full form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff-preferences.php" class="btn btn--secondary">Clear</a>
        </div>
    </form>

    <table class="data-table" style="width:100%;">
        <thead>
            <tr>
                <th>Staff</th>
                <th>Email</th>
                <th>Shift types</th>
                <th>Locations</th>
                <th>Roles</th>
                <th>Days</th>
                <th>Hours</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="8">No staff preferences match these filters.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php $prefs = staffPreferencesRowToPayload($row); ?>
                <tr>
                    <td><?= h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''))) ?></td>
                    <td><?= h((string) ($row['email'] ?? '')) ?></td>
                    <td><?= h(implode(', ', $prefs['preferred_shift_types'])) ?></td>
                    <td><?= h(implode(', ', $prefs['preferred_locations'])) ?></td>
                    <td><?= h(implode(', ', $prefs['preferred_roles'])) ?></td>
                    <td><?= h(implode(', ', $prefs['availability_days'])) ?></td>
                    <td><?= h(implode(', ', $prefs['availability_hours'])) ?></td>
                    <td><?= h((string) ($row['updated_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
