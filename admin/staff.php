<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('staff');

$pdo     = getDB();
$filters = getStaffFiltersFromRequest();
$page    = adminListPage();
$perPage = adminListPerPage();
$offset  = adminListOffset($page);
$total   = countUniqueStaffRegistrants($pdo, $filters);
$rows    = getUniqueStaffRegistrants($pdo, $filters, $perPage, $offset);
$events  = getEventsForFilter($pdo);
$flash   = getAdminFlash();

$queryString = http_build_query(array_filter([
    'q'        => $filters['q'] !== '' ? $filters['q'] : null,
    'status'   => $filters['status'] !== '' ? $filters['status'] : null,
    'role'     => $filters['role'] !== '' ? $filters['role'] : null,
    'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
]));

$pageTitle  = 'Staff List';
$activePage = 'staff';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Registrations</h2>
            <p class="card__subtitle"><?= (int) $total ?> staff — one row per person. Open a profile to see all shifts.</p>
        </div>
        <a href="export-staff.php<?= $queryString !== '' ? '?' . h($queryString) : '' ?>" class="btn btn--secondary">Export CSV</a>
    </div>

    <form method="get" class="filter-bar">
        <div class="filter-bar__group">
            <input class="form-input" type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search name, email, mobile">
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <option value="pending"<?= $filters['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="approved"<?= $filters['status'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="rejected"<?= $filters['status'] === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="role">
                <option value="">All roles</option>
                <?php foreach (getKnownStaffRoles() as $role): ?>
                    <?php if ($role === 'both' || $role === 'security') { continue; } ?>
                    <option value="<?= h($role) ?>"<?= $filters['role'] === $role ? ' selected' : '' ?>><?= h(formatStaffRoleLabel($role)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= (int) $filters['event_id'] === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h($event['name'] . ' — ' . date('d.m.Y', strtotime($event['event_date']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff.php" class="btn btn--secondary">Reset</a>
        </div>
    </form>

    <?php if ($rows !== []): ?>
        <form method="post" action="bulk-status.php" class="bulk-toolbar" id="staff-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="redirect_query" value="<?= h($queryString) ?>">
            <span class="bulk-toolbar__label"><span id="bulk-selected-count">0</span> selected</span>
            <button type="submit" name="status" value="approved" class="btn btn--small btn--success">Approve selected</button>
            <button type="submit" name="status" value="rejected" class="btn btn--small btn--danger">Reject selected</button>
            <button type="submit" name="status" value="pending" class="btn btn--small btn--secondary">Mark pending</button>
        </form>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="data-table__check"><input type="checkbox" id="staff-select-all" aria-label="Select all"></th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Shifts</th>
                    <th>Status</th>
                    <th>Last registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="8" class="data-table__empty">No staff found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $profileOk    = isStaffOnboardingComplete($row);
                        $viewId         = (int) ($row['latest_reg_id'] ?? $row['id'] ?? 0);
                        $shiftCount     = (int) ($row['registration_count'] ?? 1);
                        $statusSummary  = formatRegistrantStatusSummary($row);
                        $primaryStatus  = registrantPrimaryStatus($row);
                        ?>
                        <tr>
                            <td class="data-table__check">
                                <input type="checkbox" form="staff-bulk-form" name="emails[]" value="<?= h((string) $row['email']) ?>" class="staff-row-check" aria-label="Select <?= h($row['first_name'] . ' ' . $row['surname']) ?>">
                            </td>
                            <td>
                                <?= h($row['first_name'] . ' ' . $row['surname']) ?>
                                <?php if (!$profileOk): ?>
                                    <br><span class="badge badge--pending" title="Staff must complete profile before approval">Profile incomplete</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="staff-dir-email" href="<?= h(buildStaffRegistrationsAdminUrl((string) $row['email'])) ?>"><?= h($row['email']) ?></a>
                            </td>
                            <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
                            <td><?= $shiftCount === 1 ? '1 shift' : $shiftCount . ' shifts' ?></td>
                            <td>
                                <span class="badge badge--<?= h($primaryStatus) ?>" title="<?= h($statusSummary) ?>"><?= h($statusSummary) ?></span>
                            </td>
                            <td><?= h(date('d.m.Y H:i', strtotime((string) ($row['last_registered'] ?? $row['created_at'])))) ?></td>
                            <td>
                                <a href="view-staff.php?id=<?= $viewId ?>" class="btn btn--small btn--secondary">View profile</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $paginationQuery = array_filter([
        'q'        => $filters['q'] !== '' ? $filters['q'] : null,
        'status'   => $filters['status'] !== '' ? $filters['status'] : null,
        'role'     => $filters['role'] !== '' ? $filters['role'] : null,
        'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
    ]);
    renderAdminPagination($page, $total, 'staff.php', $paginationQuery);
    ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
