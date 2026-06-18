<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/secure-pagination.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/psa-sync.php';

$error         = '';
$staff         = [];
$search        = trim($_GET['search'] ?? '');
$statusFilter  = trim($_GET['status'] ?? '');
$expiryFilter  = strtolower(trim($_GET['psa_expiry'] ?? ''));
$page          = secureListPage();
$perPage       = secureListPerPage();
$offset        = secureListOffset($page, $perPage);
$filteredTotal = 0;

$allowedStatuses = ['Incomplete', 'Pending Review', 'Verified', 'Expired PSA'];
$allowedExpiryFilters = ['expired', 'expiring', 'valid', 'no_date'];

if (!in_array($expiryFilter, $allowedExpiryFilters, true)) {
    $expiryFilter = '';
}

/**
 * SQL fragment for PSA expiry bucket (matches secure_psa_status()).
 */
function psa_compliance_expiry_sql(string $filter): string
{
    return match ($filter) {
        'expired' => " AND psa_expiry_date IS NOT NULL AND psa_expiry_date NOT IN ('', '0000-00-00') AND psa_expiry_date < CURDATE()",
        'expiring' => " AND psa_expiry_date IS NOT NULL AND psa_expiry_date NOT IN ('', '0000-00-00') AND psa_expiry_date >= CURDATE() AND psa_expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        'valid' => " AND psa_expiry_date IS NOT NULL AND psa_expiry_date NOT IN ('', '0000-00-00') AND psa_expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        'no_date' => " AND (psa_expiry_date IS NULL OR psa_expiry_date IN ('', '0000-00-00'))",
        default => '',
    };
}

function psa_compliance_filter_href(string $expiry, string $currentExpiry, string $search, string $status): string
{
    $nextExpiry = ($currentExpiry === $expiry) ? '' : $expiry;
    $query      = array_filter([
        'psa_expiry' => $nextExpiry !== '' ? $nextExpiry : null,
        'search'     => $search !== '' ? $search : null,
        'status'     => $status !== '' ? $status : null,
    ]);

    return 'psa-compliance.php' . ($query !== [] ? '?' . http_build_query($query) : '') . '#psa-records';
}

try {
    $eventPdo = getMainAdminPdo();
    try {
        apply_auto_refresh_vault_profile_statuses($pdo, $eventPdo);
    } catch (Throwable $refreshErr) {
        error_log('[ApplySync] psa-compliance refresh: ' . $refreshErr->getMessage());
    }

    $where  = ' WHERE 1=1';
    $params = [];

    if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
        $where .= ' AND profile_status = :status';
        $params['status'] = $statusFilter;
    }

    if ($search !== '') {
        $where .= ' AND (first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3 OR psa_licence LIKE :q4)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
        $params['q4'] = $like;
    }

    if ($expiryFilter !== '') {
        $where .= psa_compliance_expiry_sql($expiryFilter);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_master' . $where);
    $countStmt->execute($params);
    $filteredTotal = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT id, first_name, last_name, email, psa_licence, psa_expiry_date,
               profile_status, verified_by, verified_at, verification_notes
        FROM staff_master
        {$where}
        ORDER BY profile_status DESC, id ASC
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $staff = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load staff data: ' . $e->getMessage();
}

$expiredCount = $expiringCount = $validCount = $noDateCount = 0;
try {
    $allForStats = $pdo->query('SELECT psa_expiry_date FROM staff_master')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allForStats as $row) {
        $psa = secure_psa_status($row['psa_expiry_date'] ?? null);
        if ($psa === 'Expired') {
            ++$expiredCount;
        } elseif ($psa === 'Expiring Soon') {
            ++$expiringCount;
        } elseif ($psa === 'Valid') {
            ++$validCount;
        } else {
            ++$noDateCount;
        }
    }
} catch (Throwable $e) {
    // stats optional
}

$paginationQuery = array_filter([
    'search'     => $search !== '' ? $search : null,
    'status'     => $statusFilter !== '' ? $statusFilter : null,
    'psa_expiry' => $expiryFilter !== '' ? $expiryFilter : null,
]);

secure_layout_start('PSA compliance', 'psa', 'Monitor licence expiry and verification status across the staff vault.');

if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <a class="secure-stat secure-stat--danger<?= $expiryFilter === 'expired' ? ' secure-stat--active' : '' ?>" href="<?= secure_h(psa_compliance_filter_href('expired', $expiryFilter, $search, $statusFilter)) ?>" title="Show staff with expired PSA">
        <div class="secure-stat__label">Expired</div>
        <div class="secure-stat__value"><?= $expiredCount ?></div>
    </a>
    <a class="secure-stat secure-stat--warn<?= $expiryFilter === 'expiring' ? ' secure-stat--active' : '' ?>" href="<?= secure_h(psa_compliance_filter_href('expiring', $expiryFilter, $search, $statusFilter)) ?>" title="Show staff expiring within 30 days">
        <div class="secure-stat__label">Expiring soon</div>
        <div class="secure-stat__value"><?= $expiringCount ?></div>
    </a>
    <a class="secure-stat secure-stat--ok<?= $expiryFilter === 'valid' ? ' secure-stat--active' : '' ?>" href="<?= secure_h(psa_compliance_filter_href('valid', $expiryFilter, $search, $statusFilter)) ?>" title="Show staff with valid PSA expiry">
        <div class="secure-stat__label">Valid</div>
        <div class="secure-stat__value"><?= $validCount ?></div>
    </a>
    <a class="secure-stat secure-stat--muted<?= $expiryFilter === 'no_date' ? ' secure-stat--active' : '' ?>" href="<?= secure_h(psa_compliance_filter_href('no_date', $expiryFilter, $search, $statusFilter)) ?>" title="Show staff with no PSA expiry date">
        <div class="secure-stat__label">No expiry date</div>
        <div class="secure-stat__value"><?= $noDateCount ?></div>
    </a>
    <a class="secure-stat secure-stat--muted<?= $expiryFilter === '' && ($search !== '' || $statusFilter !== '') ? ' secure-stat--active' : '' ?>" href="psa-compliance.php#psa-records" title="Scroll to filtered list below">
        <div class="secure-stat__label">Matching filter</div>
        <div class="secure-stat__value"><?= $filteredTotal ?></div>
    </a>
</div>

<div class="secure-card" style="margin-bottom:1rem;">
    <p style="margin:0 0 0.75rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        <strong style="color:var(--secure-text);">Verified</strong> is set automatically when PSA, IBAN, PPS, and contact fields are complete with a valid licence expiry.
        Staff must be approved on main to appear here. Run sync to refresh all rows.
    </p>
    <a href="sync-sheets.php?action=run" class="secure-btn secure-btn--primary">Sync from main ERP now</a>
</div>

<div class="secure-card secure-card--danger-top">
    <form method="get" class="secure-grid" style="align-items:flex-end;margin-bottom:1rem;">
        <div class="secure-grid__col secure-field" style="margin:0;">
            <label class="secure-label" for="search">Filter vault</label>
            <input class="secure-input" type="search" id="search" name="search" placeholder="Name, email, or PSA…" value="<?= secure_h($search) ?>">
        </div>
        <div class="secure-field" style="min-width:180px;margin:0;">
            <label class="secure-label" for="psa_expiry">PSA expiry</label>
            <select class="secure-select" id="psa_expiry" name="psa_expiry">
                <option value="">All expiry buckets</option>
                <option value="expired"<?= $expiryFilter === 'expired' ? ' selected' : '' ?>>Expired</option>
                <option value="expiring"<?= $expiryFilter === 'expiring' ? ' selected' : '' ?>>Expiring soon (30 days)</option>
                <option value="valid"<?= $expiryFilter === 'valid' ? ' selected' : '' ?>>Valid</option>
                <option value="no_date"<?= $expiryFilter === 'no_date' ? ' selected' : '' ?>>No expiry date</option>
            </select>
        </div>
        <div class="secure-field" style="min-width:180px;margin:0;">
            <label class="secure-label" for="status">Profile status</label>
            <select class="secure-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($allowedStatuses as $opt): ?>
                    <option value="<?= secure_h($opt) ?>"<?= $statusFilter === $opt ? ' selected' : '' ?>><?= secure_h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($perPage !== SECURE_LIST_DEFAULT_PER_PAGE): ?>
            <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
        <?php endif; ?>
        <button type="submit" class="secure-btn secure-btn--primary">Apply</button>
        <?php if ($search !== '' || $statusFilter !== '' || $expiryFilter !== ''): ?>
            <a href="psa-compliance.php" class="secure-btn secure-btn--ghost">Clear</a>
        <?php endif; ?>
    </form>

    <div class="secure-list-toolbar" id="psa-records">
        <h2 style="margin:0;font-size:1rem;">
            PSA records
            <?php if ($expiryFilter !== ''): ?>
                <span style="font-weight:500;color:var(--secure-muted);font-size:0.875rem;">
                    — <?= secure_h(match ($expiryFilter) {
                        'expired' => 'Expired',
                        'expiring' => 'Expiring soon',
                        'valid' => 'Valid',
                        'no_date' => 'No expiry date',
                        default => '',
                    }) ?>
                </span>
            <?php endif; ?>
        </h2>
        <?php renderSecurePerPageControl('psa-compliance.php', $paginationQuery); ?>
    </div>

    <div class="secure-table-wrap">
        <table class="secure-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>PSA</th>
                    <th>Expiry</th>
                    <th>PSA status</th>
                    <th>Profile</th>
                    <th>Verified by</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($staff === []): ?>
                    <tr><td colspan="9" style="color:var(--secure-muted);">No staff found.</td></tr>
                <?php else: ?>
                    <?php foreach ($staff as $row): ?>
                        <?php
                        $profileStatus = (string) ($row['profile_status'] ?? 'Incomplete');
                        $expiryRaw     = $row['psa_expiry_date'] ?? null;
                        $expiryLabel   = ($expiryRaw && $expiryRaw !== '0000-00-00') ? (string) $expiryRaw : 'Not set';
                        ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= secure_h(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></td>
                            <td><?= secure_h((string) ($row['email'] ?? '')) ?></td>
                            <td><?= secure_h((string) ($row['psa_licence'] ?? '')) ?></td>
                            <td><?= secure_h($expiryLabel) ?></td>
                            <td><?= secure_psa_badge($expiryRaw) ?></td>
                            <td><?= secure_status_badge($profileStatus) ?></td>
                            <td><?= $row['verified_by'] ? secure_h((string) $row['verified_by']) : '—' ?></td>
                            <td><a class="secure-btn secure-btn--ghost" style="padding:0.35rem 0.65rem;font-size:0.75rem;" href="show-blade.php?id=<?= (int) $row['id'] ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderSecurePagination($page, $filteredTotal, 'psa-compliance.php', $paginationQuery); ?>
</div>

<?php secure_layout_end(); ?>
