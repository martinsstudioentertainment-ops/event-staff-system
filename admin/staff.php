<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/psa-licence-verify.php';
require_once __DIR__ . '/../includes/site-urls.php';

requireAdminCapability('staff');

$pdo = getDB();
require_once __DIR__ . '/../includes/staff-psa.php';
ensureStaffPsaSchema($pdo);

$tabRaw = strtolower(trim((string) ($_GET['tab'] ?? '')));
$legacySignupParams = isset($_GET['status']) || isset($_GET['event_id'])
    || (isset($_GET['per_page']) && !isset($_GET['tab']));
if ($tabRaw === 'signups' || $tabRaw === 'queue' || $tabRaw === 'registrations') {
    $staffTab = 'signups';
} elseif ($tabRaw === 'people' || $tabRaw === 'directory') {
    $staffTab = 'people';
} elseif ($legacySignupParams) {
    $staffTab = 'signups';
} else {
    $staffTab = 'people';
}

$pendingBadgeCount = 0;
try {
    $pendingBadgeCount = countPendingRegistrations($pdo);
} catch (Throwable $e) {
    $pendingBadgeCount = 0;
}

$flash = getAdminFlash();
$pageTitle = 'Staff';
$activePage = 'staff';

if ($staffTab === 'people') {
    $staffPortalUrl = getStaffPortalUrl($pdo);

    $profileFilter = trim((string) ($_GET['profile'] ?? ''));
    if (!in_array($profileFilter, ['complete', 'incomplete'], true)) {
        $profileFilter = '';
    }
    $psaFilter = trim((string) ($_GET['psa'] ?? ''));
    if (!in_array($psaFilter, ['verified', 'issue', 'missing', 'unchecked'], true)) {
        $psaFilter = '';
    }

    $peopleFilters = [
        'q'           => trim((string) ($_GET['q'] ?? '')),
        'role'        => trim((string) ($_GET['role'] ?? '')),
        'blacklisted' => isset($_GET['blacklisted']) && $_GET['blacklisted'] !== ''
            ? (bool) (int) $_GET['blacklisted']
            : null,
        'profile'     => $profileFilter,
        'psa'         => $psaFilter,
    ];

    $peoplePage    = adminListPage();
    $peoplePerPage = adminListPerPage();
    $peopleTotal   = countStaffDirectory($pdo, $peopleFilters);
    $peopleList    = getStaffWithFilters($pdo, $peopleFilters, $peoplePerPage, adminListOffset($peoplePage, $peoplePerPage));
    $peopleBulkCount = count(getStaffIdsForProfileLinkBulk($pdo, $peopleFilters));

    $peoplePaginationQuery = array_filter([
        'q'           => $peopleFilters['q'] !== '' ? $peopleFilters['q'] : null,
        'role'        => $peopleFilters['role'] !== '' ? $peopleFilters['role'] : null,
        'blacklisted' => $peopleFilters['blacklisted'] !== null ? (int) $peopleFilters['blacklisted'] : null,
        'profile'     => $peopleFilters['profile'] !== '' ? $peopleFilters['profile'] : null,
        'psa'         => $peopleFilters['psa'] !== '' ? $peopleFilters['psa'] : null,
    ], static fn ($v) => $v !== null && $v !== '');
} else {
    $filters         = getStaffFiltersFromRequest();
    $pendingListMode = ($filters['status'] === 'pending');
    $pendingDbCount  = 0;
    $listPerPage     = adminStaffListPerPageFromRequest();
    $flatEventList   = !$pendingListMode && (int) ($filters['event_id'] ?? 0) > 0;

    if ($pendingListMode) {
        $allPending     = fetchPendingRegistrations($pdo, 500);
        $filtered       = filterPendingRegistrationRows($allPending, $filters);
        $total          = count($filtered);
        $pendingDbCount = countPendingRegistrations($pdo);

        if ($total === 0 && $pendingDbCount > 0 && $filters['q'] === '' && $filters['role'] === '' && $filters['event_id'] === 0) {
            $filtered = $allPending;
            $total    = count($filtered);
        }

        $filtered = array_map(
            static fn (array $row): array => mergeRegistrationWithStaff($pdo, $row),
            $filtered
        );
        $filtered = sortStaffRowsByPsaVerifiedFirst($filtered, 'created_at');

        $totalPages = max(1, adminListTotalPages($total, $listPerPage));
        $page       = min(max(1, adminListPage()), $totalPages);
        $rows       = array_slice($filtered, adminListOffset($page, $listPerPage), $listPerPage);
    } elseif ($flatEventList) {
        $total = countStaffRegistrations($pdo, $filters);
        $page  = min(adminListPage(), adminListTotalPages($total, $listPerPage));
        $rows  = getStaffRegistrations($pdo, $filters, $listPerPage, adminListOffset($page, $listPerPage));
    } else {
        $total = countStaffListTotal($pdo, $filters);
        $page  = min(adminListPage(), adminListTotalPages($total, $listPerPage));
        $rows  = getUniqueStaffRegistrants($pdo, $filters, $listPerPage, adminListOffset($page, $listPerPage));
    }

    $pendingListGap = $pendingListMode && $pendingDbCount > 0 && $total === 0;
    $events = getEventsForFilter($pdo);

    $queryString = http_build_query(array_filter([
        'tab'      => 'signups',
        'q'        => $filters['q'] !== '' ? $filters['q'] : null,
        'status'   => $filters['status'] !== '' ? $filters['status'] : null,
        'role'     => $filters['role'] !== '' ? $filters['role'] : null,
        'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
        'per_page' => $listPerPage !== adminStaffListPerPage() ? $listPerPage : null,
    ]));

    $paginationQuery = array_filter([
        'tab'      => 'signups',
        'q'        => $filters['q'] !== '' ? $filters['q'] : null,
        'status'   => $filters['status'] !== '' ? $filters['status'] : null,
        'role'     => $filters['role'] !== '' ? $filters['role'] : null,
        'event_id' => $filters['event_id'] > 0 ? $filters['event_id'] : null,
        'per_page' => $listPerPage !== adminStaffListPerPage() ? $listPerPage : null,
    ]);
}

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<nav class="staff-hub-tabs" aria-label="Staff views">
    <a href="staff.php?tab=people" class="staff-hub-tabs__link<?= $staffTab === 'people' ? ' is-active' : '' ?>">People</a>
    <a href="staff.php?tab=signups<?= $pendingBadgeCount > 0 ? '&status=pending' : '' ?>" class="staff-hub-tabs__link<?= $staffTab === 'signups' ? ' is-active' : '' ?>">
        Signups
        <?php if ($pendingBadgeCount > 0): ?>
            <span class="badge badge--pending"><?= (int) $pendingBadgeCount ?></span>
        <?php endif; ?>
    </a>
</nav>

<style>
.staff-hub-tabs {
    display: flex;
    gap: 0.35rem;
    margin: 0 0 1rem;
    padding: 0.25rem;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.04);
    width: fit-content;
    max-width: 100%;
}
.staff-hub-tabs__link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.9rem;
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    font-weight: 600;
    font-size: 0.95rem;
}
.staff-hub-tabs__link.is-active {
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}
</style>

<?php
if ($staffTab === 'people') {
    include __DIR__ . '/../includes/admin/staff-hub-people.php';
} else {
    include __DIR__ . '/../includes/admin/staff-hub-signups.php';
}

include __DIR__ . '/../includes/admin/layout-bottom.php';
