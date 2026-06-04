<?php

/**
 * PSA update panel on status.php (top of pending/approved view).
 *
 * Expects: $pdo, $token, $staff (staff row), $psaErrors (array), optional $psaFlash (string)
 */

require_once __DIR__ . '/staff-psa.php';

$psaMissing = getStaffPsaMissingFields($staff);
$psaComplete = $psaMissing === [];
$psaErrors = $psaErrors ?? [];
?>
<section class="status-psa-panel" aria-labelledby="status-psa-title">
    <?php if (!$psaComplete): ?>
        <div class="alert alert--error alert--visible status-psa-panel__alert">
            Please add your PSA licence details before your shifts can be approved or you can check in.
            <?php if ($psaMissing !== []): ?>
                Missing: <?= h(implode(', ', $psaMissing)) ?>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="status-psa-panel__intro">Update your PSA licence number, expiry date, or photos here if anything changes.</p>
    <?php endif; ?>

    <h2 id="status-psa-title" class="form-section-title">PSA licence</h2>

    <form method="post" action="status.php?token=<?= h(urlencode($token)) ?>" enctype="multipart/form-data" class="status-psa-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="status_psa_update" value="1">
        <input type="hidden" name="status_token" value="<?= h($token) ?>">

        <div class="form-grid form-grid--compact">
            <div class="form-group">
                <label class="form-label form-label--required" for="status_psa_licence">PSA licence number</label>
                <input class="form-input" type="text" id="status_psa_licence" name="psa_licence" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" placeholder="EM123456/00" autocapitalize="characters" pattern="EM[0-9]{6}/[0-9]{2}" required>
                <p class="form-hint">Format EM123456/00</p>
                <?php if (!empty($psaErrors['psa_licence'])): ?>
                    <span class="form-error form-error--visible"><?= h($psaErrors['psa_licence']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label form-label--required" for="status_psa_expiry">PSA expiry date</label>
                <input class="form-input" type="date" id="status_psa_expiry" name="psa_expiry_date" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>" required>
                <?php if (!empty($psaErrors['psa_expiry_date'])): ?>
                    <span class="form-error form-error--visible"><?= h($psaErrors['psa_expiry_date']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group form-group--full">
                <label class="form-label<?= empty($staff['psa_front_image']) ? ' form-label--required' : '' ?>" for="status_psa_front">PSA card — front</label>
                <input class="form-input form-input--file" type="file" id="status_psa_front" name="psa_front_image" accept="<?= h(psaImageFileAcceptAttribute()) ?>"<?= empty($staff['psa_front_image']) ? ' required' : '' ?>>
                <?php if (!empty($staff['psa_front_image'])): ?>
                    <p class="form-hint"><a href="<?= h((string) $staff['psa_front_image']) ?>" target="_blank" rel="noopener">View current front photo</a></p>
                <?php endif; ?>
                <?php if (!empty($psaErrors['psa_front_image'])): ?>
                    <span class="form-error form-error--visible"><?= h($psaErrors['psa_front_image']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group form-group--full">
                <label class="form-label<?= empty($staff['psa_back_image']) ? ' form-label--required' : '' ?>" for="status_psa_back">PSA card — back</label>
                <input class="form-input form-input--file" type="file" id="status_psa_back" name="psa_back_image" accept="<?= h(psaImageFileAcceptAttribute()) ?>"<?= empty($staff['psa_back_image']) ? ' required' : '' ?>>
                <?php if (!empty($staff['psa_back_image'])): ?>
                    <p class="form-hint"><a href="<?= h((string) $staff['psa_back_image']) ?>" target="_blank" rel="noopener">View current back photo</a></p>
                <?php endif; ?>
                <?php if (!empty($psaErrors['psa_back_image'])): ?>
                    <span class="form-error form-error--visible"><?= h($psaErrors['psa_back_image']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Save PSA details</button>
            </div>
        </div>
    </form>
</section>
