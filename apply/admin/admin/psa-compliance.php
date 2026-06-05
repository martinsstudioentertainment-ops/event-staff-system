<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/psa-sync.php';

$error = '';
$staff = [];

try {
    $eventPdo = getMainAdminPdo();
    try {
        apply_auto_refresh_vault_profile_statuses($pdo, $eventPdo);
    } catch (Throwable $refreshErr) {
        error_log('[ApplySync] psa-compliance refresh: ' . $refreshErr->getMessage());
    }

    $stmt  = $pdo->query("
        SELECT id, first_name, last_name, email, psa_licence, psa_expiry_date,
               profile_status, verified_by, verified_at, verification_notes
        FROM staff_master
        ORDER BY profile_status DESC, id ASC
    ");
    $staff = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load staff data: ' . $e->getMessage();
}

$expiredCount = $expiringCount = $validCount = $noDateCount = 0;
foreach ($staff as $row) {
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

secure_layout_start('PSA compliance', 'psa', 'Monitor licence expiry and verification status across the staff vault.');

if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <div class="secure-stat secure-stat--danger">
        <div class="secure-stat__label">Expired</div>
        <div class="secure-stat__value"><?= $expiredCount ?></div>
    </div>
    <div class="secure-stat secure-stat--warn">
        <div class="secure-stat__label">Expiring soon</div>
        <div class="secure-stat__value"><?= $expiringCount ?></div>
    </div>
    <div class="secure-stat secure-stat--ok">
        <div class="secure-stat__label">Valid</div>
        <div class="secure-stat__value"><?= $validCount ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">No expiry date</div>
        <div class="secure-stat__value"><?= $noDateCount ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Total records</div>
        <div class="secure-stat__value"><?= count($staff) ?></div>
    </div>
</div>

<div class="secure-card" style="margin-bottom:1rem;">
    <p style="margin:0 0 0.75rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        <strong style="color:var(--secure-text);">Verified</strong> is set automatically when PSA, IBAN, PPS, and contact fields are complete with a valid licence expiry.
        Staff must be approved on main to appear here. Run sync to refresh all rows.
    </p>
    <a href="sync-sheets.php?action=run" class="secure-btn secure-btn--primary">Sync from main ERP now</a>
</div>

<div class="secure-card secure-card--danger-top">
    <div style="display:flex;flex-wrap:wrap;gap:0.75rem;margin-bottom:1rem;">
        <div class="secure-field" style="flex:1;min-width:200px;margin:0;">
            <label class="secure-label" for="search">Filter vault</label>
            <input class="secure-input" type="search" id="search" placeholder="Name, email, or PSA…">
        </div>
        <div class="secure-field" style="min-width:180px;margin:0;">
            <label class="secure-label" for="statusFilter">Profile status</label>
            <select class="secure-select" id="statusFilter">
                <option value="">All statuses</option>
                <option value="Incomplete">Incomplete</option>
                <option value="Pending Review">Pending Review</option>
                <option value="Verified">Verified</option>
                <option value="Expired PSA">Expired PSA</option>
            </select>
        </div>
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
            <tbody id="tableBody">
                <?php foreach ($staff as $row): ?>
                    <?php
                    $profileStatus = (string) ($row['profile_status'] ?? 'Incomplete');
                    $expiryRaw     = $row['psa_expiry_date'] ?? null;
                    $expiryLabel   = ($expiryRaw && $expiryRaw !== '0000-00-00') ? (string) $expiryRaw : 'Not set';
                    ?>
                    <tr class="staff-row" data-status="<?= secure_h($profileStatus) ?>">
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
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var search = document.getElementById('search');
    var statusFilter = document.getElementById('statusFilter');
    if (!search || !statusFilter) return;

    function filterTable() {
        var term = search.value.toLowerCase();
        var status = statusFilter.value;
        document.querySelectorAll('.staff-row').forEach(function (row) {
            var text = row.textContent.toLowerCase();
            var rowStatus = row.getAttribute('data-status') || '';
            row.style.display = text.includes(term) && (!status || rowStatus === status) ? '' : 'none';
        });
    }

    search.addEventListener('keyup', filterTable);
    statusFilter.addEventListener('change', filterTable);
})();
</script>

<?php secure_layout_end(); ?>
