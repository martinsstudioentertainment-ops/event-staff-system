<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';

$search = trim($_GET['search'] ?? '');

$sql = 'SELECT * FROM staff_master';
$params = [];

if ($search !== '') {
    $sql .= " WHERE first_name LIKE :search OR last_name LIKE :search OR email LIKE :search OR phone LIKE :search OR psa_licence LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

$sql .= ' ORDER BY id DESC LIMIT 250';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

$totalStaff = (int) $pdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();

secure_layout_start('Staff directory', 'staff', (string) $totalStaff . ' records in secure vault. Search and open profiles to verify.');

?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Listed</div>
        <div class="secure-stat__value"><?= count($staff) ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Total vault</div>
        <div class="secure-stat__value"><?= $totalStaff ?></div>
    </div>
</div>

<div class="secure-card">
    <form method="get" class="secure-field" style="margin-bottom:0;">
        <label class="secure-label" for="search">Search staff vault</label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <input class="secure-input" type="search" id="search" name="search" placeholder="Name, email, phone, PSA…" value="<?= secure_h($search) ?>" style="flex:1;min-width:200px;">
            <button type="submit" class="secure-btn secure-btn--primary">Search</button>
            <?php if ($search !== ''): ?>
                <a href="staff-list.php" class="secure-btn secure-btn--ghost">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="secure-card secure-card--danger-top">
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
</div>

<?php secure_layout_end(); ?>
