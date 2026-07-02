<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('staff');

$pdo = getDB();
$staffPortalUrl = getStaffPortalUrl($pdo);

$profileFilter = trim((string) ($_GET['profile'] ?? ''));
if (!in_array($profileFilter, ['complete', 'incomplete'], true)) {
    $profileFilter = '';
}

$filters = [
    'q'           => trim((string) ($_GET['q'] ?? '')),
    'role'        => trim((string) ($_GET['role'] ?? '')),
    'blacklisted' => isset($_GET['blacklisted']) && $_GET['blacklisted'] !== ''
        ? (bool) (int) $_GET['blacklisted']
        : null,
    'profile'     => $profileFilter,
];

$page       = adminListPage();
$perPage    = adminListPerPage();
$offset     = adminListOffset($page);
$totalStaff = countStaffDirectory($pdo, $filters);
$staffList  = getStaffWithFilters($pdo, $filters, $perPage, $offset);
$bulkCount  = count(getStaffIdsForProfileLinkBulk($pdo, $filters));

$paginationQuery = array_filter([
    'q'           => $filters['q'] !== '' ? $filters['q'] : null,
    'role'        => $filters['role'] !== '' ? $filters['role'] : null,
    'blacklisted' => $filters['blacklisted'] !== null ? (int) $filters['blacklisted'] : null,
    'profile'     => $filters['profile'] !== '' ? $filters['profile'] : null,
]);

$flash = getAdminFlash();

$pageTitle = 'Staff Directory';
$activePage = 'staff-directory';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card staff-dir-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Directory</h2>
            <p class="card__subtitle">One row per person. Shift counts open that person&apos;s registrations only.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <?php if ($bulkCount > 0): ?>
                <form method="post" action="staff-send-profile-link.php" class="staff-dir-bulk-form" onsubmit="return confirm('Send profile update link to <?= (int) $bulkCount ?> registered staff? Each person gets one email with sign-in links (not an approval email).');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="bulk_all" value="1">
                    <input type="hidden" name="redirect" value="<?= h('staff-directory.php' . ($paginationQuery !== [] ? '?' . http_build_query($paginationQuery) : '')) ?>">
                    <input type="hidden" name="filter_q" value="<?= h($filters['q']) ?>">
                    <input type="hidden" name="filter_role" value="<?= h($filters['role']) ?>">
                    <?php if ($filters['blacklisted'] !== null): ?>
                        <input type="hidden" name="filter_blacklisted" value="<?= (int) $filters['blacklisted'] ?>">
                    <?php endif; ?>
                    <?php if ($filters['profile'] !== ''): ?>
                        <input type="hidden" name="filter_profile" value="<?= h($filters['profile']) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn--primary">Email profile link to all (<?= (int) $bulkCount ?>)</button>
                </form>
            <?php endif; ?>
            <a href="staff.php" class="btn btn--secondary">← Registrations</a>
        </div>
    </div>

    <details class="staff-dir-email-preview">
        <summary>What does the bulk profile-link email say?</summary>
        <div class="staff-dir-email-preview__body">
            <p><strong>Subject:</strong> <?= h($siteName) ?> — complete your staff profile</p>
            <pre class="staff-dir-email-preview__sample">Dear [First name],

Please update your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.

Option 1 — sign in with email and date of birth:
<?= h($staffPortalUrl) ?>


Option 2 — use your personal link:
[Unique link per person]

This is not an approval email.</pre>
            <p class="form-hint">Bulk send emails every registered staff member matching your filters (active accounts only). Blacklisted staff are skipped.</p>
        </div>
    </details>

    <div class="staff-dir-portal-banner">
        <div class="staff-dir-portal-banner__text">
            <strong>Link for all staff to update their profile</strong>
            <span class="form-hint">Email + date of birth — PSA photos, bank details, address</span>
        </div>
        <div class="copy-field staff-dir-portal-banner__copy">
            <input type="text" id="staff-portal-public-url" class="form-input copy-field__input" value="<?= h($staffPortalUrl) ?>" readonly>
            <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-portal-public-url">Copy</button>
        </div>
    </div>

    <form method="get" class="filter-bar filter-bar--compact">
        <div class="filter-bar__group">
            <input type="search" name="q" class="form-input" placeholder="Search name, email, mobile" value="<?= h($filters['q']) ?>">
        </div>
        <div class="filter-bar__group">
            <select name="role" class="form-select">
                <option value="">All roles</option>
                <option value="dsp" <?= $filters['role'] === 'dsp' ? ' selected' : '' ?>>DSP</option>
                <option value="static" <?= $filters['role'] === 'static' ? ' selected' : '' ?>>Static</option>
                <option value="steward" <?= $filters['role'] === 'steward' ? ' selected' : '' ?>>Steward</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select name="profile" class="form-select">
                <option value="">All profiles</option>
                <option value="complete" <?= $filters['profile'] === 'complete' ? ' selected' : '' ?>>Profile complete</option>
                <option value="incomplete" <?= $filters['profile'] === 'incomplete' ? ' selected' : '' ?>>Profile incomplete</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select name="blacklisted" class="form-select">
                <option value="">All accounts</option>
                <option value="0" <?= $filters['blacklisted'] === false ? ' selected' : '' ?>>Active</option>
                <option value="1" <?= $filters['blacklisted'] === true ? ' selected' : '' ?>>Blacklisted</option>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="staff-directory.php" class="btn btn--secondary">Clear</a>
        </div>
    </form>

    <?php if ($staffList === []): ?>
        <p class="form-hint">
            <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['profile'] !== '' || $filters['blacklisted'] !== null): ?>
                No staff members match your filters.
            <?php else: ?>
                No staff members found. Run staff table migrations if this is a new install.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="table-wrap table-wrap--fit">
            <table class="data-table data-table--staff-dir">
                <colgroup>
                    <col class="col-name">
                    <col class="col-email">
                    <col class="col-profile">
                    <col class="col-shifts">
                    <col class="col-account">
                    <col class="col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Profile</th>
                        <th>Shifts</th>
                        <th>Account</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffList as $staff): ?>
                        <?php
                        $email = (string) $staff['email'];
                        $profileOk = isStaffOnboardingComplete($staff);
                        $name = trim((string) ($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? ''));
                        $reg = (int) ($staff['registration_count'] ?? 0);
                        $pen = (int) ($staff['pending_count'] ?? 0);
                        $app = (int) ($staff['approved_count'] ?? 0);
                        ?>
                        <tr>
                            <td class="staff-dir-name">
                                <strong><?= h($name) ?></strong>
                                <span class="staff-dir-meta"><?= h((string) ($staff['mobile'] ?? '')) ?> · <?= h(formatStaffRoleLabel((string) $staff['staff_role'])) ?></span>
                            </td>
                            <td class="staff-dir-email">
                                <a href="<?= h(buildStaffRegistrationsAdminUrl($email)) ?>"><?= h($email) ?></a>
                            </td>
                            <td>
                                <?php if ($profileOk): ?>
                                    <span class="badge badge--approved">OK</span>
                                <?php else: ?>
                                    <span class="badge badge--pending">Incomplete</span>
                                <?php endif; ?>
                            </td>
                            <td class="staff-dir-shifts">
                                <a href="<?= h(buildStaffRegistrationsAdminUrl($email)) ?>" title="All registrations"><?= $reg ?></a>
                                <span class="staff-dir-shifts__sep">/</span>
                                <a href="<?= h(buildStaffRegistrationsAdminUrl($email, 'pending')) ?>" title="Pending"><?= $pen ?></a>
                                <span class="staff-dir-shifts__sep">/</span>
                                <a href="<?= h(buildStaffRegistrationsAdminUrl($email, 'approved')) ?>" title="Approved"><?= $app ?></a>
                                <span class="staff-dir-shifts__hint">all / pen / app</span>
                            </td>
                            <td>
                                <?php if ((int) ($staff['is_blacklisted'] ?? 0) === 1): ?>
                                    <span class="badge badge--rejected">Blocked</span>
                                <?php else: ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-actions">
                                <div class="table-actions table-actions--stack">
                                    <a href="staff-edit.php?id=<?= (int) $staff['id'] ?>" class="btn btn--small btn--primary">Edit</a>
                                    <a href="staff-inbox-thread.php?staff_id=<?= (int) $staff['id'] ?>" class="btn btn--small btn--secondary">Message</a>
                                    <form method="post" action="staff-send-profile-link.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>">
                                        <input type="hidden" name="single_redirect" value="staff-directory.php<?= $paginationQuery !== [] ? '?' . h(http_build_query(array_merge($paginationQuery, ['page' => $page > 1 ? $page : null]))) : ($page > 1 ? '?page=' . $page : '') ?>">
                                        <button type="submit" class="btn btn--small btn--secondary">Send link</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderAdminPagination($page, $totalStaff, 'staff-directory.php', $paginationQuery); ?>
        <p class="form-hint">Shift columns: all / pending / approved</p>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.getAttribute('data-copy-target'));
        if (!el) return;
        el.select();
        el.setSelectionRange(0, 99999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value).catch(function () {});
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
