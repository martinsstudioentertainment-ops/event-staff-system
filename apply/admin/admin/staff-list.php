<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/secure-pagination.php';

$search  = trim($_GET['search'] ?? '');
$page    = secureListPage();
$perPage = secureListPerPage();
$offset  = secureListOffset($page, $perPage);

$where  = '';
$params = [];
if ($search !== '') {
    $where  = ' WHERE first_name LIKE :q1 OR last_name LIKE :q2 OR email LIKE :q3 OR phone LIKE :q4 OR psa_licence LIKE :q5';
    $like = '%' . $search . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
    $params['q5'] = $like;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_master' . $where);
$countStmt->execute($params);
$filteredTotal = (int) $countStmt->fetchColumn();

$totalStaff = (int) $pdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();

$sql = 'SELECT * FROM staff_master' . $where . ' ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

$paginationQuery = array_filter([
    'search' => $search !== '' ? $search : null,
]);

secure_layout_start('Staff directory', 'staff', (string) $totalStaff . ' records in secure vault. Search and open profiles to verify.');

?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">This page</div>
        <div class="secure-stat__value"><?= count($staff) ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label"><?= $search !== '' ? 'Matching' : 'Total vault' ?></div>
        <div class="secure-stat__value"><?= $filteredTotal ?></div>
    </div>
</div>

<div class="secure-card">
    <form method="get" class="secure-field" style="margin-bottom:0;">
        <label class="secure-label" for="search">Search staff vault</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <input class="secure-input" type="search" id="search" name="search" placeholder="Name, email, phone, PSA…" value="<?= secure_h($search) ?>" style="flex:1;min-width:200px;">
            <?php if ($perPage !== SECURE_LIST_DEFAULT_PER_PAGE): ?>
                <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
            <?php endif; ?>
            <button type="submit" class="secure-btn secure-btn--primary">Search</button>
            <?php if ($search !== ''): ?>
                <a href="staff-list.php" class="secure-btn secure-btn--ghost">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="secure-card secure-card--danger-top">
    <div class="secure-list-toolbar">
        <h2 style="margin:0;font-size:1rem;">Staff vault</h2>
        <?php renderSecurePerPageControl('staff-list.php', $paginationQuery); ?>
    </div>

    <div class="secure-table-wrap">
        <table class="secure-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>PSA</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($staff === []): ?>
                    <tr><td colspan="7" style="color:var(--secure-muted);">No staff found.</td></tr>
                <?php else: ?>
                    <?php foreach ($staff as $row): ?>
                        <?php $status = (string) ($row['profile_status'] ?? 'Incomplete'); ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= secure_h(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?></td>
                            <td><?= secure_h((string) ($row['email'] ?? '')) ?></td>
                            <td><?= secure_h((string) ($row['phone'] ?? '')) ?></td>
                            <td><?= secure_h((string) ($row['psa_licence'] ?? '')) ?></td>
                            <td><?= secure_status_badge($status) ?></td>
                            <td><a class="secure-btn secure-btn--ghost" style="padding:0.35rem 0.65rem;font-size:0.75rem;" href="view-staff.php?id=<?= (int) $row['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php renderSecurePagination($page, $filteredTotal, 'staff-list.php', $paginationQuery); ?>
</div>

<?php secure_layout_end(); ?>
