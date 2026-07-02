<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('staff');

$pdo              = getDB();
$filters          = getStaffFiltersFromRequest();
$pendingListMode  = ($filters['status'] === 'pending');
$pendingDbCount   = 0;
$listPerPage      = adminStaffListPerPageFromRequest();
$flatEventList    = !$pendingListMode && (int) ($filters['event_id'] ?? 0) > 0;

if ($pendingListMode) {
    $allPending     = fetchPendingRegistrations($pdo, 500);
    $filtered       = filterPendingRegistrationRows($allPending, $filters);
    $total          = count($filtered);
    $pendingDbCount = countPendingRegistrations($pdo);

    if ($total === 0 && $pendingDbCount > 0 && $filters['q'] === '' && $filters['role'] === '' && $filters['event_id'] === 0) {
        $filtered = $allPending;
        $total    = count($filtered);
    }

    $totalPages = max(1, adminListTotalPages($total, $listPerPage));
    $page       = min(max(1, adminListPage()), $totalPages);
    $rows       = array_slice($filtered, adminListOffset($page, $listPerPage), $listPerPage);
} elseif ($flatEventList) {
    // One row per signup for the selected event (matches DB / export counts).
    $total = countStaffRegistrations($pdo, $filters);
    $page  = min(adminListPage(), adminListTotalPages($total, $listPerPage));
    $rows  = getStaffRegistrations($pdo, $filters, $listPerPage, adminListOffset($page, $listPerPage));
} else {
    $total = countStaffListTotal($pdo, $filters);
    $page  = min(adminListPage(), adminListTotalPages($total, $listPerPage));
    $rows  = getUniqueStaffRegistrants($pdo, $filters, $listPerPage, adminListOffset($page, $listPerPage));
}

$pendingRegCount = $pendingListMode ? $total : 0;
$pendingListGap  = $pendingListMode && $pendingDbCount > 0 && $total === 0;
$events  = getEventsForFilter($pdo);
$flash   = getAdminFlash();

$queryString = http_build_query(array_filter([
    'q'        => $filters['q'] !== '' ? $filters['q'] : null,
    'status'   => $filters['status'] !== '' ? $filters['status'] : null,
    'role'     => $filters['role'] !== '' ? $filters['role'] : null,
    'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
    'per_page' => $listPerPage !== adminStaffListPerPage() ? $listPerPage : null,
]));

$pageTitle  = 'Staff List';
$activePage = 'staff';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if ($pendingListGap): ?>
    <div class="alert alert--warning alert--visible">
        Database has <?= (int) $pendingDbCount ?> pending registration(s) but the list could not load them.
        <?php
        $rescueIds = [];
        try {
            $rescueIds = $pdo->query(
                'SELECT id, surname, first_name, email FROM staff_registrations WHERE ' . pendingRegistrationStatusSql() . ' ORDER BY id DESC LIMIT 20'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rescueIds = [];
        }
        ?>
        <?php if ($rescueIds !== []): ?>
            <ul style="margin:0.5rem 0 0 1.25rem">
                <?php foreach ($rescueIds as $rescue): ?>
                    <li>
                        <a href="view-staff.php?id=<?= (int) ($rescue['id'] ?? 0) ?>">
                            <?= h(trim(($rescue['first_name'] ?? '') . ' ' . ($rescue['surname'] ?? ''))) ?>
                            <?= trim((string) ($rescue['email'] ?? '')) !== '' ? '(' . h((string) $rescue['email']) . ')' : '' ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <a href="staff.php?status=pending&amp;page=1">Reset filters</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Registrations</h2>
            <p class="card__subtitle">
                <?php if ($pendingListMode && $total > 0): ?>
                    <?= (int) $total ?> pending registration<?= $total === 1 ? '' : 's' ?> — open a profile to approve each shift.
                <?php elseif ($pendingListMode): ?>
                    No pending registrations. Try <a href="staff.php">All statuses</a>.
                <?php elseif ($flatEventList): ?>
                    <?= (int) $total ?> registration<?= $total === 1 ? '' : 's' ?> for this event (<?= (int) $listPerPage ?> per page).
                <?php else: ?>
                    <?= (int) $total ?> staff — one row per person (<?= (int) $listPerPage ?> per page). Open a profile to see all shifts.
                <?php endif; ?>
            </p>
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
                        <?= h($event['name'] . ' — ' . formatEventDateLabel((string) $event['event_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="per_page" aria-label="Rows per page">
                <?php foreach (adminStaffListPerPageOptions() as $perPageOpt): ?>
                    <option value="<?= (int) $perPageOpt ?>"<?= $listPerPage === $perPageOpt ? ' selected' : '' ?>><?= (int) $perPageOpt ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff.php" class="btn btn--secondary">Reset</a>
        </div>
    </form>

    <?php
    $paginationQuery = array_filter([
        'q'        => $filters['q'] !== '' ? $filters['q'] : null,
        'status'   => $filters['status'] !== '' ? $filters['status'] : null,
        'role'     => $filters['role'] !== '' ? $filters['role'] : null,
        'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
        'per_page' => $listPerPage !== adminStaffListPerPage() ? $listPerPage : null,
    ]);
    if ($total > $listPerPage): ?>
        <?php renderAdminPagination($page, $total, 'staff.php', $paginationQuery, $listPerPage); ?>
    <?php endif; ?>

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
                    <th><?= ($pendingListMode || $flatEventList) ? 'Event' : 'Shifts' ?></th>
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
                        $viewId         = (int) ($row['id'] ?? 0);
                        $shiftCount     = ($pendingListMode || $flatEventList)
                            ? 1
                            : (int) ($row['registration_count'] ?? 1);
                        $statusSummary  = ($pendingListMode || $flatEventList)
                            ? formatStatusLabel((string) ($row['status'] ?? 'pending'))
                            : formatRegistrantStatusSummary($row);
                        $primaryStatus  = ($pendingListMode || $flatEventList)
                            ? (string) ($row['status'] ?? 'pending')
                            : registrantPrimaryStatus($row);
                        $editStaffId    = (int) ($row['staff_id'] ?? 0);
                        if ($editStaffId < 1 && trim((string) ($row['email'] ?? '')) !== '') {
                            $editStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) $row['email']) ?? 0);
                        }
                        ?>
                        <tr>
                            <td class="data-table__check">
                                <input type="checkbox" form="staff-bulk-form" name="<?= ($pendingListMode || $flatEventList) ? 'ids[]' : 'emails[]' ?>" value="<?= ($pendingListMode || $flatEventList) ? (int) $viewId : h((string) $row['email']) ?>" class="staff-row-check" aria-label="Select <?= h($row['first_name'] . ' ' . $row['surname']) ?>">
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
                            <td><?= h(formatRoleLabel((string) ($row['staff_role'] ?? ''))) ?></td>
                            <td>
                                <?php if ($pendingListMode || $flatEventList): ?>
                                    <?= h((string) ($row['event_name'] ?? '—')) ?>
                                <?php else: ?>
                                    <?= $shiftCount === 1 ? '1 shift' : $shiftCount . ' shifts' ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= h($primaryStatus) ?>" title="<?= h($statusSummary) ?>"><?= h($statusSummary) ?></span>
                            </td>
                            <td><?= h(formatSystemDateTime((string) ($row['last_registered'] ?? $row['created_at']), $pdo)) ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="view-staff.php?id=<?= $viewId ?>" class="btn btn--small btn--secondary">Review</a>
                                    <?php if ($editStaffId > 0): ?>
                                        <a href="staff-edit.php?id=<?= $editStaffId ?>" class="btn btn--small btn--secondary">Edit profile</a>
                                    <?php endif; ?>
                                    <?php if ($pendingListMode && $primaryStatus === 'pending'): ?>
                                        <form method="post" action="update-status.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="id" value="<?= $viewId ?>">
                                            <input type="hidden" name="status" value="approved">
                                            <input type="hidden" name="redirect" value="<?= h('staff.php?' . ($queryString !== '' ? $queryString . '&' : '') . 'page=' . (int) $page) ?>">
                                            <button type="submit" class="btn btn--small btn--success">Approve</button>
                                        </form>
                                        <form method="post" action="update-status.php" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="id" value="<?= $viewId ?>">
                                            <input type="hidden" name="status" value="rejected">
                                            <input type="hidden" name="redirect" value="<?= h('staff.php?' . ($queryString !== '' ? $queryString . '&' : '') . 'page=' . (int) $page) ?>">
                                            <button type="submit" class="btn btn--small btn--danger">Decline</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php renderAdminPagination($page, $total, 'staff.php', $paginationQuery, $listPerPage); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
