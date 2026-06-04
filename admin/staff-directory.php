<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/site-urls.php';

requireAdminCapability('staff');

$pdo = getDB();
$staffPortalUrl = getStaffPortalUrl($pdo);

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'role' => trim((string) ($_GET['role'] ?? '')),
    'blacklisted' => isset($_GET['blacklisted']) ? (bool) $_GET['blacklisted'] : null,
];

$staffList = $filters['q'] !== '' || $filters['role'] !== '' || $filters['blacklisted'] !== null
    ? getStaffWithFilters($pdo, $filters)
    : getAllStaff($pdo);

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
        <a href="staff.php" class="btn btn--secondary">← Registrations</a>
    </div>

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
            <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['blacklisted'] !== null): ?>
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
                            <td class="cell-ellipsis" title="<?= h($email) ?>">
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
                                    <form method="post" action="staff-send-profile-link.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="staff_id" value="<?= (int) $staff['id'] ?>">
                                        <button type="submit" class="btn btn--small btn--secondary">Send link</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="form-hint">Showing <?= count($staffList) ?> staff · shift columns: all / pending / approved</p>
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
