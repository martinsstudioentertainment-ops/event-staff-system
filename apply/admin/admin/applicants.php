<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/secure-layout.php';

$error       = '';
$applicants  = [];
$totalCount  = $pendingCount = $approvedCount = $rejectedCount = 0;
$search      = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$eventPdo = getMainAdminPdo();
if (!$eventPdo instanceof PDO) {
    $error = 'Main ERP database is not connected. Copy config/eventstaff-database.example.php to eventstaff-database.php on the server.';
}

try {
    if ($error !== '' || !$eventPdo instanceof PDO) {
        throw new RuntimeException($error !== '' ? $error : 'Database unavailable.');
    }

    $totalCount    = (int) $eventPdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();
    $pendingCount  = (int) $eventPdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'pending'")->fetchColumn();
    $approvedCount = (int) $eventPdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'approved'")->fetchColumn();
    $rejectedCount = (int) $eventPdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'rejected'")->fetchColumn();

    $sql = "
        SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role,
               sr.status, sr.created_at, e.name AS event_name, e.event_date
        FROM staff_registrations sr
        LEFT JOIN events e ON e.id = sr.event_id
        WHERE 1=1
    ";
    $params = [];

    if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $sql .= ' AND sr.status = :status';
        $params['status'] = $statusFilter;
    }

    if ($search !== '') {
        $sql .= ' AND (sr.first_name LIKE :q1 OR sr.surname LIKE :q2 OR sr.email LIKE :q3 OR sr.mobile LIKE :q4 OR e.name LIKE :q5)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
        $params['q4'] = $like;
        $params['q5'] = $like;
    }

    $sql .= ' ORDER BY sr.created_at DESC LIMIT 150';

    $stmt = $eventPdo->prepare($sql);
    $stmt->execute($params);
    $applicants = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load main registrations: ' . $e->getMessage();
}

secure_layout_start('Main registrations', 'applicants', 'Read-only view of event registrations from the main ERP database.');

if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));">
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Total</div>
        <div class="secure-stat__value"><?= $totalCount ?></div>
    </div>
    <div class="secure-stat secure-stat--warn">
        <div class="secure-stat__label">Pending</div>
        <div class="secure-stat__value"><?= $pendingCount ?></div>
    </div>
    <div class="secure-stat secure-stat--ok">
        <div class="secure-stat__label">Approved</div>
        <div class="secure-stat__value"><?= $approvedCount ?></div>
    </div>
    <div class="secure-stat secure-stat--danger">
        <div class="secure-stat__label">Rejected</div>
        <div class="secure-stat__value"><?= $rejectedCount ?></div>
    </div>
</div>

<div class="secure-card">
    <form method="get" class="secure-grid" style="align-items:flex-end;">
        <div class="secure-grid__col secure-field" style="margin:0;">
            <label class="secure-label" for="search">Search</label>
            <input class="secure-input" type="search" id="search" name="search" value="<?= secure_h($search) ?>" placeholder="Name, email, event…">
        </div>
        <div class="secure-field" style="min-width:160px;margin:0;">
            <label class="secure-label" for="status">Status</label>
            <select class="secure-select" id="status" name="status">
                <option value="">All</option>
                <option value="pending"<?= $statusFilter === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="approved"<?= $statusFilter === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="rejected"<?= $statusFilter === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <button type="submit" class="secure-btn secure-btn--primary">Filter</button>
        <?php if ($search !== '' || $statusFilter !== ''): ?>
            <a href="applicants.php" class="secure-btn secure-btn--ghost">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="secure-card secure-card--danger-top">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem;">
        <h2 style="margin:0;font-size:1rem;">Recent registrations</h2>
        <a href="import-applicants.php" class="secure-btn secure-btn--purple">Import to apply vault</a>
    </div>

    <div class="secure-table-wrap">
        <table class="secure-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Mobile</th>
                    <th>Role</th>
                    <th>Event</th>
                    <th>Status</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($applicants === []): ?>
                    <tr><td colspan="8" style="color:var(--secure-muted);">No registrations found.</td></tr>
                <?php else: ?>
                    <?php foreach ($applicants as $row): ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td><?= secure_h(trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''))) ?></td>
                            <td><?= secure_h((string) ($row['email'] ?? '')) ?></td>
                            <td><?= secure_h((string) ($row['mobile'] ?? '')) ?></td>
                            <td><?= secure_h(strtoupper((string) ($row['staff_role'] ?? ''))) ?></td>
                            <td>
                                <?= secure_h((string) ($row['event_name'] ?? '—')) ?>
                                <?php if (!empty($row['event_date'])): ?>
                                    <span style="color:var(--secure-muted);font-size:0.75rem;"> · <?= secure_h((string) $row['event_date']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= secure_status_badge((string) ($row['status'] ?? 'pending')) ?></td>
                            <td><?= !empty($row['created_at']) ? secure_h((string) $row['created_at']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="secure-card">
    <p style="margin:0;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        This list is read-only. Use <strong style="color:var(--secure-text);font-weight:600;">Import to apply vault</strong> to copy approved applicants into the local staff vault for PSA verification and payroll.
    </p>
</div>

<?php secure_layout_end(); ?>
