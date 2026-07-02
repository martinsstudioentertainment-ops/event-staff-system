<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/psa-sync.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    die('Invalid staff ID.');
}

$eventPdo = getMainAdminPdo();
apply_auto_refresh_vault_profile_statuses($pdo, $eventPdo, $id);

$stmt = $pdo->prepare('SELECT * FROM staff_master WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$staff = $stmt->fetch();

if (!$staff) {
    http_response_code(404);
    die('Staff record not found.');
}

$status    = (string) ($staff['profile_status'] ?? 'Incomplete');
$fullName  = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
$verifiedBy = trim((string) ($staff['verified_by'] ?? ''));
$verifiedAt = $staff['verified_at'] ?? null;

secure_layout_start('Staff profile', 'staff', $fullName . ' · vault record #' . $id);
?>

<div class="secure-card" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;">
    <div>
        <div style="margin-bottom:0.35rem;"><?= secure_status_badge($status) ?></div>
        <div style="font-size:1.1rem;font-weight:600;"><?= secure_h($fullName) ?></div>
    </div>
    <div class="secure-actions" style="margin:0;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
        <a href="show-blade.php?id=<?= $id ?>" class="secure-btn secure-btn--warn">Compliance review</a>
        <a href="edit-staff.php?id=<?= $id ?>" class="secure-btn secure-btn--primary">Edit record</a>
        <a href="staff-list.php" class="secure-btn secure-btn--ghost">Back to directory</a>
    </div>
</div>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Personal &amp; contact</h2>
    <div class="secure-dl">
        <div class="secure-dl__row"><div class="secure-dl__label">ID</div><div class="secure-dl__value"><?= $id ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Email</div><div class="secure-dl__value"><?= secure_h((string) ($staff['email'] ?? '')) ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Phone</div><div class="secure-dl__value"><?= secure_h((string) ($staff['phone'] ?? '')) ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Date of birth</div><div class="secure-dl__value"><?= secure_h(secure_format_date((string) ($staff['date_of_birth'] ?? '')) ?: '—') ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Gender</div><div class="secure-dl__value"><?= secure_h((string) ($staff['gender'] ?? '—')) ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Address</div><div class="secure-dl__value"><?= nl2br(secure_h((string) ($staff['address'] ?? ''))) ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">Postcode</div><div class="secure-dl__value"><?= secure_h((string) ($staff['postcode'] ?? '')) ?></div></div>
    </div>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">PSA &amp; payroll (restricted)</h2>
    <div class="secure-dl">
        <div class="secure-dl__row"><div class="secure-dl__label">PSA licence</div><div class="secure-dl__value"><?= secure_h((string) ($staff['psa_licence'] ?? '—')) ?></div></div>
        <div class="secure-dl__row">
            <div class="secure-dl__label">PSA expiry</div>
            <div class="secure-dl__value">
                <?= secure_h((string) (($staff['psa_expiry_date'] ?? '') ?: '—')) ?>
                <?php if (!empty($staff['psa_expiry_date'])): ?>
                    · <?= secure_psa_badge((string) $staff['psa_expiry_date']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="secure-dl__row"><div class="secure-dl__label">PPS / NI</div><div class="secure-dl__value"><?= secure_h((string) ($staff['national_insurance'] ?? '—')) ?></div></div>
        <div class="secure-dl__row"><div class="secure-dl__label">IBAN</div><div class="secure-dl__value"><?= secure_h((string) ($staff['bank_iban'] ?? '—')) ?></div></div>
    </div>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Profile status (automatic)</h2>
    <p style="margin:0 0 0.75rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        Status is set automatically when PSA, payroll fields, and contact details are complete with a valid licence expiry.
        No manual approval is required.
    </p>
    <div class="secure-dl">
        <div class="secure-dl__row">
            <div class="secure-dl__label">Status</div>
            <div class="secure-dl__value"><?= secure_status_badge($status) ?></div>
        </div>
        <?php if ($verifiedBy !== ''): ?>
            <div class="secure-dl__row">
                <div class="secure-dl__label">Verified</div>
                <div class="secure-dl__value"><?= secure_h($verifiedBy) ?><?= $verifiedAt ? ' · ' . secure_h((string) $verifiedAt) : '' ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php secure_layout_end(); ?>

