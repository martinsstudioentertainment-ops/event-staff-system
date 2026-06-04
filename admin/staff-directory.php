<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('staff');

$pdo = getDB();

// Get filters from request
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'role' => trim((string) ($_GET['role'] ?? '')),
    'blacklisted' => isset($_GET['blacklisted']) ? (bool) $_GET['blacklisted'] : null,
];

// Get staff members
$staffList = $filters['q'] !== '' || $filters['role'] !== '' || $filters['blacklisted'] !== null
    ? getStaffWithFilters($pdo, $filters)
    : getAllStaff($pdo);

$flash = getAdminFlash();
$adminUser = getAdminUser();

$pageTitle = 'Staff Directory';
$activePage = 'staff-directory';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Directory</h2>
            <p class="card__subtitle">
                All staff members in one place. Staff personal information is stored once and linked to event registrations.
            </p>
        </div>
        <a href="staff.php" class="btn btn--secondary">← Staff Registrations</a>
    </div>

    <!-- Filters -->
    <form method="get" class="form form--inline">
        <div class="form__group">
            <label class="form__label">Search</label>
            <input type="text" name="q" class="form__input" placeholder="Name, email, or mobile" value="<?= h($filters['q']) ?>">
        </div>
        <div class="form__group">
            <label class="form__label">Role</label>
            <select name="role" class="form__select">
                <option value="">All Roles</option>
                <option value="dsp" <?= $filters['role'] === 'dsp' ? 'selected' : '' ?>>DSP</option>
                <option value="static" <?= $filters['role'] === 'static' ? 'selected' : '' ?>>Static</option>
                <option value="steward" <?= $filters['role'] === 'steward' ? 'selected' : '' ?>>Steward</option>
            </select>
        </div>
        <div class="form__group">
            <label class="form__label">Status</label>
            <select name="blacklisted" class="form__select">
                <option value="">All</option>
                <option value="0" <?= $filters['blacklisted'] === false ? 'selected' : '' ?>>Active</option>
                <option value="1" <?= $filters['blacklisted'] === true ? 'selected' : '' ?>>Blacklisted</option>
            </select>
        </div>
        <button type="submit" class="btn btn--primary">Filter</button>
        <a href="staff-directory.php" class="btn btn--secondary">Clear</a>
    </form>

    <?php if ($staffList === []): ?>
        <p class="form-hint">
            <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['blacklisted'] !== null): ?>
                No staff members match your filters.
            <?php else: ?>
                No staff members found. The staff table may not exist yet - run the migration scripts first.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Address</th>
                        <th>Role</th>
                        <th>Registrations</th>
                        <th>Approved</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffList as $staff): ?>
                        <tr>
                            <td>
                                <strong><?= h(trim((string) ($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? ''))) ?></strong>
                            </td>
                            <td><?= h((string) $staff['email']) ?></td>
                            <td><?= h((string) ($staff['mobile'] ?? '—')) ?></td>
                            <td>
                                <?= h((string) ($staff['full_address'] ?? '')) ?>
                                <?php if ($staff['eircode']): ?>
                                    <br><small><?= h((string) $staff['eircode']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= $staff['staff_role'] === 'dsp' ? 'approved' : ($staff['staff_role'] === 'static' ? 'pending' : 'rejected') ?>">
                                    <?= h(formatStaffRoleLabel((string) $staff['staff_role'])) ?>
                                </span>
                            </td>
                            <td><?= (int) ($staff['registration_count'] ?? 0) ?></td>
                            <td><?= (int) ($staff['approved_count'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) ($staff['is_blacklisted'] ?? 0) === 1): ?>
                                    <span class="badge badge--rejected">Blacklisted</span>
                                    <?php if ($staff['blacklist_reason']): ?>
                                        <small class="form-hint"><?= h((string) $staff['blacklist_reason']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-actions">
                                <a href="staff-edit.php?id=<?= (int) $staff['id'] ?>" class="btn btn--small btn--primary">Edit</a>
                                <a href="staff.php?q=<?= h((string) $staff['email']) ?>" class="btn btn--small btn--secondary">Registrations</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint">
            Showing <?= count($staffList) ?> staff member(s).
        </p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
