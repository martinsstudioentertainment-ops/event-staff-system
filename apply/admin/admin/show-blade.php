<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/psa-sync.php';
require_once __DIR__ . '/../includes/psa-images.php';

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
    die('Staff not found.');
}

$staff = apply_merge_vault_psa_images($staff, $eventPdo);
$psaFrontUrl = apply_psa_image_url((string) ($staff['psa_front_image'] ?? ''));
$psaBackUrl  = apply_psa_image_url((string) ($staff['psa_back_image'] ?? ''));

$status   = (string) ($staff['profile_status'] ?? 'Incomplete');
$fullName = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));

secure_layout_start('Compliance review', 'psa', $fullName . ' — PSA verification and profile audit.');
?>

<div class="secure-card" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;">
    <div><?= secure_status_badge($status) ?></div>
    <div class="secure-actions" style="margin:0;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));">
        <a href="edit-staff.php?id=<?= $id ?>" class="secure-btn secure-btn--success">Edit staff</a>
        <a href="psa-compliance.php" class="secure-btn secure-btn--ghost">PSA list</a>
        <a href="javascript:history.back();" class="secure-btn secure-btn--ghost">Back</a>
    </div>
</div>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Personal information</h2>
    <div class="secure-grid">
        <div class="secure-grid__col">
            <div class="secure-label">First name</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['first_name'] ?? '')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Surname</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['last_name'] ?? '')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Date of birth</div>
            <div class="secure-dl__value"><?= secure_h(secure_format_date((string) ($staff['date_of_birth'] ?? '')) ?: '—') ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Gender</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['gender'] ?? '—')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">PPS number</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['national_insurance'] ?? '—')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Nationality</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['nationality'] ?? '—')) ?></div>
        </div>
    </div>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Contact</h2>
    <div class="secure-grid">
        <div class="secure-grid__col">
            <div class="secure-label">Email</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['email'] ?? '')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Phone</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['phone'] ?? '')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Address</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['address'] ?? '')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">Postcode</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['postcode'] ?? '')) ?></div>
        </div>
    </div>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Payroll</h2>
    <div class="secure-grid">
        <div class="secure-grid__col">
            <div class="secure-label">IBAN</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['bank_iban'] ?? '—')) ?></div>
        </div>
    </div>
</div>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 1rem;font-size:1rem;">PSA compliance</h2>
    <div class="secure-grid">
        <div class="secure-grid__col">
            <div class="secure-label">PSA licence</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['psa_licence'] ?? '—')) ?></div>
        </div>
        <div class="secure-grid__col">
            <div class="secure-label">PSA expiry</div>
            <div class="secure-dl__value">
                <?= secure_h(secure_format_date((string) ($staff['psa_expiry_date'] ?? '')) ?: '—') ?>
                <?php if (!empty($staff['psa_expiry_date'])): ?>
                    · <?= secure_psa_badge((string) $staff['psa_expiry_date']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($psaFrontUrl !== '' || $psaBackUrl !== ''): ?>
        <div class="secure-grid" style="margin-top:1rem;">
            <?php if ($psaFrontUrl !== ''): ?>
                <div class="secure-grid__col">
                    <div class="secure-label">PSA front</div>
                    <a href="<?= secure_h($psaFrontUrl) ?>" target="_blank" rel="noopener">
                        <img src="<?= secure_h($psaFrontUrl) ?>" alt="PSA card front" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid var(--secure-border);">
                    </a>
                </div>
            <?php endif; ?>
            <?php if ($psaBackUrl !== ''): ?>
                <div class="secure-grid__col">
                    <div class="secure-label">PSA back</div>
                    <a href="<?= secure_h($psaBackUrl) ?>" target="_blank" rel="noopener">
                        <img src="<?= secure_h($psaBackUrl) ?>" alt="PSA card back" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid var(--secure-border);">
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p style="color:var(--secure-muted);font-size:0.875rem;margin:1rem 0 0;">No PSA images on file. Photos from registration are stored on the main ERP — run import/sync if staff registered recently.</p>
    <?php endif; ?>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 1rem;font-size:1rem;">Verification details</h2>
    <div class="secure-dl">
        <div class="secure-dl__row">
            <div class="secure-dl__label">Notes</div>
            <div class="secure-dl__value"><?= nl2br(secure_h((string) ($staff['verification_notes'] ?? '—'))) ?></div>
        </div>
        <div class="secure-dl__row">
            <div class="secure-dl__label">Verified by</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['verified_by'] ?? 'Not verified')) ?></div>
        </div>
        <div class="secure-dl__row">
            <div class="secure-dl__label">Verified at</div>
            <div class="secure-dl__value"><?= secure_h((string) ($staff['verified_at'] ?? 'Not verified')) ?></div>
        </div>
    </div>
</div>

<?php secure_layout_end(); ?>
